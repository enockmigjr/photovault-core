<?php
/**
 * Moteur de recherche et de filtrage via l'API REST WordPress pour PhotoVault.
 *
 * @package PhotoVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function photovault_register_rest_routes() {
	register_rest_route( 'photovault/v1', '/media', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'photovault_get_filtered_media',
		'permission_callback' => 'is_user_logged_in',
	) );
	register_rest_route( 'photovault/v1', '/secure-image', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'photovault_serve_secure_image',
		'permission_callback' => '__return_true',
	) );
}
add_action( 'rest_api_init', 'photovault_register_rest_routes' );

/**
 * Construire une URL d'image protegee en indiquant l'usage attendu.
 */
function photovault_get_secure_image_url( $media_id, $display = 'card', $download = false ) {
	$args = array(
		'id'      => absint( $media_id ),
		'display' => sanitize_key( $display ),
	);

	if ( $download ) {
		$args['download'] = '1';
		unset( $args['display'] );
	}

	if ( is_user_logged_in() ) {
		$args['_wpnonce'] = wp_create_nonce( 'wp_rest' );
	}

	return add_query_arg( $args, rest_url( 'photovault/v1/secure-image' ) );
}

function photovault_get_secure_image_variant( $attachment_id, $display ) {
	$display = sanitize_key( $display );
	$sizes = array(
		'card'    => array( 'photovault-card', 'medium', 'thumbnail' ),
		'preview' => array( 'photovault-preview', 'large', 'medium_large' ),
	);
	$wanted_sizes = isset( $sizes[ $display ] ) ? $sizes[ $display ] : $sizes['card'];
	$meta = wp_get_attachment_metadata( $attachment_id );
	$upload_dir = wp_get_upload_dir();

	if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && ! empty( $meta['file'] ) ) {
		$relative_dir = dirname( $meta['file'] );
		$relative_dir = '.' === $relative_dir ? '' : $relative_dir;

		foreach ( $wanted_sizes as $size_name ) {
			if ( empty( $meta['sizes'][ $size_name ]['file'] ) ) {
				continue;
			}

			$relative_file = trim( $relative_dir . '/' . $meta['sizes'][ $size_name ]['file'], '/\\' );
			$path = trailingslashit( $upload_dir['basedir'] ) . $relative_file;
			$path = wp_normalize_path( $path );

			if ( file_exists( $path ) ) {
				return array(
					'path' => $path,
					'mime' => ! empty( $meta['sizes'][ $size_name ]['mime-type'] ) ? $meta['sizes'][ $size_name ]['mime-type'] : photovault_get_file_mime_type( $path, $attachment_id ),
				);
			}
		}
	}

	$original_path = get_attached_file( $attachment_id );
	return array(
		'path' => $original_path,
		'mime' => photovault_get_file_mime_type( $original_path, $attachment_id ),
	);
}

function photovault_get_file_mime_type( $path, $attachment_id ) {
	$filetype = wp_check_filetype( $path );
	if ( ! empty( $filetype['type'] ) ) {
		return $filetype['type'];
	}

	return get_post_mime_type( $attachment_id );
}

function photovault_get_filtered_media( $request ) {
	$params = $request->get_params();
	$args = array(
		'post_type'      => 'media_item',
		'posts_per_page' => 12,
		'paged'          => ! empty( $params['page'] ) ? intval( $params['page'] ) : 1,
	);

	if ( current_user_can( 'manage_options' ) ) {
		$args['post_status'] = array( 'publish', 'private' );
	} else {
		$args['post_status'] = array( 'publish' );
	}

	if ( ! empty( $params['search'] ) ) {
		$args['s'] = sanitize_text_field( $params['search'] );
	}

	$tax_query = array( 'relation' => 'AND' );
	foreach ( array( 'folder' => 'media_folder', 'category' => 'media_category' ) as $param => $tax ) {
		if ( ! empty( $params[ $param ] ) ) {
			$tax_query[] = array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => intval( $params[ $param ] ) );
		}
	}
	if ( count( $tax_query ) > 1 ) {
		$args['tax_query'] = $tax_query;
	}

	if ( ! empty( $params['author_id'] ) ) {
		$args['author'] = intval( $params['author_id'] );
	}

	foreach ( array( 'year', 'month' ) as $date_field ) {
		if ( ! empty( $params[ $date_field ] ) ) {
			$args['date_query'][] = array( $date_field => intval( $params[ $date_field ] ) );
		}
	}

	if ( isset( $params['protected'] ) && '' !== $params['protected'] ) {
		$args['meta_query'] = array( array( 'key' => 'is_protected', 'value' => sanitize_text_field( $params['protected'] ), 'compare' => '=' ) );
	}

	if ( ! empty( $params['orderby'] ) ) {
		$orderby = sanitize_text_field( $params['orderby'] );
		$args['orderby'] = ( 'alphabetical' === $orderby ) ? 'title' : 'date';
		$args['order']   = ( 'alphabetical' === $orderby || 'date_asc' === $orderby ) ? 'ASC' : 'DESC';
	}

	$query = new WP_Query( $args );
	$results = array();

	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();

			if ( 'private' === get_post_status() && ! current_user_can( 'manage_options' ) ) {
				continue;
			}

			$media_id = get_the_ID();
			ob_start();
			get_template_part( 'templates/media-card' );
			$html = ob_get_clean();

			$results[] = array(
				'id'           => $media_id,
				'title'        => get_the_title(),
				'url'          => get_permalink(),
				'image'        => photovault_get_secure_image_url( $media_id, 'card' ),
				'author'       => get_the_author(),
				'is_protected' => get_post_meta( $media_id, 'is_protected', true ) === '1',
				'is_private'   => 'private' === get_post_status(),
				'html'         => $html,
			);
		}
		wp_reset_postdata();
	}

	return new WP_REST_Response( array( 'success' => true, 'data' => $results, 'pages' => $query->max_num_pages ), 200 );
}

