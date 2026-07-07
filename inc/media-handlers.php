<?php
/**
 * Gestionnaires d'upload, édition et suppression de médias pour PhotoVault.
 *
 * @package PhotoVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Isoler les téléversements des photographes dans un dossier personnalisé.
 */
function photovault_custom_upload_dir( $uploads ) {
	if ( is_user_logged_in() && ! current_user_can( 'administrator' ) ) {
		$user_id = get_current_user_id();
		$uploads['path']   = $uploads['basedir'] . '/photographers/user_' . $user_id;
		$uploads['url']    = $uploads['baseurl'] . '/photographers/user_' . $user_id;
		$uploads['subdir'] = '/photographers/user_' . $user_id;
	}
	return $uploads;
}
add_filter( 'upload_dir', 'photovault_custom_upload_dir' );

/**
 * Traiter l'upload de média (simple ou multiple).
 */
function photovault_handle_media_upload() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['photovault_upload_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( $_POST['photovault_upload_nonce'], 'photovault_upload_action' ) ) {
		wp_die( esc_html__( 'Échec de la vérification de sécurité.', 'photovault' ) );
	}

	if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
		wp_redirect( home_url( '/login/' ) );
		exit;
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$files = $_FILES['media_files'];
	$title       = sanitize_text_field( $_POST['title'] );
	$description = sanitize_textarea_field( $_POST['description'] );
	$folder      = isset( $_POST['folder'] ) ? intval( $_POST['folder'] ) : 0;
	$category    = isset( $_POST['category'] ) ? intval( $_POST['category'] ) : 0;
	$visibility  = ( isset( $_POST['visibility'] ) && 'private' === $_POST['visibility'] ) ? 'private' : 'publish';
	$is_protected = isset( $_POST['is_protected'] ) ? '1' : '0';

	// Si upload multiple, restructurer le tableau $_FILES pour boucler.
	if ( is_array( $files['name'] ) ) {
		$file_count = count( $files['name'] );
		for ( $i = 0; $i < $file_count; $i++ ) {
			if ( empty( $files['name'][$i] ) ) {
				continue;
			}
			$_FILES['temp_upload'] = array(
				'name'     => $files['name'][$i],
				'type'     => $files['type'][$i],
				'tmp_name' => $files['tmp_name'][$i],
				'error'    => $files['error'][$i],
				'size'     => $files['size'][$i],
			);
			photovault_create_media_post( 'temp_upload', $title, $description, $folder, $category, $visibility, $is_protected );
		}
	} else {
		photovault_create_media_post( 'media_files', $title, $description, $folder, $category, $visibility, $is_protected );
	}

	wp_redirect( admin_url( 'edit.php?post_type=media_item&upload=success' ) );
	exit;
}
add_action( 'template_redirect', 'photovault_handle_media_upload' );

/**
 * Créer l'entité media_item et attacher le fichier.
 */
function photovault_create_media_post( $file_key, $title, $description, $folder, $category, $visibility, $is_protected ) {
	$current_user_id = get_current_user_id();

	// Téléverser l'image dans la bibliothèque WP.
	$attachment_id = media_handle_upload( $file_key, 0 );

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	// Créer le post de type 'media_item'.
	$media_post_id = wp_insert_post( array(
		'post_title'   => ! empty( $title ) ? $title : get_the_title( $attachment_id ),
		'post_content' => $description,
		'post_status'  => $visibility,
		'post_type'    => 'media_item',
		'post_author'  => $current_user_id,
	) );

	if ( is_wp_error( $media_post_id ) ) {
		return $media_post_id;
	}

	// Attacher l'image à la une (thumbnail) du CPT.
	set_post_thumbnail( $media_post_id, $attachment_id );

	// Associer la taxonomie Dossier.
	if ( $folder ) {
		wp_set_post_terms( $media_post_id, array( $folder ), 'media_folder' );
	}

	// Associer la taxonomie Catégorie.
	if ( $category ) {
		wp_set_post_terms( $media_post_id, array( $category ), 'media_category' );
	}

	// Sauvegarder la métadonnée de protection.
	update_post_meta( $media_post_id, 'is_protected', $is_protected );

	return $media_post_id;
}

/**
 * Traiter la suppression de média (vérification stricte de propriété).
 */
function photovault_handle_media_delete() {
	if ( isset( $_GET['action'] ) && 'delete_media' === $_GET['action'] && isset( $_GET['media_id'] ) ) {
		$media_id = intval( $_GET['media_id'] );
		
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'delete_media_' . $media_id ) ) {
			wp_die( esc_html__( 'Échec de la vérification de sécurité.', 'photovault' ) );
		}

		$post = get_post( $media_id );
		if ( ! $post || 'media_item' !== $post->post_type ) {
			wp_die( esc_html__( 'Média introuvable.', 'photovault' ) );
		}

		// Sécurité : Vérifier que l'utilisateur est le propriétaire ou admin.
		if ( intval( $post->post_author ) !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'êtes pas autorisé à supprimer ce média.', 'photovault' ) );
		}

		// Supprimer l'image attachée à la une d'abord.
		$thumbnail_id = get_post_thumbnail_id( $media_id );
		if ( $thumbnail_id ) {
			wp_delete_attachment( $thumbnail_id, true );
		}

		// Supprimer le post.
		wp_delete_post( $media_id, true );

		wp_redirect( admin_url( 'edit.php?post_type=media_item&delete=success' ) );
		exit;
	}
}
add_action( 'template_redirect', 'photovault_handle_media_delete' );
