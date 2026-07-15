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
		'permission_callback' => '__return_true',
		'args'                => array(
			'page'      => array( 'sanitize_callback' => 'absint', 'validate_callback' => 'photovault_validate_optional_positive_int' ),
			'folder'    => array( 'sanitize_callback' => 'absint', 'validate_callback' => 'photovault_validate_optional_positive_int' ),
			'category'  => array( 'sanitize_callback' => 'absint', 'validate_callback' => 'photovault_validate_optional_positive_int' ),
			'author_id' => array( 'sanitize_callback' => 'absint', 'validate_callback' => 'photovault_validate_optional_positive_int' ),
			'year'      => array( 'sanitize_callback' => 'absint', 'validate_callback' => 'photovault_validate_optional_positive_int' ),
			'month'     => array( 'sanitize_callback' => 'absint', 'validate_callback' => 'photovault_validate_month' ),
			'protected' => array( 'sanitize_callback' => 'sanitize_key', 'validate_callback' => 'photovault_validate_binary_filter' ),
			'orderby'   => array( 'sanitize_callback' => 'sanitize_key', 'validate_callback' => 'photovault_validate_media_orderby' ),
			'search'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
		),
	) );
	register_rest_route( 'photovault/v1', '/secure-image', array(
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'photovault_serve_secure_image',
		'permission_callback' => '__return_true',
		'args'                => array(
			'id'       => array( 'required' => true, 'sanitize_callback' => 'absint', 'validate_callback' => 'photovault_validate_positive_int' ),
			'display'  => array( 'sanitize_callback' => 'sanitize_key', 'validate_callback' => 'photovault_validate_image_display' ),
			'download' => array( 'sanitize_callback' => 'sanitize_key', 'validate_callback' => 'photovault_validate_binary_filter' ),
		),
	) );
}
add_action( 'rest_api_init', 'photovault_register_rest_routes' );

function photovault_validate_positive_int( $value ) {
	return absint( $value ) > 0;
}

function photovault_validate_optional_positive_int( $value ) {
	return '' === $value || null === $value || absint( $value ) > 0;
}

function photovault_validate_month( $value ) {
	if ( '' === $value || null === $value ) {
		return true;
	}

	$month = absint( $value );
	return $month >= 1 && $month <= 12;
}

function photovault_validate_binary_filter( $value ) {
	return '' === $value || null === $value || in_array( (string) $value, array( '0', '1' ), true );
}

function photovault_validate_media_orderby( $value ) {
	return '' === $value || null === $value || in_array( sanitize_key( $value ), array( 'date_desc', 'date_asc', 'alphabetical' ), true );
}

function photovault_validate_image_display( $value ) {
	return '' === $value || null === $value || in_array( sanitize_key( $value ), array( 'card', 'preview' ), true );
}

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

