<?php
/**
 * Media upload, creation, and deletion handlers for PhotoVault Core.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function photovault_harden_upload_directory( $path ) {
	if ( empty( $path ) ) {
		return;
	}

	if ( ! wp_mkdir_p( $path ) ) {
		return;
	}

	$index_file = trailingslashit( $path ) . 'index.php';
	if ( ! file_exists( $index_file ) ) {
		file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
	}

	$htaccess_file = trailingslashit( $path ) . '.htaccess';
	if ( ! file_exists( $htaccess_file ) ) {
		$rules = "Options -Indexes\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
		file_put_contents( $htaccess_file, $rules );
	}
}
/**
 * Isolate non-admin uploads in a user-specific directory.
 */
function photovault_custom_upload_dir( $uploads ) {
	if ( is_user_logged_in() && ! photovault_current_user_can( 'photovault_manage_platform' ) ) {
		$user_id = get_current_user_id();
		$uploads['path']   = $uploads['basedir'] . '/photographers/user_' . $user_id;
		$uploads['url']    = $uploads['baseurl'] . '/photographers/user_' . $user_id;
		$uploads['subdir'] = '/photographers/user_' . $user_id;
		photovault_harden_upload_directory( $uploads['path'] );
	}

	return $uploads;
}
add_filter( 'upload_dir', 'photovault_custom_upload_dir' );

function photovault_get_allowed_image_mimes() {
	return array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
	);
}

function photovault_get_upload_error_message( $error_code ) {
	$messages = array(
		UPLOAD_ERR_INI_SIZE   => 'Le fichier depasse la taille autorisee par le serveur.',
		UPLOAD_ERR_FORM_SIZE  => 'Le fichier depasse la taille autorisee par le formulaire.',
		UPLOAD_ERR_PARTIAL    => 'Le fichier a ete envoye partiellement.',
		UPLOAD_ERR_NO_FILE    => 'Aucun fichier n a ete recu.',
		UPLOAD_ERR_NO_TMP_DIR => 'Le dossier temporaire est indisponible.',
		UPLOAD_ERR_CANT_WRITE => 'Impossible d ecrire le fichier sur le disque.',
		UPLOAD_ERR_EXTENSION  => 'Une extension PHP a bloque l envoi du fichier.',
	);

	return isset( $messages[ $error_code ] ) ? $messages[ $error_code ] : 'Erreur inconnue pendant l envoi du fichier.';
}

function photovault_normalize_upload_files( $files ) {
	$normalized = array();

	if ( empty( $files ) || ! isset( $files['name'] ) ) {
		return $normalized;
	}

	if ( is_array( $files['name'] ) ) {
		$file_count = count( $files['name'] );
		for ( $i = 0; $i < $file_count; $i++ ) {
			if ( empty( $files['name'][ $i ] ) ) {
				continue;
			}

			$normalized[] = array(
				'name'     => $files['name'][ $i ],
				'type'     => $files['type'][ $i ],
				'tmp_name' => $files['tmp_name'][ $i ],
				'error'    => $files['error'][ $i ],
				'size'     => $files['size'][ $i ],
			);
		}
		return $normalized;
	}

	if ( ! empty( $files['name'] ) ) {
		$normalized[] = $files;
	}

	return $normalized;
}

function photovault_validate_uploaded_image_file( $file ) {
	if ( empty( $file['name'] ) ) {
		return new WP_Error( 'empty_file', 'Aucun fichier image n a ete fourni.' );
	}

	$error_code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_OK;
	if ( UPLOAD_ERR_OK !== $error_code ) {
		return new WP_Error( 'upload_error', photovault_get_upload_error_message( $error_code ) );
	}

	$max_size = (int) apply_filters( 'photovault_max_upload_bytes', 30 * 1024 * 1024 );
	$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
	if ( $size <= 0 || $size > $max_size ) {
		return new WP_Error( 'invalid_size', sprintf( 'La taille du fichier doit etre comprise entre 1 octet et %s Mo.', (int) floor( $max_size / 1024 / 1024 ) ) );
	}

	$tmp_name = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
	if ( empty( $tmp_name ) || ! is_uploaded_file( $tmp_name ) ) {
		return new WP_Error( 'invalid_tmp_file', 'Le fichier temporaire est invalide.' );
	}

	$allowed_mimes = photovault_get_allowed_image_mimes();
	$file_check = wp_check_filetype_and_ext( $tmp_name, $file['name'], $allowed_mimes );
	if ( empty( $file_check['ext'] ) || empty( $file_check['type'] ) || ! in_array( $file_check['type'], $allowed_mimes, true ) ) {
		return new WP_Error( 'invalid_type', 'Format refuse. Utilisez JPG, PNG ou WebP.' );
	}

	$image_size = @getimagesize( $tmp_name );
	if ( false === $image_size || empty( $image_size[0] ) || empty( $image_size[1] ) ) {
		return new WP_Error( 'invalid_image', 'Le fichier envoye n est pas une image valide.' );
	}

	$max_dimension = (int) apply_filters( 'photovault_max_upload_dimension', 12000 );
	if ( $image_size[0] > $max_dimension || $image_size[1] > $max_dimension ) {
		return new WP_Error( 'invalid_dimensions', sprintf( 'Les dimensions ne doivent pas depasser %d pixels par cote.', $max_dimension ) );
	}

	return true;
}