function photovault_serve_secure_image( $request ) {
	if ( 0 === get_current_user_id() ) {
		$cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( $cookie_user_id ) {
			wp_set_current_user( $cookie_user_id );
		}
	}

	$media_id = intval( $request->get_param( 'id' ) );
	$post = get_post( $media_id );
	if ( ! $post || 'media_item' !== $post->post_type ) {
		return new WP_Error( 'not_found', 'Media introuvable.', array( 'status' => 404 ) );
	}

	$is_private = 'private' === $post->post_status;
	$is_admin = current_user_can( 'manage_options' );
	$is_owner = is_user_logged_in() && (int) $post->post_author === get_current_user_id();

	if ( $is_private && ! $is_admin && ! $is_owner ) {
		return new WP_Error( 'forbidden', 'Acces interdit.', array( 'status' => 403 ) );
	}

	$thumb_id = get_post_thumbnail_id( $media_id );
	if ( ! $thumb_id ) {
		return new WP_Error( 'not_found', 'Fichier introuvable.', array( 'status' => 404 ) );
	}

	$original_path = get_attached_file( $thumb_id );
	if ( ! $original_path || ! file_exists( $original_path ) ) {
		return new WP_Error( 'not_found', 'Fichier introuvable.', array( 'status' => 404 ) );
	}

	$is_protected = get_post_meta( $media_id, 'is_protected', true ) === '1';

	if ( $request->get_param( 'download' ) === '1' ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'unauthorized', 'Vous devez etre connecte pour telecharger des medias.', array( 'status' => 401 ) );
		}

		if ( $is_protected && ! $is_admin && ! $is_owner ) {
			return new WP_Error( 'forbidden', 'Telechargement interdit sur un media protege.', array( 'status' => 403 ) );
		}

		$downloads = (int) get_post_meta( $media_id, 'photovault_downloads_count', true );
		update_post_meta( $media_id, 'photovault_downloads_count', $downloads + 1 );

		$mime = photovault_get_file_mime_type( $original_path, $thumb_id );
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . basename( $original_path ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $original_path ) );

		if ( ob_get_length() ) {
			ob_clean();
		}
		flush();
		readfile( $original_path );
		exit;
	}

	$display = sanitize_key( $request->get_param( 'display' ) );
	$display = in_array( $display, array( 'card', 'preview' ), true ) ? $display : 'card';
	$variant = photovault_get_secure_image_variant( $thumb_id, $display );
	$filepath = $variant['path'];
	$mime = $variant['mime'];

	if ( ! $filepath || ! file_exists( $filepath ) ) {
		return new WP_Error( 'not_found', 'Fichier introuvable.', array( 'status' => 404 ) );
	}

	header( 'Content-Type: ' . $mime );
	header( 'Cache-Control: private, max-age=3600' );

	if ( $is_protected && ! $is_admin && ! $is_owner ) {
		$filesize = filesize( $filepath );
		if ( $filesize <= 20 * 1024 * 1024 && function_exists( 'imagecreatefromstring' ) ) {
			$img = null;
			if ( 'image/jpeg' === $mime || 'image/jpg' === $mime ) {
				$img = function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $filepath ) : null;
			} elseif ( 'image/png' === $mime ) {
				$img = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $filepath ) : null;
			} elseif ( 'image/webp' === $mime ) {
				$img = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $filepath ) : null;
			}

			if ( $img ) {
				$watermark_text = get_option( 'photovault_watermark_text', 'PHOTOVAULT' );
				$col = imagecolorallocatealpha( $img, 255, 255, 255, 52 );
				$w = imagesx( $img );
				$h = imagesy( $img );

				for ( $y = -30, $row = 0; $y < $h + 60; $y += 58, $row++ ) {
					$offset = ( $row % 4 ) * 42;
					for ( $x = -160 + $offset; $x < $w + 120; $x += 145 ) {
						imagestring( $img, 5, $x, $y, $watermark_text, $col );
					}
				}

				if ( 'image/png' === $mime ) {
					imagepng( $img );
				} elseif ( 'image/webp' === $mime ) {
					imagewebp( $img );
				} else {
					imagejpeg( $img );
				}
				imagedestroy( $img );
				exit;
			}
		}
	}

	readfile( $filepath );
	exit;
}