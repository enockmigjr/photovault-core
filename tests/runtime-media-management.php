<?php
/**
 * WordPress runtime verification for media metadata and import permissions.
 *
 * Run with: wp eval-file tests/runtime-media-management.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function photovault_media_management_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$suffix           = strtolower( wp_generate_password( 8, false, false ) );
$previous_user_id = get_current_user_id();
$user_ids         = array();
$media_ids        = array();
$term_ids         = array();

try {
	photovault_core_activate();
	photovault_register_post_types();
	photovault_register_taxonomies();

	foreach ( array( 'owner', 'other' ) as $label ) {
		$user_id = wp_create_user( 'pv_media_' . $label . '_' . $suffix, wp_generate_password( 24 ), 'pv-media-' . $label . '-' . $suffix . '@photovault.test' );
		photovault_media_management_runtime_assert( ! is_wp_error( $user_id ), 'Runtime user creation failed.' );
		$user_ids[] = (int) $user_id;
	}

	$folder = wp_insert_term( 'Runtime Collection ' . $suffix, 'media_folder' );
	$category = wp_insert_term( 'Runtime Category ' . $suffix, 'media_category' );
	photovault_media_management_runtime_assert( ! is_wp_error( $folder ) && ! is_wp_error( $category ), 'Runtime taxonomy creation failed.' );
	$term_ids = array( (int) $folder['term_id'], (int) $category['term_id'] );

	$media_id = wp_insert_post(
		array(
			'post_type'   => 'media_item',
			'post_status' => 'publish',
			'post_title'  => 'Runtime Original',
			'post_author' => $user_ids[0],
		),
		true
	);
	photovault_media_management_runtime_assert( ! is_wp_error( $media_id ), 'Runtime media creation failed.' );
	$media_ids[] = (int) $media_id;

	$defaults = photovault_normalize_media_metadata( array() );
	photovault_media_management_runtime_assert( 'private' === $defaults['visibility'] && '1' === $defaults['is_protected'], 'Import defaults are not private and protected.' );

	wp_set_current_user( $user_ids[0] );
	$result = photovault_apply_media_metadata(
		$media_id,
		array(
			'title'        => 'Runtime Edited',
			'description'  => 'Description runtime controlee.',
			'folder'       => $folder['term_id'],
			'category'     => $category['term_id'],
			'visibility'   => 'publish',
			'is_protected' => false,
			'tags'         => 'runtime-a-' . $suffix . ', runtime-b-' . $suffix . ', runtime-a-' . $suffix,
		)
	);
	photovault_media_management_runtime_assert( is_array( $result ), 'Owner metadata update failed.' );
	photovault_media_management_runtime_assert( 'Runtime Edited' === get_the_title( $media_id ), 'Media title was not updated.' );
	photovault_media_management_runtime_assert( 'Description runtime controlee.' === get_post_field( 'post_content', $media_id ), 'Media description was not updated.' );
	photovault_media_management_runtime_assert( array( (int) $folder['term_id'] ) === wp_get_post_terms( $media_id, 'media_folder', array( 'fields' => 'ids' ) ), 'Collection assignment failed.' );
	photovault_media_management_runtime_assert( array( (int) $category['term_id'] ) === wp_get_post_terms( $media_id, 'media_category', array( 'fields' => 'ids' ) ), 'Category assignment failed.' );
	$tag_ids = wp_get_post_terms( $media_id, 'media_tag', array( 'fields' => 'ids' ) );
	photovault_media_management_runtime_assert( 2 === count( $tag_ids ), 'Tag normalization failed.' );
	$term_ids = array_merge( $term_ids, array_map( 'intval', $tag_ids ) );

	$invalid = photovault_apply_media_metadata( $media_id, array( 'folder' => 99999999 ), $user_ids[0] );
	photovault_media_management_runtime_assert( is_wp_error( $invalid ) && 'invalid_media_folder' === $invalid->get_error_code(), 'Invalid collection was accepted.' );

	wp_set_current_user( $user_ids[1] );
	$forbidden = photovault_apply_media_metadata( $media_id, array( 'title' => 'Cross account edit' ), $user_ids[1] );
	photovault_media_management_runtime_assert( is_wp_error( $forbidden ) && 'media_edit_forbidden' === $forbidden->get_error_code(), 'Cross-account metadata edit was accepted.' );
	photovault_media_management_runtime_assert( ! photovault_rest_upload_media_permission(), 'Client received media import permission.' );

	$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	photovault_media_management_runtime_assert( ! empty( $administrators ), 'Administrator required for import verification.' );
	wp_set_current_user( (int) $administrators[0] );
	photovault_media_management_runtime_assert( photovault_rest_upload_media_permission(), 'Administrator cannot use the media importer.' );
	$admin_result = photovault_apply_media_metadata( $media_id, array( 'title' => 'Runtime Admin Edit', 'visibility' => 'publish', 'is_protected' => false ) );
	photovault_media_management_runtime_assert( is_array( $admin_result ) && 'Runtime Admin Edit' === $admin_result['title'], 'Administrator could not edit another owner media.' );

	ob_start();
	photovault_render_media_import_page();
	$admin_html = ob_get_clean();
	photovault_media_management_runtime_assert( false !== strpos( $admin_html, 'pv-upload-form' ) && false !== strpos( $admin_html, 'pv-media-editor-template' ), 'Media import workspace did not render.' );

	echo wp_json_encode(
		array(
			'private_defaults'    => true,
			'metadata_editing'    => true,
			'taxonomies_and_tags' => true,
			'ownership_isolation' => true,
			'admin_import_access' => true,
			'admin_workspace'     => true,
		)
	);
} finally {
	wp_set_current_user( $previous_user_id );
	foreach ( $media_ids as $media_id ) {
		wp_delete_post( $media_id, true );
	}
	if ( isset( $term_ids[0] ) ) {
		wp_delete_term( $term_ids[0], 'media_folder' );
	}
	if ( isset( $term_ids[1] ) ) {
		wp_delete_term( $term_ids[1], 'media_category' );
	}
	foreach ( array_slice( $term_ids, 2 ) as $tag_id ) {
		wp_delete_term( $tag_id, 'media_tag' );
	}
	foreach ( $user_ids as $user_id ) {
		wp_delete_user( $user_id );
	}
}
