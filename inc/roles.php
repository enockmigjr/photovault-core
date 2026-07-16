<?php
/**
 * Rôles et permissions du thème PhotoVault.
 *
 * @package PhotoVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Création et configuration du rôle "Client" à l'activation du thème.
 */
function photovault_register_roles() {
	// Supprimer l'ancien rôle photographe s'il existe pour nettoyer.
	remove_role( 'photographer' );

	// Vérifier si le rôle 'client' existe déjà avant de le créer (évite les pertes de privilèges personnalisés).

	$admin = get_role( 'administrator' );
	if ( $admin && function_exists( 'photovault_get_core_capabilities' ) ) {
		foreach ( photovault_get_core_capabilities() as $capability ) {
			$admin->add_cap( $capability );
		}
	}
	if ( null === get_role( 'client' ) ) {
		add_role( 'client', esc_html__( 'Client', 'photovault' ), array(
			'read'         => true,
			'upload_files' => false,
			'publish_posts'=> false,
			'edit_posts'   => false,
			'delete_posts' => false,
		) );
	}
}
add_action( 'after_switch_theme', 'photovault_register_roles' );

/** Return whether the current request targets a secured front-office endpoint. */
function photovault_is_frontend_admin_endpoint() {
	global $pagenow;

	return ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || in_array( $pagenow, array( 'admin-ajax.php', 'admin-post.php' ), true );
}

/** Return whether the current account may use the native WordPress admin UI. */
function photovault_current_user_can_access_admin() {
	return current_user_can( 'edit_posts' ) || current_user_can( 'upload_files' ) || photovault_current_user_can( 'photovault_manage_platform' );
}

/** Restrict the admin UI while preserving secured front-office endpoints. */
function photovault_restrict_admin_access() {
	if ( photovault_is_frontend_admin_endpoint() ) {
		return;
	}

	if ( is_user_logged_in() && ! photovault_current_user_can_access_admin() ) {
		wp_safe_redirect( home_url( '/dashboard/' ) );
		exit;
	}
}
add_action( 'admin_init', 'photovault_restrict_admin_access' );

/**
 * Restreindre l'accès aux galeries et médias pour les utilisateurs anonymes.
 */
function photovault_enforce_login_for_media() {
	if ( ! is_user_logged_in() && is_singular( 'media_item' ) && 'private' === get_post_status( get_queried_object_id() ) ) {
		wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( get_permalink( get_queried_object_id() ) ), home_url( '/login/' ) ) );
		exit;
	}
}
add_action( 'template_redirect', 'photovault_enforce_login_for_media' );

/**
 * Redirection de wp-login.php si l'utilisateur essaie de s'y connecter directement.
 */
function photovault_redirect_login_page() {
	global $pagenow;
	
	if ( 'wp-login.php' === $pagenow && ! isset( $_GET['action'] ) && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
		wp_safe_redirect( home_url( '/login/' ) );
		exit;
	}
}
add_action( 'init', 'photovault_redirect_login_page' );

/**
 * Cacher la barre d'administration pour les utilisateurs non-administrateurs et visiteurs.
 */
function photovault_hide_admin_bar() {
	if ( ! photovault_current_user_can_access_admin() ) {
		show_admin_bar( false );
	}
}
add_action( 'after_setup_theme', 'photovault_hide_admin_bar' );
