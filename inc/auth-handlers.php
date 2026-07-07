<?php
/**
 * Gestionnaires d'authentification et profil utilisateur pour PhotoVault.
 *
 * @package PhotoVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Traitement de la connexion personnalisée.
 */
function photovault_handle_login() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['photovault_login_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $_POST['photovault_login_nonce'], 'photovault_login_action' ) ) {
		wp_die( esc_html__( 'Échec de la vérification de sécurité.', 'photovault' ) );
	}

	$creds = array(
		'user_login'    => sanitize_text_field( $_POST['log'] ),
		'user_password' => $_POST['pwd'],
		'remember'      => isset( $_POST['rememberme'] ),
	);

	$user = wp_signon( $creds, is_ssl() );

	if ( is_wp_error( $user ) ) {
		// Renvoyer l'erreur via la session pour l'afficher sur la page de login
		wp_redirect( add_query_arg( 'login', 'failed', home_url( '/login/' ) ) );
		exit;
	}

	// Redirection en fonction du rôle
	if ( current_user_can( 'manage_options' ) ) {
		wp_redirect( home_url( '/dashboard/' ) );
	} else {
		wp_redirect( get_post_type_archive_link( 'media_item' ) );
	}
	exit;
}
add_action( 'template_redirect', 'photovault_handle_login' );

/**
 * Traitement de l'inscription personnalisée du photographe.
 */
function photovault_handle_registration() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['photovault_register_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $_POST['photovault_register_nonce'], 'photovault_register_action' ) ) {
		wp_die( esc_html__( 'Échec de la vérification de sécurité.', 'photovault' ) );
	}

	$first_name = sanitize_text_field( $_POST['first_name'] );
	$last_name  = sanitize_text_field( $_POST['last_name'] );
	$username   = sanitize_user( $_POST['username'] );
	$email      = sanitize_email( $_POST['email'] );
	$password   = $_POST['password'];
	$password_c = $_POST['password_confirm'];

	$error_code = '';

	if ( empty( $username ) || empty( $email ) || empty( $password ) ) {
		$error_code = 'fields_required';
	} elseif ( $password !== $password_c ) {
		$error_code = 'password_mismatch';
	} elseif ( email_exists( $email ) ) {
		$error_code = 'email_exists';
	} elseif ( username_exists( $username ) ) {
		$error_code = 'username_exists';
	}

	if ( ! empty( $error_code ) ) {
		wp_redirect( add_query_arg( array( 'register' => 'failed', 'err' => $error_code ), home_url( '/register/' ) ) );
		exit;
	}

	$user_id = wp_insert_user( array(
		'user_login' => $username,
		'user_email' => $email,
		'user_pass'  => $password,
		'first_name' => $first_name,
		'last_name'  => $last_name,
		'role'       => 'client',
	) );

	if ( is_wp_error( $user_id ) ) {
		wp_redirect( add_query_arg( array( 'register' => 'failed', 'err' => 'failed' ), home_url( '/register/' ) ) );
		exit;
	}

	// Auto-connexion après inscription
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id );
	wp_redirect( get_post_type_archive_link( 'media_item' ) );
	exit;
}
add_action( 'template_redirect', 'photovault_handle_registration' );

/**
 * Traitement de la mise à jour du profil utilisateur.
 */
function photovault_handle_profile_update() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['photovault_profile_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $_POST['photovault_profile_nonce'], 'photovault_profile_action' ) ) {
		wp_die( esc_html__( 'Échec de la vérification de sécurité.', 'photovault' ) );
	}

	if ( ! is_user_logged_in() ) {
		wp_redirect( home_url( '/login/' ) );
		exit;
	}

	$current_user_id = get_current_user_id();
	$email = sanitize_email( $_POST['email'] );
	$bio   = sanitize_textarea_field( $_POST['bio'] );
	
	$user_data = array(
		'ID'         => $current_user_id,
		'user_email' => $email,
		'description'=> $bio,
	);

	// Gestion du mot de passe
	if ( ! empty( $_POST['password'] ) && ! empty( $_POST['password_confirm'] ) ) {
		if ( $_POST['password'] === $_POST['password_confirm'] ) {
			$user_data['user_pass'] = $_POST['password'];
		} else {
			wp_redirect( add_query_arg( 'profile', 'pwd_mismatch', home_url( '/profile/' ) ) );
			exit;
		}
	}

	// Upload d'image de profil personnalisée
	if ( ! empty( $_FILES['profile_avatar']['name'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'profile_avatar', 0 );
		if ( ! is_wp_error( $attachment_id ) ) {
			update_user_meta( $current_user_id, 'photovault_avatar_id', $attachment_id );
		}
	}

	wp_update_user( $user_data );
	wp_redirect( add_query_arg( 'profile', 'success', home_url( '/profile/' ) ) );
	exit;
}
add_action( 'template_redirect', 'photovault_handle_profile_update' );