function photovault_redirect_upload_error( $error ) {
	$code = is_wp_error( $error ) ? $error->get_error_code() : 'upload_failed';
	wp_safe_redirect( add_query_arg( array( 'upload' => 'failed', 'reason' => sanitize_key( $code ) ), admin_url( 'edit.php?post_type=media_item' ) ) );
	exit;
}

/**
 * Handle single or multiple media uploads.
 */
function photovault_handle_media_upload() {
	if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || ! isset( $_POST['photovault_upload_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['photovault_upload_nonce'] ) ), 'photovault_upload_action' ) ) {
		wp_die( esc_html__( 'Echec de la verification de securite.', 'photovault' ) );
	}

	if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
		wp_safe_redirect( home_url( '/login/' ) );
		exit;
	}

	if ( empty( $_FILES['media_files'] ) ) {
		photovault_redirect_upload_error( new WP_Error( 'missing_file', 'Aucun fichier media n a ete recu.' ) );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';

	$files = photovault_normalize_upload_files( $_FILES['media_files'] );
	$max_files = (int) apply_filters( 'photovault_max_upload_files', 20 );

	if ( empty( $files ) || count( $files ) > $max_files ) {
		photovault_redirect_upload_error( new WP_Error( 'invalid_file_count', 'Le nombre de fichiers envoyes est invalide.' ) );
	}

	foreach ( $files as $file ) {
		$validation = photovault_validate_uploaded_image_file( $file );
		if ( is_wp_error( $validation ) ) {
			photovault_redirect_upload_error( $validation );
		}
	}

	$title        = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
	$description  = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
	$folder       = isset( $_POST['folder'] ) ? absint( $_POST['folder'] ) : 0;
	$category     = isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0;
	$visibility   = ( isset( $_POST['visibility'] ) && 'private' === sanitize_key( wp_unslash( $_POST['visibility'] ) ) ) ? 'private' : 'publish';
	$is_protected = isset( $_POST['is_protected'] ) ? '1' : '0';

	foreach ( $files as $index => $file ) {
		$file_key = 'photovault_upload_' . $index;
		$_FILES[ $file_key ] = $file;
		$result = photovault_create_media_post( $file_key, $title, $description, $folder, $category, $visibility, $is_protected );
		unset( $_FILES[ $file_key ] );

		if ( is_wp_error( $result ) ) {
			photovault_redirect_upload_error( $result );
		}
	}

	wp_safe_redirect( admin_url( 'edit.php?post_type=media_item&upload=success' ) );
	exit;
}
add_action( 'template_redirect', 'photovault_handle_media_upload' );

/**
 * Create the media_item entity and attach its image.
 */
function photovault_create_media_post( $file_key, $title, $description, $folder, $category, $visibility, $is_protected ) {
	$current_user_id = get_current_user_id();

	if ( empty( $_FILES[ $file_key ] ) ) {
		return new WP_Error( 'missing_file', 'Aucun fichier media n a ete recu.' );
	}

	$validation = photovault_validate_uploaded_image_file( $_FILES[ $file_key ] );
	if ( is_wp_error( $validation ) ) {
		return $validation;
	}

	$attachment_id = media_handle_upload( $file_key, 0, array(), array( 'mimes' => photovault_get_allowed_image_mimes() ) );

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	$media_post_id = wp_insert_post( array(
		'post_title'   => ! empty( $title ) ? $title : get_the_title( $attachment_id ),
		'post_content' => $description,
		'post_status'  => $visibility,
		'post_type'    => 'media_item',
		'post_author'  => $current_user_id,
	) );

	if ( is_wp_error( $media_post_id ) ) {
		wp_delete_attachment( $attachment_id, true );
		return $media_post_id;
	}

	set_post_thumbnail( $media_post_id, $attachment_id );

	if ( $folder ) {
		wp_set_post_terms( $media_post_id, array( $folder ), 'media_folder' );
	}

	if ( $category ) {
		wp_set_post_terms( $media_post_id, array( $category ), 'media_category' );
	}

	update_post_meta( $media_post_id, 'is_protected', $is_protected );

	return $media_post_id;
}

/**
 * Handle media deletion with ownership verification.
 */
function photovault_handle_media_delete() {
	if ( isset( $_GET['action'] ) && 'delete_media' === sanitize_key( wp_unslash( $_GET['action'] ) ) && isset( $_GET['media_id'] ) ) {
		$media_id = absint( $_GET['media_id'] );

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'delete_media_' . $media_id ) ) {
			wp_die( esc_html__( 'Echec de la verification de securite.', 'photovault' ) );
		}

		$post = get_post( $media_id );
		if ( ! $post || 'media_item' !== $post->post_type ) {
			wp_die( esc_html__( 'Media introuvable.', 'photovault' ) );
		}

		if ( intval( $post->post_author ) !== get_current_user_id() && ! photovault_current_user_can( 'photovault_manage_media' ) ) {
			wp_die( esc_html__( 'Vous n etes pas autorise a supprimer ce media.', 'photovault' ) );
		}

		$thumbnail_id = get_post_thumbnail_id( $media_id );
		if ( $thumbnail_id ) {
			wp_delete_attachment( $thumbnail_id, true );
		}

		wp_delete_post( $media_id, true );

		wp_safe_redirect( admin_url( 'edit.php?post_type=media_item&delete=success' ) );
		exit;
	}
}
add_action( 'template_redirect', 'photovault_handle_media_delete' );