function photovault_get_protected_preview_cache_dir() {
	$upload_dir = wp_get_upload_dir();
	$base_dir   = ! empty( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';
	$cache_dir  = trailingslashit( $base_dir ) . 'photovault-cache/protected-previews';

	return untrailingslashit( wp_normalize_path( apply_filters( 'photovault_protected_preview_cache_dir', $cache_dir ) ) );
}

function photovault_prepare_protected_preview_cache_dir() {
	$cache_dir = photovault_get_protected_preview_cache_dir();
	if ( empty( $cache_dir ) || ! wp_mkdir_p( $cache_dir ) ) {
		return false;
	}

	$index_file = trailingslashit( $cache_dir ) . 'index.php';
	if ( ! file_exists( $index_file ) ) {
		file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
	}

	$htaccess_file = trailingslashit( $cache_dir ) . '.htaccess';
	if ( ! file_exists( $htaccess_file ) ) {
		$rules = "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar)$\">\n\tRequire all denied\n</FilesMatch>\n";
		file_put_contents( $htaccess_file, $rules );
	}

	return $cache_dir;
}


function photovault_get_watermark_image_resource( $attachment_id ) {
	$attachment_id = absint( $attachment_id );
	if ( ! $attachment_id ) {
		return null;
	}

	$path = get_attached_file( $attachment_id );
	if ( ! $path || ! file_exists( $path ) ) {
		return null;
	}

	$mime = photovault_get_file_mime_type( $path, $attachment_id );
	if ( 'image/jpeg' === $mime || 'image/jpg' === $mime ) {
		return function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $path ) : null;
	}
	if ( 'image/png' === $mime ) {
		return function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $path ) : null;
	}
	if ( 'image/webp' === $mime ) {
		return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : null;
	}

	return null;
}
function photovault_get_watermark_settings() {
	$image_id   = photovault_sanitize_watermark_image_id( get_option( 'photovault_watermark_image_id', 0 ) );
	$image_path = $image_id ? get_attached_file( $image_id ) : '';

	return array(
		'text'        => photovault_sanitize_watermark_text( get_option( 'photovault_watermark_text', 'PHOTOVAULT' ) ),
		'opacity'     => photovault_sanitize_watermark_opacity( get_option( 'photovault_watermark_opacity', 60 ) ),
		'spacing'     => photovault_sanitize_watermark_spacing( get_option( 'photovault_watermark_spacing', 58 ) ),
		'quality'     => photovault_sanitize_watermark_quality( get_option( 'photovault_watermark_quality', 90 ) ),
		'image_id'    => $image_id,
		'image_mtime' => $image_path && file_exists( $image_path ) ? (int) filemtime( $image_path ) : 0,
	);
}
function photovault_get_protected_preview_cache_path( $media_id, $attachment_id, $display, $source_path, $mime, $watermark_settings ) {
	$cache_dir = photovault_prepare_protected_preview_cache_dir();
	if ( ! $cache_dir || ! $source_path || ! file_exists( $source_path ) ) {
		return '';
	}

	$extension = 'jpg';
	if ( 'image/png' === $mime ) {
		$extension = 'png';
	} elseif ( 'image/webp' === $mime ) {
		$extension = 'webp';
	}

	$key = implode(
		'|',
		array(
			absint( $media_id ),
			absint( $attachment_id ),
			sanitize_key( $display ),
			(string) filemtime( $source_path ),
			(string) filesize( $source_path ),
			wp_hash( wp_json_encode( $watermark_settings ) ),
			PHOTOVAULT_CORE_VERSION,
		)
	);

	return trailingslashit( $cache_dir ) . sha1( $key ) . '.' . $extension;
}

