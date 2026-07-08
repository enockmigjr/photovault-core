<?php
/**
 * Access request workflow for protected PhotoVault collections.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function photovault_get_access_requests_table() {
	global $wpdb;

	return $wpdb->prefix . 'photovault_access_requests';
}

function photovault_install_access_request_schema() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table_name      = photovault_get_access_requests_table();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NULL,
		name varchar(120) NOT NULL,
		email varchar(190) NOT NULL,
		subject varchar(190) NOT NULL,
		collection varchar(190) NULL,
		message longtext NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'pending',
		ip_hash char(64) NULL,
		user_agent varchar(255) NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY status (status),
		KEY email (email),
		KEY user_id (user_id),
		KEY created_at (created_at)
	) {$charset_collate};";

	dbDelta( $sql );
}

function photovault_get_access_request_ip_hash() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : null;
}

function photovault_create_access_request( $data ) {
	global $wpdb;

	if ( function_exists( 'photovault_rate_limit' ) && ! photovault_rate_limit( 'access_request', 5, HOUR_IN_SECONDS ) ) {
		return new WP_Error( 'rate_limited', __( 'Veuillez patienter avant de soumettre une nouvelle demande.', 'photovault' ) );
	}

	if ( get_option( 'photovault_core_version' ) !== PHOTOVAULT_CORE_VERSION ) {
		photovault_install_access_request_schema();
		update_option( 'photovault_core_version', PHOTOVAULT_CORE_VERSION, false );
	}

	$name       = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
	$email      = isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '';
	$subject    = isset( $data['subject'] ) ? sanitize_text_field( $data['subject'] ) : '';
	$collection = isset( $data['collection'] ) ? sanitize_text_field( $data['collection'] ) : '';
	$message    = isset( $data['message'] ) ? sanitize_textarea_field( $data['message'] ) : '';

	if ( '' === $name || ! is_email( $email ) || '' === $message ) {
		return new WP_Error( 'invalid_request', __( 'Nom, e-mail et message sont obligatoires.', 'photovault' ) );
	}

	if ( '' === $subject ) {
		$subject = __( 'Demande d acces protege', 'photovault' );
	}

	$now        = gmdate( 'Y-m-d H:i:s' );
	$table_name = photovault_get_access_requests_table();
	$inserted   = $wpdb->insert(
		$table_name,
		array(
			'user_id'    => get_current_user_id() ?: 0,
			'name'       => $name,
			'email'      => $email,
			'subject'    => $subject,
			'collection' => $collection,
			'message'    => $message,
			'status'     => 'pending',
			'ip_hash'    => photovault_get_access_request_ip_hash(),
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : null,
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return new WP_Error( 'db_insert_failed', __( 'La demande n a pas pu etre enregistree.', 'photovault' ) );
	}

	return absint( $wpdb->insert_id );
}

function photovault_get_access_request_counts() {
	global $wpdb;

	$table_name = photovault_get_access_requests_table();
	$rows       = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table_name} GROUP BY status", ARRAY_A );
	$counts     = array(
		'pending'  => 0,
		'approved' => 0,
		'rejected' => 0,
	);

	foreach ( (array) $rows as $row ) {
		$status = sanitize_key( $row['status'] );
		if ( isset( $counts[ $status ] ) ) {
			$counts[ $status ] = absint( $row['total'] );
		}
	}

	return $counts;
}

function photovault_get_access_requests( $status = '', $limit = 30 ) {
	global $wpdb;

	$table_name = photovault_get_access_requests_table();
	$limit      = max( 1, min( 100, absint( $limit ) ) );
	$status     = sanitize_key( $status );

	if ( $status ) {
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table_name} WHERE status = %s ORDER BY created_at DESC LIMIT %d", $status, $limit ),
			ARRAY_A
		);
	}

	return $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d", $limit ),
		ARRAY_A
	);
}

function photovault_register_access_requests_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=media_item',
		__( 'Demandes d acces', 'photovault' ),
		__( 'Demandes d acces', 'photovault' ),
		'photovault_manage_media',
		'photovault-access-requests',
		'photovault_render_access_requests_page'
	);
}
add_action( 'admin_menu', 'photovault_register_access_requests_admin_menu' );

function photovault_render_access_requests_page() {
	if ( ! photovault_current_user_can( 'photovault_manage_media' ) ) {
		wp_die( esc_html__( 'Vous ne pouvez pas gerer les demandes PhotoVault.', 'photovault' ) );
	}

	$status = isset( $_GET['request_status'] ) ? sanitize_key( wp_unslash( $_GET['request_status'] ) ) : 'pending';
	if ( ! in_array( $status, array( 'pending', 'approved', 'rejected', '' ), true ) ) {
		$status = 'pending';
	}

	$counts   = photovault_get_access_request_counts();
	$requests = photovault_get_access_requests( $status, 50 );
	?>
	<div class="wrap photovault-access-admin">
		<h1><?php esc_html_e( 'Demandes d acces protege', 'photovault' ); ?></h1>
		<p><?php esc_html_e( 'Suivi manuel des visiteurs qui demandent a consulter une collection, une serie privee ou une archive confidentielle.', 'photovault' ); ?></p>

		<div class="pv-access-grid pv-access-grid-compact">
			<?php photovault_render_access_count_card( __( 'En attente', 'photovault' ), $counts['pending'], __( 'A traiter', 'photovault' ) ); ?>
			<?php photovault_render_access_count_card( __( 'Approuvees', 'photovault' ), $counts['approved'], __( 'Accord manuel', 'photovault' ) ); ?>
			<?php photovault_render_access_count_card( __( 'Refusees', 'photovault' ), $counts['rejected'], __( 'Non retenues', 'photovault' ) ); ?>
		</div>

		<p class="subsubsub">
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=media_item&page=photovault-access-requests&request_status=pending' ) ); ?>"><?php esc_html_e( 'En attente', 'photovault' ); ?></a> |
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=media_item&page=photovault-access-requests&request_status=approved' ) ); ?>"><?php esc_html_e( 'Approuvees', 'photovault' ); ?></a> |
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=media_item&page=photovault-access-requests&request_status=rejected' ) ); ?>"><?php esc_html_e( 'Refusees', 'photovault' ); ?></a> |
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=media_item&page=photovault-access-requests&request_status=' ) ); ?>"><?php esc_html_e( 'Toutes', 'photovault' ); ?></a>
		</p>

		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Demandeur', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Collection / sujet', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Message', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Statut', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Date', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Action', 'photovault' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $requests ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Aucune demande dans ce filtre.', 'photovault' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $requests as $request ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $request['name'] ); ?></strong><br><a href="mailto:<?php echo esc_attr( $request['email'] ); ?>"><?php echo esc_html( $request['email'] ); ?></a></td>
							<td><strong><?php echo esc_html( $request['collection'] ? $request['collection'] : $request['subject'] ); ?></strong><br><small><?php echo esc_html( $request['subject'] ); ?></small></td>
							<td><?php echo esc_html( wp_trim_words( $request['message'], 28 ) ); ?></td>
							<td><code><?php echo esc_html( $request['status'] ); ?></code></td>
							<td><?php echo esc_html( get_date_from_gmt( $request['created_at'], 'Y-m-d H:i' ) ); ?></td>
							<td>
								<form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;flex-wrap:wrap">
									<input type="hidden" name="action" value="photovault_update_access_request_status">
									<input type="hidden" name="request_id" value="<?php echo esc_attr( absint( $request['id'] ) ); ?>">
									<?php wp_nonce_field( 'photovault_update_access_request_status_' . absint( $request['id'] ) ); ?>
									<button class="button button-small" name="new_status" value="approved" type="submit"><?php esc_html_e( 'Approuver', 'photovault' ); ?></button>
									<button class="button button-small" name="new_status" value="rejected" type="submit"><?php esc_html_e( 'Refuser', 'photovault' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<style>.photovault-access-admin .pv-access-grid-compact{grid-template-columns:repeat(3,minmax(0,1fr));max-width:900px}</style>
	<?php
}

function photovault_handle_access_request_status_update() {
	global $wpdb;

	if ( ! photovault_current_user_can( 'photovault_manage_media' ) ) {
		wp_die( esc_html__( 'Action non autorisee.', 'photovault' ) );
	}

	$request_id = isset( $_POST['request_id'] ) ? absint( $_POST['request_id'] ) : 0;
	$new_status = isset( $_POST['new_status'] ) ? sanitize_key( wp_unslash( $_POST['new_status'] ) ) : '';

	if ( ! $request_id || ! in_array( $new_status, array( 'approved', 'rejected' ), true ) ) {
		wp_die( esc_html__( 'Demande invalide.', 'photovault' ) );
	}

	check_admin_referer( 'photovault_update_access_request_status_' . $request_id );

	$wpdb->update(
		photovault_get_access_requests_table(),
		array(
			'status'     => $new_status,
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		),
		array( 'id' => $request_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	wp_safe_redirect( admin_url( 'edit.php?post_type=media_item&page=photovault-access-requests&request_status=pending&updated=true' ) );
	exit;
}
add_action( 'admin_post_photovault_update_access_request_status', 'photovault_handle_access_request_status_update' );