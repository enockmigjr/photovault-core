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

function photovault_get_access_grants_table() {
	global $wpdb;

	return $wpdb->prefix . 'photovault_access_grants';
}

function photovault_install_access_request_schema() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$requests_table  = photovault_get_access_requests_table();
	$grants_table    = photovault_get_access_grants_table();
	$charset_collate = $wpdb->get_charset_collate();
	$requests_sql    = "CREATE TABLE {$requests_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
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

	$grants_sql = "CREATE TABLE {$grants_table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		request_id bigint(20) unsigned NULL,
		user_id bigint(20) unsigned NOT NULL DEFAULT 0,
		email_hash char(64) NOT NULL,
		folder_id bigint(20) unsigned NOT NULL,
		status varchar(24) NOT NULL DEFAULT 'active',
		created_by bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY request_id (request_id),
		KEY user_id (user_id),
		KEY email_hash (email_hash),
		KEY folder_id (folder_id),
		KEY status (status)
	) {$charset_collate};";

	dbDelta( $requests_sql );
	dbDelta( $grants_sql );
}

function photovault_hash_access_email( $email ) {
	return hash_hmac( 'sha256', strtolower( trim( sanitize_email( $email ) ) ), wp_salt( 'auth' ) );
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

	$request_id = absint( $wpdb->insert_id );
	if ( function_exists( 'photovault_log_media_event' ) ) {
		photovault_log_media_event( 'access_request_created', 'info', 0, array( 'request_id' => $request_id, 'has_collection' => '' !== $collection, 'user_id' => get_current_user_id() ?: 0 ) );
	}

	return $request_id;
}

function photovault_find_access_folder_from_request( $request ) {
	$collection = isset( $request['collection'] ) ? trim( (string) $request['collection'] ) : '';
	if ( '' === $collection ) {
		return null;
	}

	$term = get_term_by( 'name', $collection, 'media_folder' );
	if ( ! $term ) {
		$term = get_term_by( 'slug', sanitize_title( $collection ), 'media_folder' );
	}

	return $term && ! is_wp_error( $term ) ? $term : null;
}

