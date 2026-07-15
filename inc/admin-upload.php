<?php
/**
 * Media import workspace.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function photovault_register_media_import_menu() {
	add_submenu_page(
		'edit.php?post_type=media_item',
		__( 'Importer des images', 'photovault' ),
		__( 'Importer', 'photovault' ),
		'photovault_manage_media',
		'photovault-import',
		'photovault_render_media_import_page'
	);
}
add_action( 'admin_menu', 'photovault_register_media_import_menu' );

function photovault_enqueue_media_import_assets( $hook ) {
	if ( 'media_item_page_photovault-import' !== $hook ) {
		return;
	}
	wp_enqueue_style( 'photovault-admin-upload', PHOTOVAULT_CORE_URI . 'assets/admin-upload.css', array(), PHOTOVAULT_CORE_VERSION );
	wp_enqueue_script( 'photovault-admin-upload', PHOTOVAULT_CORE_URI . 'assets/admin-upload.js', array(), PHOTOVAULT_CORE_VERSION, true );
	wp_add_inline_script(
		'photovault-admin-upload',
		'window.PhotoVaultUpload = ' . wp_json_encode(
			array(
				'uploadUrl' => rest_url( 'photovault/v1/media/upload' ),
				'mediaUrl'  => rest_url( 'photovault/v1/media' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'maxFiles'  => (int) apply_filters( 'photovault_max_upload_files', 20 ),
				'maxBytes'  => (int) apply_filters( 'photovault_max_upload_bytes', 30 * 1024 * 1024 ),
			)
		) . ';',
		'before'
	);
}
add_action( 'admin_enqueue_scripts', 'photovault_enqueue_media_import_assets' );

function photovault_render_media_import_page() {
	if ( ! photovault_current_user_can( 'photovault_manage_media' ) || ! current_user_can( 'upload_files' ) ) {
		wp_die( esc_html__( 'Vous ne pouvez pas importer de medias.', 'photovault' ) );
	}
	$folders    = get_terms( array( 'taxonomy' => 'media_folder', 'hide_empty' => false ) );
	$categories = get_terms( array( 'taxonomy' => 'media_category', 'hide_empty' => false ) );
	?>
	<div class="wrap photovault-import"><header class="pv-import-header"><div><h1><?php esc_html_e( 'Importer des images', 'photovault' ); ?></h1><p><?php esc_html_e( 'Chaque fichier est controle, transfere individuellement puis modifiable sans quitter cet espace.', 'photovault' ); ?></p></div><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=media_item' ) ); ?>"><?php esc_html_e( 'Voir tous les medias', 'photovault' ); ?></a></header>
	<section class="pv-import-panel"><form id="pv-upload-form"><div class="pv-dropzone"><input id="pv-media-files" name="media_files" type="file" accept="image/jpeg,image/png,image/webp" multiple required><label for="pv-media-files"><strong><?php esc_html_e( 'Choisir des images', 'photovault' ); ?></strong><span><?php esc_html_e( 'JPG, PNG ou WebP. Les originaux sensibles seront places dans le stockage prive.', 'photovault' ); ?></span></label></div><div class="pv-upload-defaults"><label><?php esc_html_e( 'Confidentialite initiale', 'photovault' ); ?><select id="pv-default-visibility"><option value="private"><?php esc_html_e( 'Prive', 'photovault' ); ?></option><option value="publish"><?php esc_html_e( 'Public', 'photovault' ); ?></option></select></label><label class="pv-checkbox"><input id="pv-default-protected" type="checkbox" checked> <?php esc_html_e( 'Proteger et filigraner', 'photovault' ); ?></label><button class="button button-primary" type="submit"><?php esc_html_e( 'Demarrer l import', 'photovault' ); ?></button></div></form></section>
	<div id="pv-upload-summary" class="pv-upload-summary" aria-live="polite"></div><ol id="pv-upload-list" class="pv-upload-list"></ol>
	<template id="pv-media-editor-template"><form class="pv-media-editor"><div class="pv-editor-preview"><img src="" alt=""></div><div class="pv-editor-fields"><label><?php esc_html_e( 'Titre', 'photovault' ); ?><input name="title" maxlength="160" required></label><label class="pv-wide"><?php esc_html_e( 'Description', 'photovault' ); ?><textarea name="description" maxlength="5000" rows="3"></textarea></label><label><?php esc_html_e( 'Collection', 'photovault' ); ?><select name="folder"><option value="0"><?php esc_html_e( 'Aucune', 'photovault' ); ?></option><?php foreach ( is_wp_error( $folders ) ? array() : $folders as $folder ) : ?><option value="<?php echo esc_attr( $folder->term_id ); ?>"><?php echo esc_html( $folder->name ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Categorie', 'photovault' ); ?><select name="category"><option value="0"><?php esc_html_e( 'Aucune', 'photovault' ); ?></option><?php foreach ( is_wp_error( $categories ) ? array() : $categories as $category ) : ?><option value="<?php echo esc_attr( $category->term_id ); ?>"><?php echo esc_html( $category->name ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Confidentialite', 'photovault' ); ?><select name="visibility"><option value="private"><?php esc_html_e( 'Prive', 'photovault' ); ?></option><option value="publish"><?php esc_html_e( 'Public', 'photovault' ); ?></option></select></label><label><?php esc_html_e( 'Tags', 'photovault' ); ?><input name="tags" maxlength="420" placeholder="portrait, cotonou, exposition"></label><label class="pv-checkbox pv-wide"><input name="is_protected" type="checkbox"> <?php esc_html_e( 'Media protege et filigrane', 'photovault' ); ?></label><div class="pv-editor-actions pv-wide"><span class="pv-editor-status" role="status"></span><button class="button button-primary" type="submit"><?php esc_html_e( 'Enregistrer les metadonnees', 'photovault' ); ?></button><a class="button pv-full-edit" href=""><?php esc_html_e( 'Edition complete', 'photovault' ); ?></a></div></div></form></template>
	</div>
	<?php
}
