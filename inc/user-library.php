<?php
/**
 * Personal media library, favorites and dashboard data.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function photovault_get_favorites_table() {
	global $wpdb;

	return $wpdb->prefix . 'photovault_favorites';
}

function photovault_user_library_table_exists() {
	global $wpdb;

	$table = photovault_get_favorites_table();

	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
}

function photovault_install_user_library_schema() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table           = photovault_get_favorites_table();
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		user_id bigint(20) unsigned NOT NULL,
		media_id bigint(20) unsigned NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY user_media (user_id,media_id),
		KEY user_created (user_id,created_at),
		KEY media_id (media_id)
	) {$charset_collate};";

	dbDelta( $sql );
}

function photovault_user_can_read_library( $user_id ) {
	$user_id = absint( $user_id );

	return $user_id && ( get_current_user_id() === $user_id || photovault_current_user_can( 'photovault_manage_media' ) );
}

function photovault_get_user_favorite_ids( $user_id = 0, $limit = 100 ) {
	global $wpdb;

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$limit   = max( 1, min( 200, absint( $limit ) ) );
	if ( ! photovault_user_can_read_library( $user_id ) || ! photovault_user_library_table_exists() ) {
		return array();
	}
	$cache_key = 'user_' . $user_id;
	$cached    = wp_cache_get( $cache_key, 'photovault_favorites' );
	if ( is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	$media_ids = array_map(
		'absint',
		$wpdb->get_col(
			$wpdb->prepare(
				'SELECT media_id FROM ' . photovault_get_favorites_table() . ' WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d',
				$user_id,
				200
			)
		)
	);

	$media_ids = array_values(
		array_filter(
			$media_ids,
			static function ( $media_id ) use ( $user_id ) {
				return photovault_user_can_access_media( $media_id, $user_id );
			}
		)
	);
	wp_cache_set( $cache_key, $media_ids, 'photovault_favorites' );

	return array_slice( $media_ids, 0, $limit );
}

function photovault_is_media_favorite( $media_id, $user_id = 0 ) {
	global $wpdb;

	$user_id  = $user_id ? absint( $user_id ) : get_current_user_id();
	$media_id = absint( $media_id );
	if ( ! $media_id || ! photovault_user_can_read_library( $user_id ) || ! photovault_user_library_table_exists() ) {
		return false;
	}

	return in_array( $media_id, photovault_get_user_favorite_ids( $user_id, 200 ), true );
}

function photovault_add_user_favorite( $user_id, $media_id ) {
	global $wpdb;

	$user_id  = absint( $user_id );
	$media_id = absint( $media_id );
	$post     = get_post( $media_id );
	if ( ! $user_id || get_current_user_id() !== $user_id ) {
		return new WP_Error( 'photovault_favorite_forbidden', __( 'Vous ne pouvez modifier que vos favoris.', 'photovault' ) );
	}
	if ( ! $post || 'media_item' !== $post->post_type || ! photovault_user_can_access_media( $media_id, $user_id ) ) {
		return new WP_Error( 'photovault_favorite_unavailable', __( 'Ce media ne peut pas etre ajoute aux favoris.', 'photovault' ) );
	}
	if ( ! photovault_user_library_table_exists() ) {
		photovault_install_user_library_schema();
	}

	$inserted = $wpdb->query(
		$wpdb->prepare(
			'INSERT IGNORE INTO ' . photovault_get_favorites_table() . ' (user_id, media_id, created_at) VALUES (%d, %d, %s)',
			$user_id,
			$media_id,
			current_time( 'mysql', true )
		)
	);
	if ( false === $inserted ) {
		return new WP_Error( 'photovault_favorite_failed', __( 'Le favori n a pas pu etre enregistre.', 'photovault' ) );
	}
	if ( function_exists( 'photovault_log_media_event' ) ) {
		photovault_log_media_event( 'media_favorite_added', 'success', $media_id, array( 'user_id' => $user_id ) );
	}
	wp_cache_delete( 'user_' . $user_id, 'photovault_favorites' );

	return true;
}

function photovault_remove_user_favorite( $user_id, $media_id ) {
	global $wpdb;

	$user_id  = absint( $user_id );
	$media_id = absint( $media_id );
	if ( ! $user_id || get_current_user_id() !== $user_id ) {
		return new WP_Error( 'photovault_favorite_forbidden', __( 'Vous ne pouvez modifier que vos favoris.', 'photovault' ) );
	}
	if ( ! photovault_user_library_table_exists() ) {
		return true;
	}

	$deleted = $wpdb->delete( photovault_get_favorites_table(), array( 'user_id' => $user_id, 'media_id' => $media_id ), array( '%d', '%d' ) );
	if ( false === $deleted ) {
		return new WP_Error( 'photovault_favorite_failed', __( 'Le favori n a pas pu etre retire.', 'photovault' ) );
	}
	if ( function_exists( 'photovault_log_media_event' ) ) {
		photovault_log_media_event( 'media_favorite_removed', 'success', $media_id, array( 'user_id' => $user_id ) );
	}
	wp_cache_delete( 'user_' . $user_id, 'photovault_favorites' );

	return true;
}

function photovault_get_user_download_history( $user_id = 0, $limit = 30 ) {
	global $wpdb;

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$limit   = max( 1, min( 100, absint( $limit ) ) );
	if ( ! photovault_user_can_read_library( $user_id ) ) {
		return array();
	}

	$audit = photovault_get_media_audit_table();
	$posts = $wpdb->posts;
	$sql   = "SELECT a.id, a.media_id, a.created_at, p.post_title
		FROM {$audit} a
		INNER JOIN {$posts} p ON p.ID = a.media_id AND p.post_type = 'media_item'
		WHERE a.user_id = %d AND a.event = %s AND a.status = %s
		ORDER BY a.created_at DESC, a.id DESC LIMIT %d";

	return $wpdb->get_results( $wpdb->prepare( $sql, $user_id, 'media_download', 'success', $limit ), ARRAY_A );
}

function photovault_get_user_access_requests( $user_id = 0, $limit = 30 ) {
	global $wpdb;

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$limit   = max( 1, min( 100, absint( $limit ) ) );
	if ( ! photovault_user_can_read_library( $user_id ) ) {
		return array();
	}

	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, subject, collection, status, created_at, updated_at FROM ' . photovault_get_access_requests_table() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT %d',
			$user_id,
			$limit
		),
		ARRAY_A
	);
}

function photovault_get_user_access_grants( $user_id = 0, $limit = 30 ) {
	global $wpdb;

	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$limit   = max( 1, min( 100, absint( $limit ) ) );
	if ( ! photovault_user_can_read_library( $user_id ) ) {
		return array();
	}

	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT id, request_id, folder_id, status, created_at, updated_at FROM ' . photovault_get_access_grants_table() . ' WHERE user_id = %d ORDER BY updated_at DESC LIMIT %d',
			$user_id,
			$limit
		),
		ARRAY_A
	);
}

function photovault_rest_favorites_permission() {
	return is_user_logged_in();
}

function photovault_rest_get_favorites() {
	return rest_ensure_response( array( 'media_ids' => photovault_get_user_favorite_ids( get_current_user_id() ) ) );
}

function photovault_rest_update_favorite( $request ) {
	$media_id = absint( $request->get_param( 'id' ) );
	$result   = 'DELETE' === $request->get_method()
		? photovault_remove_user_favorite( get_current_user_id(), $media_id )
		: photovault_add_user_favorite( get_current_user_id(), $media_id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'media_id' => $media_id,
			'favorite' => 'DELETE' !== $request->get_method(),
		)
	);
}

function photovault_register_user_library_routes() {
	register_rest_route(
		'photovault/v1',
		'/favorites',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'photovault_rest_get_favorites',
			'permission_callback' => 'photovault_rest_favorites_permission',
		)
	);
	register_rest_route(
		'photovault/v1',
		'/favorites/(?P<id>\d+)',
		array(
			'methods'             => array( WP_REST_Server::CREATABLE, WP_REST_Server::DELETABLE ),
			'callback'            => 'photovault_rest_update_favorite',
			'permission_callback' => 'photovault_rest_favorites_permission',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'photovault_validate_positive_int',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'photovault_register_user_library_routes' );