function photovault_create_access_grant_from_request( $request_id ) {
	global $wpdb;

	$request_id = absint( $request_id );
	$request    = $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM " . photovault_get_access_requests_table() . " WHERE id = %d LIMIT 1", $request_id ),
		ARRAY_A
	);

	if ( ! $request ) {
		if ( function_exists( 'photovault_log_media_event' ) ) {
			photovault_log_media_event( 'access_grant_failed', 'warning', 0, array( 'request_id' => $request_id, 'reason' => 'request_not_found' ) );
		}

		return new WP_Error( 'not_found', __( 'Demande introuvable.', 'photovault' ) );
	}

	$folder = photovault_find_access_folder_from_request( $request );
	if ( ! $folder ) {
		if ( function_exists( 'photovault_log_media_event' ) ) {
			photovault_log_media_event( 'access_grant_failed', 'warning', 0, array( 'request_id' => $request_id, 'reason' => 'folder_not_found' ) );
		}

		return new WP_Error( 'folder_not_found', __( 'Aucun dossier media_folder ne correspond a cette collection.', 'photovault' ) );
	}

	$email_hash = photovault_hash_access_email( $request['email'] );
	$user_id    = absint( $request['user_id'] );
	$user       = get_user_by( 'email', $request['email'] );
	if ( $user ) {
		$user_id = absint( $user->ID );
	}

	$now          = gmdate( 'Y-m-d H:i:s' );
	$grants_table = photovault_get_access_grants_table();
	$existing_id  = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$grants_table} WHERE email_hash = %s AND folder_id = %d LIMIT 1",
			$email_hash,
			absint( $folder->term_id )
		)
	);

	if ( $existing_id ) {
		$wpdb->update(
			$grants_table,
			array(
				'request_id'  => $request_id,
				'user_id'     => $user_id,
				'status'      => 'active',
				'updated_at'  => $now,
			),
			array( 'id' => absint( $existing_id ) ),
			array( '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( function_exists( 'photovault_log_media_event' ) ) {
			photovault_log_media_event( 'access_grant_created', 'success', 0, array( 'request_id' => $request_id, 'grant_id' => absint( $existing_id ), 'folder_id' => absint( $folder->term_id ), 'updated_existing' => true, 'user_id' => $user_id ) );
		}

		return absint( $existing_id );
	}

	$inserted = $wpdb->insert(
		$grants_table,
		array(
			'request_id' => $request_id,
			'user_id'    => $user_id,
			'email_hash' => $email_hash,
			'folder_id'  => absint( $folder->term_id ),
			'status'     => 'active',
			'created_by' => get_current_user_id(),
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s' )
	);

	if ( false === $inserted ) {
		if ( function_exists( 'photovault_log_media_event' ) ) {
			photovault_log_media_event( 'access_grant_failed', 'error', 0, array( 'request_id' => $request_id, 'reason' => 'db_insert_failed', 'folder_id' => absint( $folder->term_id ) ) );
		}

		return new WP_Error( 'grant_failed', __( 'Acces approuve mais grant non cree.', 'photovault' ) );
	}

	$grant_id = absint( $wpdb->insert_id );
	if ( function_exists( 'photovault_log_media_event' ) ) {
		photovault_log_media_event( 'access_grant_created', 'success', 0, array( 'request_id' => $request_id, 'grant_id' => $grant_id, 'folder_id' => absint( $folder->term_id ), 'updated_existing' => false, 'user_id' => $user_id ) );
	}

	return $grant_id;
}

function photovault_user_can_access_media( $media_id, $user_id = 0 ) {
	global $wpdb;

	$media_id = absint( $media_id );
	$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
	$post     = get_post( $media_id );

	if ( ! $post || 'media_item' !== $post->post_type ) {
		return false;
	}

	if ( photovault_user_can( $user_id, 'photovault_manage_media' ) ) {
		return true;
	}

	if ( $user_id && (int) $post->post_author === $user_id ) {
		return true;
	}

	if ( 'private' !== $post->post_status ) {
		return true;
	}

	if ( ! $user_id || ! photovault_user_has_verified_identity( $user_id ) ) {
		return false;
	}

	$user = get_userdata( $user_id );
	if ( ! $user || ! is_email( $user->user_email ) ) {
		return false;
	}

	$folders = wp_get_object_terms( $media_id, 'media_folder', array( 'fields' => 'ids' ) );
	$folders = is_wp_error( $folders ) ? array() : array_map( 'absint', $folders );
	if ( empty( $folders ) ) {
		return false;
	}

	$placeholders = implode( ',', array_fill( 0, count( $folders ), '%d' ) );
	$params       = array_merge(
		array( photovault_hash_access_email( $user->user_email ), $user_id, 'active' ),
		$folders
	);
	$sql          = "SELECT id FROM " . photovault_get_access_grants_table() . " WHERE email_hash = %s AND (user_id = 0 OR user_id = %d) AND status = %s AND folder_id IN ({$placeholders}) LIMIT 1";

	return (bool) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
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

	$updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
	$counts   = photovault_get_access_request_counts();
	$requests = photovault_get_access_requests( $status, 50 );
	?>
	<div class="wrap photovault-access-admin">
		<h1><?php esc_html_e( 'Demandes d acces protege', 'photovault' ); ?></h1>
		<p><?php esc_html_e( 'Suivi manuel des visiteurs qui demandent a consulter une collection, une serie privee ou une archive confidentielle.', 'photovault' ); ?></p>
		<?php if ( $updated ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo 'grant_missing_folder' === $updated ? esc_html__( 'Statut mis a jour, mais aucun dossier media_folder correspondant n a ete trouve pour creer le grant.', 'photovault' ) : esc_html__( 'Demande mise a jour.', 'photovault' ); ?></p></div>
		<?php endif; ?>

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
					<th><?php esc_html_e( 'Grant', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Date', 'photovault' ); ?></th>
					<th><?php esc_html_e( 'Action', 'photovault' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $requests ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Aucune demande dans ce filtre.', 'photovault' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $requests as $request ) : ?>
						<?php $folder = photovault_find_access_folder_from_request( $request ); ?>
						<tr>
							<td><strong><?php echo esc_html( $request['name'] ); ?></strong><br><a href="mailto:<?php echo esc_attr( $request['email'] ); ?>"><?php echo esc_html( $request['email'] ); ?></a></td>
							<td><strong><?php echo esc_html( $request['collection'] ? $request['collection'] : $request['subject'] ); ?></strong><br><small><?php echo esc_html( $request['subject'] ); ?></small></td>
							<td><?php echo esc_html( wp_trim_words( $request['message'], 28 ) ); ?></td>
							<td><code><?php echo esc_html( $request['status'] ); ?></code></td>
							<td><?php echo $folder ? esc_html( $folder->name ) : esc_html__( 'Dossier introuvable', 'photovault' ); ?></td>
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

	$updated = 'true';
	if ( 'approved' === $new_status ) {
		$grant = photovault_create_access_grant_from_request( $request_id );
		if ( is_wp_error( $grant ) ) {
			$updated = 'folder_not_found' === $grant->get_error_code() ? 'grant_missing_folder' : 'grant_failed';
		}
	}

	if ( function_exists( 'photovault_log_media_event' ) ) {
		photovault_log_media_event( 'access_request_status_updated', 'info', 0, array( 'request_id' => $request_id, 'new_status' => $new_status, 'grant_result' => $updated ) );
	}

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

	wp_safe_redirect( admin_url( 'edit.php?post_type=media_item&page=photovault-access-requests&request_status=pending&updated=' . $updated ) );
	exit;
}
add_action( 'admin_post_photovault_update_access_request_status', 'photovault_handle_access_request_status_update' );