function photovault_render_protected_preview_to_cache( $source_path, $cache_path, $mime, $watermark_settings ) {
	if ( ! $source_path || ! $cache_path || ! file_exists( $source_path ) || ! function_exists( 'imagecreatefromstring' ) ) {
		return false;
	}

	$filesize = filesize( $source_path );
	if ( false === $filesize || $filesize > 20 * 1024 * 1024 ) {
		return false;
	}

	$img = null;
	if ( 'image/jpeg' === $mime || 'image/jpg' === $mime ) {
		$img = function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $source_path ) : null;
	} elseif ( 'image/png' === $mime ) {
		$img = function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $source_path ) : null;
	} elseif ( 'image/webp' === $mime ) {
		$img = function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $source_path ) : null;
	}

	if ( ! $img ) {
		return false;
	}

	$opacity = max( 10, min( 100, absint( $watermark_settings['opacity'] ) ) );
	$alpha   = max( 0, min( 127, 127 - (int) round( 127 * ( $opacity / 100 ) ) ) );
	$col     = imagecolorallocatealpha( $img, 255, 255, 255, $alpha );
	$w       = imagesx( $img );
	$h       = imagesy( $img );
	$spacing = max( 35, min( 180, absint( $watermark_settings['spacing'] ) ) );
	$x_step  = max( 90, (int) round( $spacing * 2.5 ) );

	$watermark_img = photovault_get_watermark_image_resource( $watermark_settings['image_id'] );
	if ( $watermark_img ) {
		$wm_w = imagesx( $watermark_img );
		$wm_h = imagesy( $watermark_img );
		$target_w = max( 64, min( 220, (int) round( $w * 0.22 ) ) );
		$target_h = max( 24, (int) round( $wm_h * ( $target_w / max( 1, $wm_w ) ) ) );
		$scaled = function_exists( 'imagescale' ) ? imagescale( $watermark_img, $target_w, $target_h ) : $watermark_img;
		if ( $scaled ) {
			for ( $y = -1 * $target_h, $row = 0; $y < $h + $target_h; $y += $spacing + $target_h, $row++ ) {
				$offset = ( $row % 4 ) * (int) round( $spacing * 0.72 );
				for ( $x = -1 * $x_step + $offset; $x < $w + $x_step; $x += $x_step ) {
					imagecopymerge( $img, $scaled, $x, $y, 0, 0, imagesx( $scaled ), imagesy( $scaled ), $opacity );
				}
			}
			if ( $scaled !== $watermark_img ) {
				imagedestroy( $scaled );
			}
		}
		imagedestroy( $watermark_img );
	} else {
		for ( $y = -30, $row = 0; $y < $h + 60; $y += $spacing, $row++ ) {
			$offset = ( $row % 4 ) * (int) round( $spacing * 0.72 );
			for ( $x = -1 * $x_step + $offset; $x < $w + $x_step; $x += $x_step ) {
				imagestring( $img, 5, $x, $y, $watermark_settings['text'], $col );
			}
		}
	}

	$tmp_path = $cache_path . '.' . wp_generate_password( 8, false, false ) . '.tmp';
	$written  = false;
	if ( 'image/png' === $mime ) {
		$written = imagepng( $img, $tmp_path );
	} elseif ( 'image/webp' === $mime ) {
		$written = function_exists( 'imagewebp' ) ? imagewebp( $img, $tmp_path ) : false;
	} else {
		$written = imagejpeg( $img, $tmp_path, max( 60, min( 95, absint( $watermark_settings['quality'] ) ) ) );
	}

	imagedestroy( $img );

	if ( ! $written || ! file_exists( $tmp_path ) ) {
		@unlink( $tmp_path );
		return false;
	}

	if ( ! @rename( $tmp_path, $cache_path ) ) {
		@unlink( $tmp_path );
		return false;
	}

	return file_exists( $cache_path );
}
function photovault_serve_file_response( $filepath, $mime, $cache_control = 'private, max-age=3600' ) {
	header( 'Content-Type: ' . $mime );
	header( 'Cache-Control: ' . $cache_control );
	header( 'Content-Length: ' . filesize( $filepath ) );
	readfile( $filepath );
	exit;
}

function photovault_clear_protected_preview_cache( ...$args ) {
	$cache_dir = photovault_get_protected_preview_cache_dir();
	if ( empty( $cache_dir ) || ! is_dir( $cache_dir ) ) {
		return;
	}

	$files = glob( trailingslashit( $cache_dir ) . '*.{jpg,jpeg,png,webp,tmp}', GLOB_BRACE );
	if ( ! is_array( $files ) ) {
		return;
	}

	foreach ( $files as $file ) {
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
	}
}
add_action( 'save_post_media_item', 'photovault_clear_protected_preview_cache' );
add_action( 'deleted_post', 'photovault_clear_protected_preview_cache' );
add_action( 'update_option_photovault_watermark_text', 'photovault_clear_protected_preview_cache' );
add_action( 'update_option_photovault_watermark_opacity', 'photovault_clear_protected_preview_cache' );
add_action( 'update_option_photovault_watermark_spacing', 'photovault_clear_protected_preview_cache' );
add_action( 'update_option_photovault_watermark_quality', 'photovault_clear_protected_preview_cache' );
add_action( 'update_option_photovault_watermark_image_id', 'photovault_clear_protected_preview_cache' );

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

/**
 * Restrict private media before pagination is calculated.
 */
