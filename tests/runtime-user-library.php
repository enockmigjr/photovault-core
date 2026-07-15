<?php
/**
 * WordPress runtime verification for personal dashboard data.
 *
 * Run with: wp eval-file tests/runtime-user-library.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function photovault_user_library_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix           = strtolower( wp_generate_password( 8, false, false ) );
$previous_user_id = get_current_user_id();
$user_ids         = array();
$media_ids        = array();
$request_id       = 0;
$grant_id         = 0;
$folder_id        = 0;

try {
	photovault_core_activate();
	photovault_user_library_runtime_assert( photovault_user_library_table_exists(), 'Favorites migration did not create its table.' );

	foreach ( array( 'owner', 'other' ) as $label ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'pv-library-' . $label . '-' . $suffix,
				'user_email' => 'pv-library-' . $label . '-' . $suffix . '@photovault.test',
				'user_pass'  => wp_generate_password( 24, true, true ),
				'role'       => 'client',
			)
		);
		photovault_user_library_runtime_assert( ! is_wp_error( $user_id ), 'Runtime user creation failed.' );
		$user_ids[] = (int) $user_id;
	}

	$public_media_id = wp_insert_post(
		array(
			'post_type'   => 'media_item',
			'post_status' => 'publish',
			'post_title'  => 'Runtime public library ' . $suffix,
			'post_author' => $user_ids[0],
		)
	);
	$private_media_id = wp_insert_post(
		array(
			'post_type'   => 'media_item',
			'post_status' => 'private',
			'post_title'  => 'Runtime private library ' . $suffix,
			'post_author' => $user_ids[0],
		)
	);
	photovault_user_library_runtime_assert( ! is_wp_error( $public_media_id ) && ! is_wp_error( $private_media_id ), 'Runtime media creation failed.' );
	$media_ids = array( (int) $public_media_id, (int) $private_media_id );

	wp_set_current_user( $user_ids[0] );
	photovault_user_library_runtime_assert( true === photovault_add_user_favorite( $user_ids[0], $public_media_id ), 'Public favorite creation failed.' );
	photovault_user_library_runtime_assert( true === photovault_add_user_favorite( $user_ids[0], $public_media_id ), 'Favorite idempotence failed.' );
	photovault_user_library_runtime_assert( true === photovault_add_user_favorite( $user_ids[0], $private_media_id ), 'Owner private favorite creation failed.' );
	$favorite_ids = photovault_get_user_favorite_ids( $user_ids[0] );
	photovault_user_library_runtime_assert( 2 === count( $favorite_ids ) && photovault_is_media_favorite( $private_media_id, $user_ids[0] ), 'Owner favorites were not resolved.' );
	$stored_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . photovault_get_favorites_table() . ' WHERE user_id = %d AND media_id = %d', $user_ids[0], $public_media_id ) );
	photovault_user_library_runtime_assert( 1 === $stored_count, 'Duplicate favorite rows were persisted.' );

	photovault_log_media_event( 'media_download', 'success', $public_media_id, array( 'user_id' => $user_ids[0] ) );
	$history = photovault_get_user_download_history( $user_ids[0] );
	photovault_user_library_runtime_assert( 1 === count( $history ) && (int) $public_media_id === (int) $history[0]['media_id'], 'Personal download history was not resolved.' );

	$folder = wp_insert_term( 'Runtime private collection ' . $suffix, 'media_folder' );
	photovault_user_library_runtime_assert( ! is_wp_error( $folder ), 'Runtime collection creation failed.' );
	$folder_id = (int) $folder['term_id'];
	$request_id = photovault_create_access_request(
		array(
			'name'       => 'Runtime owner',
			'email'      => get_userdata( $user_ids[0] )->user_email,
			'subject'    => 'Runtime access',
			'collection' => 'Runtime private collection ' . $suffix,
			'message'    => 'Runtime dashboard access verification.',
		)
	);
	photovault_user_library_runtime_assert( ! is_wp_error( $request_id ), 'Runtime access request failed.' );
	$grant_id = photovault_create_access_grant_from_request( $request_id );
	photovault_user_library_runtime_assert( ! is_wp_error( $grant_id ), 'Runtime access grant failed.' );
	photovault_user_library_runtime_assert( 1 === count( photovault_get_user_access_requests( $user_ids[0] ) ) && 1 === count( photovault_get_user_access_grants( $user_ids[0] ) ), 'Personal access data was not resolved.' );

	wp_set_current_user( $user_ids[1] );
	photovault_user_library_runtime_assert( array() === photovault_get_user_favorite_ids( $user_ids[0] ), 'Another user could read the owner library.' );
	$cross_user = photovault_add_user_favorite( $user_ids[0], $public_media_id );
	photovault_user_library_runtime_assert( is_wp_error( $cross_user ) && 'photovault_favorite_forbidden' === $cross_user->get_error_code(), 'Another user could mutate the owner library.' );
	photovault_user_library_runtime_assert( true === photovault_add_user_favorite( $user_ids[1], $public_media_id ), 'Second user could not create an independent favorite.' );
	photovault_user_library_runtime_assert( array( (int) $public_media_id ) === photovault_get_user_favorite_ids( $user_ids[1] ), 'Second user favorites were not isolated.' );
	photovault_user_library_runtime_assert( array() === photovault_get_user_download_history( $user_ids[1] ), 'Download history leaked across users.' );
	photovault_user_library_runtime_assert( array() === photovault_get_user_access_requests( $user_ids[1] ), 'Access requests leaked across users.' );

	$request = new WP_REST_Request( 'DELETE', '/photovault/v1/favorites/' . $public_media_id );
	$request->set_param( 'id', $public_media_id );
	$response = photovault_rest_update_favorite( $request );
	photovault_user_library_runtime_assert( $response instanceof WP_REST_Response && false === $response->get_data()['favorite'], 'Authenticated REST favorite removal failed.' );

	wp_set_current_user( 0 );
	photovault_user_library_runtime_assert( false === photovault_rest_favorites_permission(), 'Anonymous REST favorite access was accepted.' );

	echo wp_json_encode(
		array(
			'migration'         => PHOTOVAULT_CORE_VERSION,
			'favorites'         => 'idempotent_and_user_isolated',
			'private_filtering' => true,
			'download_history'  => true,
			'access_dashboard'  => true,
			'rest_permission'   => true,
		)
	);
} finally {
	wp_set_current_user( $previous_user_id );
	if ( $grant_id ) {
		$wpdb->delete( photovault_get_access_grants_table(), array( 'id' => $grant_id ), array( '%d' ) );
	}
	if ( $request_id ) {
		$wpdb->delete( photovault_get_access_requests_table(), array( 'id' => $request_id ), array( '%d' ) );
	}
	if ( $user_ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . photovault_get_favorites_table() . " WHERE user_id IN ({$placeholders})", $user_ids ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . photovault_get_media_audit_table() . " WHERE user_id IN ({$placeholders})", $user_ids ) );
	}
	foreach ( $media_ids as $media_id ) {
		wp_delete_post( $media_id, true );
	}
	if ( $folder_id ) {
		wp_delete_term( $folder_id, 'media_folder' );
	}
	foreach ( $user_ids as $user_id ) {
		wp_delete_user( $user_id );
	}
}
