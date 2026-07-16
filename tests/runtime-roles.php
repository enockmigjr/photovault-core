<?php
/** WordPress runtime verification for frontend endpoints and native editor access. */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/user.php';

function photovault_roles_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$suffix    = strtolower( wp_generate_password( 8, false, false ) );
$client_id = wp_insert_user( array( 'user_login' => 'pv_role_client_' . $suffix, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'pv-role-client-' . $suffix . '@photovault.test', 'role' => get_role( 'client' ) ? 'client' : 'subscriber' ) );
$editor_id = wp_insert_user( array( 'user_login' => 'pv_role_editor_' . $suffix, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'pv-role-editor-' . $suffix . '@photovault.test', 'role' => 'editor' ) );
$original_pagenow = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : '';

try {
	photovault_roles_runtime_assert( ! is_wp_error( $client_id ) && ! is_wp_error( $editor_id ), 'Runtime users could not be created.' );

	wp_set_current_user( $client_id );
	photovault_roles_runtime_assert( ! photovault_current_user_can_access_admin(), 'A client unexpectedly received wp-admin UI access.' );

	global $pagenow;
	$pagenow          = 'admin-post.php';
	photovault_roles_runtime_assert( photovault_is_frontend_admin_endpoint(), 'admin-post.php was not recognized as a secured frontend endpoint.' );
	$pagenow = 'admin-ajax.php';
	photovault_roles_runtime_assert( photovault_is_frontend_admin_endpoint(), 'admin-ajax.php was not recognized as a secured frontend endpoint.' );
	$pagenow = 'edit.php';
	photovault_roles_runtime_assert( ! photovault_is_frontend_admin_endpoint(), 'A native admin screen was treated as a frontend endpoint.' );

	wp_set_current_user( $editor_id );
	photovault_roles_runtime_assert( current_user_can( 'edit_posts' ), 'The runtime editor lost its native capability.' );
	photovault_roles_runtime_assert( photovault_current_user_can_access_admin(), 'An editor was denied native wp-admin access.' );

	$pagenow = $original_pagenow;
	echo wp_json_encode( array( 'client_admin_ui' => 'denied', 'frontend_endpoints' => 'allowed', 'editor_admin_ui' => 'allowed' ) );
} finally {
	wp_set_current_user( 0 );
	$GLOBALS['pagenow'] = $original_pagenow;
	if ( ! is_wp_error( $client_id ) ) {
		wp_delete_user( $client_id );
	}
	if ( ! is_wp_error( $editor_id ) ) {
		wp_delete_user( $editor_id );
	}
}
