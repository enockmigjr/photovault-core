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

/**
 * Bloquer l'accès à wp-admin et rediriger tout utilisateur non-administrateur.
 */
function photovault_restrict_admin_access() {
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
		return;
	}

	if ( is_user_logged_in() ) {
		if ( ! photovault_current_user_can( 'photovault_manage_platform' ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}
	}
}
add_action( 'admin_init', 'photovault_restrict_admin_access' );

/**
 * Restreindre l'accès aux galeries et médias pour les utilisateurs anonymes.
 */
function photovault_enforce_login_for_media() {
	if ( ! is_user_logged_in() ) {
		if ( is_post_type_archive( 'media_item' ) || is_singular( 'media_item' ) || is_tax( 'media_folder' ) || is_tax( 'media_category' ) ) {
			wp_safe_redirect( home_url( '/login/' ) );
			exit;
		}
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
	if ( ! photovault_current_user_can( 'photovault_manage_platform' ) ) {
		show_admin_bar( false );
	}
}
add_action( 'after_setup_theme', 'photovault_hide_admin_bar' );

