<?php
/**
 * WordPress runtime verification for the media authorization matrix.
 *
 * Run with: wp eval-file tests/runtime-media-authorization.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function photovault_media_authorization_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function photovault_media_authorization_list( $params = array() ) {
	$request = new WP_REST_Request( 'GET', '/photovault/v1/media' );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	$response = photovault_get_filtered_media( $request );
	photovault_media_authorization_assert( $response instanceof WP_REST_Response, 'Media list did not return a REST response.' );
	$data = $response->get_data();

	return array(
		'ids'   => array_map( 'intval', wp_list_pluck( $data['data'], 'id' ) ),
		'pages' => (int) $data['pages'],
	);
}

function photovault_media_authorization_error( $result, $code, $status ) {
	$data = is_wp_error( $result ) ? $result->get_error_data() : array();

	return is_wp_error( $result ) && $code === $result->get_error_code() && is_array( $data ) && $status === (int) ( $data['status'] ?? 0 );
}

global $wpdb;

$suffix           = strtolower( wp_generate_password( 8, false, false ) );
$previous_user_id = get_current_user_id();
$user_ids         = array();
$media_ids        = array();
$folder_ids       = array();
$attachment_id    = 0;
$attachment_path  = '';
$request_id       = 0;
$grant_id         = 0;

try {
	photovault_core_activate();
	photovault_register_post_types();
	photovault_register_taxonomies();
	photovault_media_authorization_assert( function_exists( 'identity_security_kit_is_email_verified' ), 'Identity Security Kit must be active for the verified/unverified matrix.' );

	foreach ( array( 'owner', 'unverified', 'verified', 'granted', 'manager' ) as $label ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'pv-auth-' . $label . '-' . $suffix,
				'user_email' => 'pv-auth-' . $label . '-' . $suffix . '@photovault.test',
				'user_pass'  => wp_generate_password( 24, true, true ),
				'role'       => 'client',
			)
		);
		photovault_media_authorization_assert( ! is_wp_error( $user_id ), 'Runtime authorization user creation failed.' );
		$user_ids[ $label ] = (int) $user_id;
	}

	foreach ( array( 'owner', 'verified', 'granted' ) as $label ) {
		update_user_meta( $user_ids[ $label ], identity_security_kit_email_verified_meta_key(), '1' );
	}
	delete_user_meta( $user_ids['unverified'], identity_security_kit_email_verified_meta_key() );
	$manager = get_userdata( $user_ids['manager'] );
	$manager->add_cap( 'upload_files' );
	$manager->add_cap( 'photovault_manage_media' );
	$manager->add_cap( 'photovault_view_private_media' );

	foreach ( array( 'A', 'B' ) as $label ) {
		$folder = wp_insert_term( 'Runtime authorization ' . $label . ' ' . $suffix, 'media_folder' );
		photovault_media_authorization_assert( ! is_wp_error( $folder ), 'Runtime authorization folder creation failed.' );
		$folder_ids[ $label ] = (int) $folder['term_id'];
	}

	wp_set_current_user( $user_ids['manager'] );
	$upload = wp_upload_bits(
		'pv-authorization-' . $suffix . '.png',
		null,
		base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII=' )
	);
	photovault_media_authorization_assert( empty( $upload['error'] ) && file_exists( $upload['file'] ), 'Runtime image fixture could not be created.' );
	$attachment_path = $upload['file'];
	$attachment_id   = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => 'Runtime authorization fixture',
			'post_status'    => 'inherit',
		),
		$attachment_path
	);
	photovault_media_authorization_assert( is_int( $attachment_id ) && $attachment_id > 0, 'Runtime attachment creation failed.' );

	$definitions = array(
		'public'           => array( 'publish', false, 0 ),
		'public_protected' => array( 'publish', true, 0 ),
		'private_a'        => array( 'private', true, $folder_ids['A'] ),
		'private_b'        => array( 'private', true, $folder_ids['B'] ),
	);
	foreach ( $definitions as $key => $definition ) {
		$media_id = wp_insert_post(
			array(
				'post_type'   => 'media_item',
				'post_status' => $definition[0],
				'post_title'  => 'Runtime authorization ' . $key . ' ' . $suffix,
				'post_author' => $user_ids['owner'],
			),
			true
		);
		photovault_media_authorization_assert( ! is_wp_error( $media_id ), 'Runtime media creation failed.' );
		$media_ids[ $key ] = (int) $media_id;
		set_post_thumbnail( $media_id, $attachment_id );
		update_post_meta( $media_id, 'is_protected', $definition[1] ? '1' : '0' );
		if ( $definition[2] ) {
			wp_set_post_terms( $media_id, array( $definition[2] ), 'media_folder', false );
		}
	}

	wp_set_current_user( $user_ids['granted'] );
	$request_id = photovault_create_access_request(
		array(
			'name'       => 'Runtime granted user',
			'email'      => get_userdata( $user_ids['granted'] )->user_email,
			'subject'    => 'Runtime authorization grant',
			'collection' => 'Runtime authorization A ' . $suffix,
			'message'    => 'Verify collection-scoped access.',
		)
	);
	photovault_media_authorization_assert( ! is_wp_error( $request_id ), 'Runtime access request creation failed.' );
	wp_set_current_user( $user_ids['manager'] );
	$grant_id = photovault_create_access_grant_from_request( $request_id );
	photovault_media_authorization_assert( ! is_wp_error( $grant_id ), 'Runtime access grant creation failed.' );

	wp_set_current_user( 0 );
	$anonymous = rest_do_request( new WP_REST_Request( 'GET', '/photovault/v1/media' ) );
	photovault_media_authorization_assert( in_array( $anonymous->get_status(), array( 401, 403 ), true ), 'Anonymous media listing was accepted.' );
	$private_request = new WP_REST_Request( 'GET', '/photovault/v1/secure-image' );
	$private_request->set_param( 'id', $media_ids['private_a'] );
	$private_request->set_param( 'display', 'card' );
	$private_denied = photovault_serve_secure_image( $private_request );
	photovault_media_authorization_assert( photovault_media_authorization_error( $private_denied, 'not_found', 404 ), 'Anonymous private ID guessing disclosed the media.' );

	foreach ( array( 'unverified', 'verified' ) as $label ) {
		wp_set_current_user( $user_ids[ $label ] );
		$list = photovault_media_authorization_list();
		photovault_media_authorization_assert( in_array( $media_ids['public'], $list['ids'], true ) && in_array( $media_ids['public_protected'], $list['ids'], true ), ucfirst( $label ) . ' user could not list public media.' );
		photovault_media_authorization_assert( ! in_array( $media_ids['private_a'], $list['ids'], true ) && ! in_array( $media_ids['private_b'], $list['ids'], true ), ucfirst( $label ) . ' user listed private media without a grant.' );
		$folder_list = photovault_media_authorization_list( array( 'folder' => $folder_ids['A'] ) );
		photovault_media_authorization_assert( array() === $folder_list['ids'] && 0 === $folder_list['pages'], 'Private collection volume leaked through pagination.' );
	}

	wp_set_current_user( $user_ids['owner'] );
	$owner_list = photovault_media_authorization_list();
	photovault_media_authorization_assert( in_array( $media_ids['private_a'], $owner_list['ids'], true ) && in_array( $media_ids['private_b'], $owner_list['ids'], true ), 'Owner could not list owned private media.' );
	photovault_media_authorization_assert( photovault_user_can_edit_media_item( $media_ids['private_a'], $user_ids['owner'] ), 'Owner could not edit owned media.' );

	wp_set_current_user( $user_ids['granted'] );
	$granted_list = photovault_media_authorization_list();
	photovault_media_authorization_assert( in_array( $media_ids['private_a'], $granted_list['ids'], true ) && ! in_array( $media_ids['private_b'], $granted_list['ids'], true ), 'Collection grant was not scoped to one folder.' );
	photovault_media_authorization_assert( true === photovault_add_user_favorite( $user_ids['granted'], $media_ids['private_a'] ), 'Granted user could not favorite an accessible private media.' );
	$forbidden_favorite = photovault_add_user_favorite( $user_ids['granted'], $media_ids['private_b'] );
	photovault_media_authorization_assert( is_wp_error( $forbidden_favorite ) && 'photovault_favorite_unavailable' === $forbidden_favorite->get_error_code(), 'Granted user favorited media outside the granted collection.' );

	wp_set_current_user( $user_ids['unverified'] );
	$download = new WP_REST_Request( 'GET', '/photovault/v1/secure-image' );
	$download->set_param( 'id', $media_ids['public'] );
	$download->set_param( 'download', '1' );
	$download->set_param( '_wpnonce', wp_create_nonce( 'wp_rest' ) );
	$unverified_download = photovault_serve_secure_image( $download );
	photovault_media_authorization_assert( photovault_media_authorization_error( $unverified_download, 'email_unverified', 403 ), 'Unverified user could download an original.' );

	wp_set_current_user( $user_ids['verified'] );
	$download->set_param( '_wpnonce', 'invalid' );
	$invalid_nonce = photovault_serve_secure_image( $download );
	photovault_media_authorization_assert( photovault_media_authorization_error( $invalid_nonce, 'forbidden', 403 ), 'Invalid download nonce was accepted.' );
	$protected_download = new WP_REST_Request( 'GET', '/photovault/v1/secure-image' );
	$protected_download->set_param( 'id', $media_ids['public_protected'] );
	$protected_download->set_param( 'download', '1' );
	$protected_download->set_param( '_wpnonce', wp_create_nonce( 'wp_rest' ) );
	$protected_denied = photovault_serve_secure_image( $protected_download );
	photovault_media_authorization_assert( photovault_media_authorization_error( $protected_denied, 'forbidden', 403 ), 'Verified non-owner downloaded protected media.' );

	foreach ( array( 'manager', 'administrator' ) as $label ) {
		if ( 'administrator' === $label ) {
			$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
			photovault_media_authorization_assert( ! empty( $administrators ), 'Administrator required for authorization matrix.' );
			wp_set_current_user( (int) $administrators[0] );
		} else {
			wp_set_current_user( $user_ids['manager'] );
		}
		$privileged_list = photovault_media_authorization_list();
		photovault_media_authorization_assert( 4 === count( array_intersect( array_values( $media_ids ), $privileged_list['ids'] ) ), ucfirst( $label ) . ' could not list all media.' );
		photovault_media_authorization_assert( photovault_user_can_edit_media_item( $media_ids['private_b'] ), ucfirst( $label ) . ' could not manage private media.' );
		photovault_media_authorization_assert( photovault_rest_upload_media_permission(), ucfirst( $label ) . ' could not access media import.' );
	}

	echo wp_json_encode(
		array(
			'anonymous_listing'       => 'denied',
			'private_id_guessing'     => 'not_found',
			'pagination_leak'         => 'closed',
			'unverified_download'     => 'denied',
			'invalid_download_nonce'  => 'denied',
			'collection_grant_scope'  => 'isolated',
			'owner_manager_admin'     => 'authorized',
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
		$ids          = array_values( $user_ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . photovault_get_favorites_table() . " WHERE user_id IN ({$placeholders})", $ids ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . photovault_get_media_audit_table() . " WHERE user_id IN ({$placeholders})", $ids ) );
		foreach ( $ids as $user_id ) {
			delete_transient( 'pv_rl_media_filter_' . md5( 'u' . $user_id ) );
			delete_transient( 'pv_rl_secure_image_' . md5( 'u' . $user_id ) );
			delete_transient( 'pv_rl_access_request_' . md5( 'u' . $user_id ) );
		}
	}
	foreach ( $media_ids as $media_id ) {
		wp_delete_post( $media_id, true );
	}
	if ( $attachment_id ) {
		wp_delete_attachment( $attachment_id, true );
	} elseif ( $attachment_path && file_exists( $attachment_path ) ) {
		wp_delete_file( $attachment_path );
	}
	foreach ( $folder_ids as $folder_id ) {
		wp_delete_term( $folder_id, 'media_folder' );
	}
	foreach ( $user_ids as $user_id ) {
		wp_delete_user( $user_id );
	}
}
