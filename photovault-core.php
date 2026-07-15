<?php
/**
 * Plugin Name: PhotoVault Core
 * Description: Core application layer for PhotoVault media, access rules, REST endpoints, roles, and statistics.
 * Version: 0.5.1
 * Author: PhotoVault
 * Text Domain: photovault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PHOTOVAULT_CORE_VERSION', '0.5.1' );
define( 'PHOTOVAULT_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHOTOVAULT_CORE_URI', plugin_dir_url( __FILE__ ) );

$photovault_core_includes = array(
	'inc/roles.php',
	'inc/post-types.php',
	'inc/taxonomies.php',
	'inc/private-storage.php',
	'inc/media-handlers.php',
	'inc/ajax-filters.php',
	'inc/helpers.php',
	'inc/admin-access.php',
	'inc/admin-upload.php',
	'inc/audit-log.php',
	'inc/emails.php',
	'inc/contact.php',
	'inc/access-requests.php',
	'inc/user-library.php',
	'inc/shootings.php',
	'inc/cli.php',
);

foreach ( $photovault_core_includes as $file ) {
	$filepath = PHOTOVAULT_CORE_DIR . $file;
	if ( file_exists( $filepath ) ) {
		require_once $filepath;
	}
}

function photovault_core_activate() {
	if ( function_exists( 'photovault_register_roles' ) ) {
		photovault_register_roles();
	}
	if ( function_exists( 'photovault_register_post_types' ) ) {
		photovault_register_post_types();
	}
	if ( function_exists( 'photovault_register_taxonomies' ) ) {
		photovault_register_taxonomies();
	}
	if ( function_exists( 'photovault_register_shooting_post_type' ) ) {
		photovault_register_shooting_post_type();
	}
	if ( function_exists( 'photovault_install_access_request_schema' ) ) {
		photovault_install_access_request_schema();
	}
	if ( function_exists( 'photovault_install_media_audit_schema' ) ) {
		photovault_install_media_audit_schema();
	}
	if ( function_exists( 'photovault_install_user_library_schema' ) ) {
		photovault_install_user_library_schema();
	}
	update_option( 'photovault_core_version', PHOTOVAULT_CORE_VERSION, false );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'photovault_core_activate' );

function photovault_core_maybe_upgrade() {
	if ( PHOTOVAULT_CORE_VERSION === get_option( 'photovault_core_version' ) ) {
		return;
	}

	if ( function_exists( 'photovault_install_access_request_schema' ) ) {
		photovault_install_access_request_schema();
	}
	if ( function_exists( 'photovault_install_media_audit_schema' ) ) {
		photovault_install_media_audit_schema();
	}
	if ( function_exists( 'photovault_install_user_library_schema' ) ) {
		photovault_install_user_library_schema();
	}
	if ( function_exists( 'photovault_register_roles' ) ) {
		photovault_register_roles();
	}

	update_option( 'photovault_core_version', PHOTOVAULT_CORE_VERSION, false );
}
add_action( 'admin_init', 'photovault_core_maybe_upgrade' );

function photovault_core_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'photovault_core_deactivate' );
