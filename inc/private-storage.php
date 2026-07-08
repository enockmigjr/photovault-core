<?php
/**
 * Private original storage for sensitive PhotoVault media.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function photovault_get_private_originals_dir() {
	$default = trailingslashit( WP_CONTENT_DIR ) . 'photovault-private/originals';
	$path    = apply_filters( 'photovault_private_originals_dir', $default );

	return untrailingslashit( wp_normalize_path( $path ) );
}

function photovault_harden_private_storage_directory( $path ) {
	if ( empty( $path ) || ! wp_mkdir_p( $path ) ) {
		return false;
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

	return true;
}

function photovault_is_private_original_path( $path ) {
	if ( empty( $path ) ) {
		return false;
	}

	$path = wp_normalize_path( $path );
	$root = trailingslashit( photovault_get_private_originals_dir() );

	return 0 === strpos( $path, $root );
}

function photovault_media_requires_private_original( $media_id ) {
	$media_id = absint( $media_id );
	$post     = get_post( $media_id );

	if ( ! $post || 'media_item' !== $post->post_type ) {
		return false;
	}

	return 'private' === $post->post_status || '1' === get_post_meta( $media_id, 'is_protected', true );
}

function photovault_store_attachment_original_private( $attachment_id, $media_id = 0 ) {
	$attachment_id = absint( $attachment_id );
	$media_id      = absint( $media_id );
	$source        = get_attached_file( $attachment_id, true );

	if ( ! $attachment_id || empty( $source ) ) {
		return new WP_Error( 'missing_attachment', __( 'Attachment original introuvable.', 'photovault' ) );
	}

	$source = wp_normalize_path( $source );
	if ( photovault_is_private_original_path( $source ) ) {
		update_post_meta( $attachment_id, '_photovault_private_original', '1' );
		return true;
	}

	if ( ! file_exists( $source ) || ! is_readable( $source ) ) {
		return new WP_Error( 'source_missing', __( 'Fichier original introuvable sur le disque.', 'photovault' ) );
	}

	$target_dir = trailingslashit( photovault_get_private_originals_dir() ) . gmdate( 'Y/m' );
	if ( ! photovault_harden_private_storage_directory( $target_dir ) ) {
		return new WP_Error( 'private_storage_unavailable', __( 'Stockage prive indisponible.', 'photovault' ) );
	}

	$extension = pathinfo( $source, PATHINFO_EXTENSION );
	$basename  = sanitize_file_name( pathinfo( $source, PATHINFO_FILENAME ) );
	$hash      = substr( wp_hash( $attachment_id . '|' . $media_id . '|' . basename( $source ) ), 0, 16 );
	$filename  = trim( $media_id . '-' . $attachment_id . '-' . $hash . '-' . $basename, '-' );
	if ( $extension ) {
		$filename .= '.' . strtolower( sanitize_key( $extension ) );
	}

	$target = trailingslashit( $target_dir ) . $filename;
	$target = wp_unique_filename( $target_dir, basename( $target ) );
	$target = trailingslashit( $target_dir ) . $target;

	$moved = @rename( $source, $target );
	if ( ! $moved ) {
		$moved = @copy( $source, $target );
		if ( $moved ) {
			@unlink( $source );
		}
	}

	if ( ! $moved || ! file_exists( $target ) ) {
		return new WP_Error( 'private_move_failed', __( 'Impossible de deplacer l original vers le stockage prive.', 'photovault' ) );
	}

	update_attached_file( $attachment_id, wp_normalize_path( $target ) );
	update_post_meta( $attachment_id, '_photovault_private_original', '1' );

	if ( function_exists( 'photovault_log_media_event' ) ) {
		photovault_log_media_event( 'original_moved_private', 'success', $media_id, array( 'attachment_id' => $attachment_id ) );
	}

	return true;
}

function photovault_maybe_secure_media_original( $media_id ) {
	$media_id = absint( $media_id );
	if ( ! photovault_media_requires_private_original( $media_id ) ) {
		return true;
	}

	$attachment_id = get_post_thumbnail_id( $media_id );
	if ( ! $attachment_id ) {
		return new WP_Error( 'missing_thumbnail', __( 'Aucun fichier principal associe au media.', 'photovault' ) );
	}

	$result = photovault_store_attachment_original_private( $attachment_id, $media_id );
	if ( is_wp_error( $result ) && function_exists( 'photovault_log_media_event' ) ) {
		photovault_log_media_event( 'original_private_failed', 'warning', $media_id, array( 'reason' => $result->get_error_code(), 'attachment_id' => $attachment_id ) );
	}

	return $result;
}

function photovault_get_media_original_security_status( $media_id ) {
	$media_id      = absint( $media_id );
	$attachment_id = get_post_thumbnail_id( $media_id );
	$path          = $attachment_id ? get_attached_file( $attachment_id, true ) : '';
	$requires      = photovault_media_requires_private_original( $media_id );
	$is_private    = $path && photovault_is_private_original_path( $path );

	return array(
		'attachment_id' => $attachment_id,
		'requires'      => $requires,
		'is_private'    => $is_private,
		'needs_action'  => $requires && ! $is_private,
	);
}

function photovault_get_unsecured_sensitive_media_ids( $limit = 25 ) {
	$limit = max( 1, min( 100, absint( $limit ) ) );
	$query = new WP_Query(
		array(
			'post_type'      => 'media_item',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => 200,
			'fields'         => 'ids',
		)
	);

	$ids = array();
	foreach ( array_map( 'absint', $query->posts ) as $media_id ) {
		$status = photovault_get_media_original_security_status( $media_id );
		if ( ! empty( $status['needs_action'] ) ) {
			$ids[] = $media_id;
		}

		if ( count( $ids ) >= $limit ) {
			break;
		}
	}

	return $ids;
}

function photovault_handle_secure_existing_originals() {
	if ( ! photovault_current_user_can( 'photovault_manage_media' ) ) {
		wp_die( esc_html__( 'Action non autorisee.', 'photovault' ) );
	}

	check_admin_referer( 'photovault_secure_existing_originals' );

	$secured = 0;
	$failed  = 0;
	foreach ( photovault_get_unsecured_sensitive_media_ids( 25 ) as $media_id ) {
		$result = photovault_maybe_secure_media_original( $media_id );
		if ( is_wp_error( $result ) ) {
			$failed++;
		} else {
			$secured++;
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'    => 'photovault-access-downloads',
				'post_type' => 'media_item',
				'secured' => $secured,
				'failed'  => $failed,
			),
			admin_url( 'edit.php' )
		)
	);
	exit;
}
add_action( 'admin_post_photovault_secure_existing_originals', 'photovault_handle_secure_existing_originals' );