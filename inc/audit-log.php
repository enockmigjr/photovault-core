<?php
/**
 * Media audit trail for PhotoVault Core.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function photovault_get_media_audit_table() {
	global $wpdb;

	return $wpdb->prefix . 'photovault_media_audit';
}

function photovault_install_media_audit_schema() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = photovault_get_media_audit_table();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		event varchar(80) NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'info',
		media_id bigint(20) unsigned NULL,
		user_id bigint(20) unsigned NULL,
		actor_user_id bigint(20) unsigned NULL,
		request_id bigint(20) unsigned NULL,
		grant_id bigint(20) unsigned NULL,
		ip_hash char(64) NULL,
		user_agent varchar(255) NULL,
		context longtext NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY event (event),
		KEY status (status),
		KEY media_id (media_id),
		KEY user_id (user_id),
		KEY request_id (request_id),
		KEY grant_id (grant_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $sql );
}

function photovault_get_request_ip_hash() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : null;
}

function photovault_get_request_user_agent() {
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	return $user_agent ? substr( $user_agent, 0, 255 ) : null;
}

function photovault_sanitize_audit_context( $context ) {
	if ( ! is_array( $context ) ) {
		return array();
	}

	$blocked_keys = array( 'email', 'token', 'nonce', 'password', 'secret', 'key', 'ip' );
	$clean        = array();

	foreach ( $context as $key => $value ) {
		$key = sanitize_key( $key );
		if ( '' === $key || in_array( $key, $blocked_keys, true ) ) {
			continue;
		}

		if ( is_array( $value ) ) {
			$clean[ $key ] = photovault_sanitize_audit_context( $value );
		} elseif ( is_bool( $value ) ) {
			$clean[ $key ] = $value;
		} elseif ( is_numeric( $value ) ) {
			$clean[ $key ] = 0 + $value;
		} else {
			$clean[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 190 );
		}
	}

	return $clean;
}

function photovault_log_media_event( $event, $status = 'info', $media_id = 0, $context = array() ) {
	global $wpdb;

	$event = sanitize_key( $event );
	if ( '' === $event ) {
		return false;
	}

	$status = sanitize_key( $status );
	if ( ! in_array( $status, array( 'info', 'success', 'warning', 'error' ), true ) ) {
		$status = 'info';
	}

	if ( get_option( 'photovault_core_version' ) !== PHOTOVAULT_CORE_VERSION && function_exists( 'photovault_install_media_audit_schema' ) ) {
		photovault_install_media_audit_schema();
	}

	$context       = photovault_sanitize_audit_context( $context );
	$request_id    = isset( $context['request_id'] ) ? absint( $context['request_id'] ) : null;
	$grant_id      = isset( $context['grant_id'] ) ? absint( $context['grant_id'] ) : null;
	$user_id       = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
	$actor_user_id = get_current_user_id();

	unset( $context['request_id'], $context['grant_id'], $context['user_id'] );

	$inserted = $wpdb->insert(
		photovault_get_media_audit_table(),
		array(
			'event'         => $event,
			'status'        => $status,
			'media_id'      => $media_id ? absint( $media_id ) : null,
			'user_id'       => $user_id ? absint( $user_id ) : null,
			'actor_user_id' => $actor_user_id ? absint( $actor_user_id ) : null,
			'request_id'    => $request_id ?: null,
			'grant_id'      => $grant_id ?: null,
			'ip_hash'       => photovault_get_request_ip_hash(),
			'user_agent'    => photovault_get_request_user_agent(),
			'context'       => empty( $context ) ? null : wp_json_encode( $context ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		),
		array( '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
	);

	return false !== $inserted;
}

function photovault_get_media_audit_events( $event = '', $status = '', $limit = 80, $offset = 0 ) {
	global $wpdb;

	$table_name = photovault_get_media_audit_table();
	$where      = array();
	$params     = array();
	$event      = sanitize_key( $event );
	$status     = sanitize_key( $status );
	$limit      = max( 1, min( 200, absint( $limit ) ) );
	$offset     = absint( $offset );

	if ( $event ) {
		$where[]  = 'event = %s';
		$params[] = $event;
	}

	if ( $status ) {
		$where[]  = 'status = %s';
		$params[] = $status;
	}

	$sql = "SELECT * FROM {$table_name}";
	if ( $where ) {
		$sql .= ' WHERE ' . implode( ' AND ', $where );
	}
	$sql     .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
	$params[] = $limit;
	$params[] = $offset;

	return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
}

function photovault_count_media_audit_events( $event = '', $status = '' ) {
	global $wpdb;
	$table  = photovault_get_media_audit_table();
	$where  = array();
	$params = array();
	if ( sanitize_key( $event ) ) {
		$where[]  = 'event = %s';
		$params[] = sanitize_key( $event );
	}
	if ( sanitize_key( $status ) ) {
		$where[]  = 'status = %s';
		$params[] = sanitize_key( $status );
	}
	$sql = "SELECT COUNT(*) FROM {$table}" . ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' );

	return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
}

function photovault_register_media_audit_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=media_item',
		__( 'Audit media', 'photovault' ),
		__( 'Audit media', 'photovault' ),
		'photovault_manage_media',
		'photovault-media-audit',
		'photovault_render_media_audit_page'
	);
}
add_action( 'admin_menu', 'photovault_register_media_audit_admin_menu' );

function photovault_render_media_audit_page() {
	if ( ! photovault_current_user_can( 'photovault_manage_media' ) ) {
		wp_die( esc_html__( 'Vous ne pouvez pas consulter l audit PhotoVault.', 'photovault' ) );
	}

	$event        = isset( $_GET['audit_event'] ) ? sanitize_key( wp_unslash( $_GET['audit_event'] ) ) : '';
	$status       = isset( $_GET['audit_status'] ) ? sanitize_key( wp_unslash( $_GET['audit_status'] ) ) : '';
	$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page     = 30;
	$total        = photovault_count_media_audit_events( $event, $status );
	$rows         = photovault_get_media_audit_events( $event, $status, $per_page, ( $current_page - 1 ) * $per_page );
	?>
	<div class="wrap photovault-audit-admin pv-admin">
		<h1><?php esc_html_e( 'Audit media PhotoVault', 'photovault' ); ?></h1>
		<p><?php esc_html_e( 'Historique des apercus, telechargements, refus et grants sensibles. Les IP sont hachees et les secrets ne sont pas conserves.', 'photovault' ); ?></p>

		<form method="GET" class="pv-admin-filters">
			<input type="hidden" name="post_type" value="media_item">
			<input type="hidden" name="page" value="photovault-media-audit">
			<label><?php esc_html_e( 'Evenement', 'photovault' ); ?><input type="text" name="audit_event" value="<?php echo esc_attr( $event ); ?>" placeholder="media_download" class="regular-text"></label>
			<label><?php esc_html_e( 'Statut', 'photovault' ); ?>
			<select name="audit_status">
				<option value=""><?php esc_html_e( 'Tous les statuts', 'photovault' ); ?></option>
				<?php foreach ( array( 'info', 'success', 'warning', 'error' ) as $status_key ) : ?>
					<option value="<?php echo esc_attr( $status_key ); ?>" <?php selected( $status, $status_key ); ?>><?php echo esc_html( $status_key ); ?></option>
				<?php endforeach; ?>
			</select>
			</label>
			<button class="button" type="submit"><?php esc_html_e( 'Filtrer', 'photovault' ); ?></button>
		</form>

		<div class="pv-table-wrap"><table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Evenement', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Media', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Utilisateur', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Contexte', 'photovault' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Aucun evenement dans ce filtre.', 'photovault' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], 'Y-m-d H:i' ) ); ?></td>
							<td><code><?php echo esc_html( $row['event'] ); ?></code></td>
							<td><code><?php echo esc_html( $row['status'] ); ?></code></td>
							<td>
								<?php if ( ! empty( $row['media_id'] ) ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( absint( $row['media_id'] ) ) ); ?>"><?php echo esc_html( get_the_title( absint( $row['media_id'] ) ) ?: '#' . absint( $row['media_id'] ) ); ?></a>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
							<td><?php echo ! empty( $row['user_id'] ) ? esc_html( '#' . absint( $row['user_id'] ) ) : esc_html__( 'Invite', 'photovault' ); ?></td>
							<td><?php if ( ! empty( $row['context'] ) ) : ?><details><summary><?php esc_html_e( 'Voir les details', 'photovault' ); ?></summary><pre><?php echo esc_html( wp_json_encode( json_decode( $row['context'], true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre></details><?php else : ?>&mdash;<?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table></div>
		<?php photovault_render_admin_pagination( $total, $per_page, $current_page, add_query_arg( array( 'post_type' => 'media_item', 'page' => 'photovault-media-audit', 'audit_event' => $event, 'audit_status' => $status ), admin_url( 'edit.php' ) ) ); ?>
	</div>
	<?php
}