function photovault_restrict_media_query_where( $where, $user_id ) {
	global $wpdb;

	$user_id = absint( $user_id );
	$where  .= $wpdb->prepare(
		" AND ({$wpdb->posts}.post_status <> %s OR {$wpdb->posts}.post_author = %d",
		'private',
		$user_id
	);

	if ( $user_id && photovault_user_has_verified_identity( $user_id ) ) {
		$user = get_userdata( $user_id );
		if ( $user && is_email( $user->user_email ) ) {
			$grants_table = photovault_get_access_grants_table();
			$where       .= $wpdb->prepare(
				" OR EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} pv_tr
					INNER JOIN {$wpdb->term_taxonomy} pv_tt ON pv_tt.term_taxonomy_id = pv_tr.term_taxonomy_id
					INNER JOIN {$grants_table} pv_grant ON pv_grant.folder_id = pv_tt.term_id
					WHERE pv_tr.object_id = {$wpdb->posts}.ID
					AND pv_tt.taxonomy = %s
					AND pv_grant.email_hash = %s
					AND (pv_grant.user_id = 0 OR pv_grant.user_id = %d)
					AND pv_grant.status = %s
				)",
				'media_folder',
				photovault_hash_access_email( $user->user_email ),
				$user_id,
				'active'
			);
		}
	}

	return $where . ')';
}

