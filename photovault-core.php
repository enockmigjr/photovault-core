<?php
/**
 * Plugin Name: PhotoVault Core
 * Description: Core application layer for PhotoVault media, access rules, REST endpoints, roles, and statistics.
 * Version: 0.1.2
 * Author: PhotoVault
 * Text Domain: photovault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PHOTOVAULT_CORE_VERSION', '0.1.2' );
define( 'PHOTOVAULT_CORE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PHOTOVAULT_CORE_URI', plugin_dir_url( __FILE__ ) );

$photovault_core_includes = array(
	'inc/roles.php',
	'inc/post-types.php',
	'inc/taxonomies.php',
	'inc/auth-handlers.php',
	'inc/media-handlers.php',
	'inc/ajax-filters.php',
	'inc/helpers.php',
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
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'photovault_core_activate' );

function photovault_core_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'photovault_core_deactivate' );