function photovault_get_filtered_media( $request ) {
	if ( function_exists( 'photovault_rate_limit' ) && ! photovault_rate_limit( 'media_filter', 90, 60 ) ) {
		return new WP_Error( 'too_many_requests', 'Trop de requetes. Veuillez patienter.', array( 'status' => 429 ) );
	}

	$params = $request->get_params();
	$args = array(
		'post_type'      => 'media_item',
		'posts_per_page' => 12,
		'paged'          => ! empty( $params['page'] ) ? intval( $params['page'] ) : 1,
	);

	if ( photovault_current_user_can( 'photovault_manage_media' ) || is_user_logged_in() ) {
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

	$visibility_filter = null;
	if ( ! photovault_current_user_can( 'photovault_manage_media' ) ) {
		$user_id          = get_current_user_id();
		$visibility_filter = static function ( $where, $query ) use ( $user_id ) {
			if ( 'media_item' !== $query->get( 'post_type' ) ) {
				return $where;
			}

			return photovault_restrict_media_query_where( $where, $user_id );
		};
		add_filter( 'posts_where', $visibility_filter, 10, 2 );
	}

	try {
		$query = new WP_Query( $args );
	} finally {
		if ( $visibility_filter ) {
			remove_filter( 'posts_where', $visibility_filter, 10 );
		}
	}
	$results = array();

	if ( $query->have_posts() ) {
		while ( $query->have_posts() ) {
			$query->the_post();

			if ( 'private' === get_post_status() && function_exists( 'photovault_user_can_access_media' ) && ! photovault_user_can_access_media( get_the_ID(), get_current_user_id() ) ) {
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
	$media_id = intval( $request->get_param( 'id' ) );

	if ( function_exists( 'photovault_rate_limit' ) && ! photovault_rate_limit( 'secure_image', 240, 60 ) ) {
		if ( function_exists( 'photovault_log_media_event' ) ) {
			photovault_log_media_event( 'secure_image_rate_limited', 'warning', $media_id, array( 'display' => sanitize_key( $request->get_param( 'display' ) ) ) );
		}

		return new WP_Error( 'too_many_requests', 'Trop de requetes. Veuillez patienter.', array( 'status' => 429 ) );
	}

	if ( 0 === get_current_user_id() ) {
		$cookie_user_id = wp_validate_auth_cookie( '', 'logged_in' );
		if ( $cookie_user_id ) {
			wp_set_current_user( $cookie_user_id );
		}
	}

	$post = get_post( $media_id );
	if ( ! $post || 'media_item' !== $post->post_type ) {
		if ( function_exists( 'photovault_log_media_event' ) ) {
			photovault_log_media_event( 'media_not_found', 'warning', $media_id );
		}

		return new WP_Error( 'not_found', 'Media introuvable.', array( 'status' => 404 ) );
	}

	$is_private = 'private' === $post->post_status;
	$is_admin = photovault_current_user_can( 'photovault_manage_media' );
	$is_owner = is_user_logged_in() && (int) $post->post_author === get_current_user_id();

	if ( $is_private && function_exists( 'photovault_user_can_access_media' ) && ! photovault_user_can_access_media( $media_id, get_current_user_id() ) ) {
		if ( function_exists( 'photovault_log_media_event' ) ) {
			photovault_log_media_event( 'access_denied', 'warning', $media_id, array( 'reason' => 'private_media', 'display' => sanitize_key( $request->get_param( 'display' ) ) ) );
		}

		return new WP_Error( 'not_found', 'Media introuvable.', array( 'status' => 404 ) );
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
		$nonce = sanitize_text_field( (string) $request->get_param( '_wpnonce' ) );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			if ( function_exists( 'photovault_log_media_event' ) ) {
				photovault_log_media_event( 'access_denied', 'warning', $media_id, array( 'reason' => 'invalid_download_nonce' ) );
			}

			return new WP_Error( 'forbidden', 'Lien de telechargement invalide.', array( 'status' => 403 ) );
		}

		if ( ! is_user_logged_in() ) {
			if ( function_exists( 'photovault_log_media_event' ) ) {
				photovault_log_media_event( 'access_denied', 'warning', $media_id, array( 'reason' => 'download_requires_login' ) );
			}

			return new WP_Error( 'unauthorized', 'Vous devez etre connecte pour telecharger des medias.', array( 'status' => 401 ) );
		}

		if ( ! $is_admin && ! photovault_user_has_verified_identity( get_current_user_id() ) ) {
			if ( function_exists( 'photovault_log_media_event' ) ) {
				photovault_log_media_event( 'access_denied', 'warning', $media_id, array( 'reason' => 'email_unverified_download' ) );
			}

			return new WP_Error( 'email_unverified', 'Verifiez votre adresse e-mail avant de telecharger un original.', array( 'status' => 403 ) );
		}

		if ( $is_protected && ! $is_admin && ! $is_owner ) {
			if ( function_exists( 'photovault_log_media_event' ) ) {
				photovault_log_media_event( 'access_denied', 'warning', $media_id, array( 'reason' => 'protected_download_forbidden' ) );
			}

			return new WP_Error( 'forbidden', 'Telechargement interdit sur un media protege.', array( 'status' => 403 ) );
		}

		$downloads = (int) get_post_meta( $media_id, 'photovault_downloads_count', true );
		update_post_meta( $media_id, 'photovault_downloads_count', $downloads + 1 );

		if ( function_exists( 'photovault_log_media_event' ) ) {
			photovault_log_media_event( 'media_download', 'success', $media_id, array( 'protected' => $is_protected, 'owner' => $is_owner, 'admin' => $is_admin ) );
		}

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

	if ( function_exists( 'photovault_log_media_event' ) ) {
		photovault_log_media_event( $is_protected && ! $is_admin && ! $is_owner ? 'protected_preview_served' : 'media_preview_served', 'info', $media_id, array( 'display' => $display, 'protected' => $is_protected ) );
	}

	if ( $is_protected && ! $is_admin && ! $is_owner ) {
		$watermark_settings = photovault_get_watermark_settings();
		$cache_path         = photovault_get_protected_preview_cache_path( $media_id, $thumb_id, $display, $filepath, $mime, $watermark_settings );

		if ( $cache_path && file_exists( $cache_path ) ) {
			photovault_serve_file_response( $cache_path, $mime );
		}

		if ( $cache_path && photovault_render_protected_preview_to_cache( $filepath, $cache_path, $mime, $watermark_settings ) ) {
			photovault_serve_file_response( $cache_path, $mime );
		}
	}

	photovault_serve_file_response( $filepath, $mime );
}
