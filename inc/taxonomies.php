<?php
/**
 * Taxonomies personnalisées du thème PhotoVault.
 *
 * @package PhotoVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enregistrement des taxonomies du thème.
 */
function photovault_register_taxonomies() {
	// Taxonomie 'media_folder' (Dossiers)
	$folder_labels = array(
		'name'              => _x( 'Dossiers', 'taxonomy general name', 'photovault' ),
		'singular_name'     => _x( 'Dossier', 'taxonomy singular name', 'photovault' ),
		'search_items'      => __( 'Rechercher des dossiers', 'photovault' ),
		'all_items'         => __( 'Tous les dossiers', 'photovault' ),
		'parent_item'       => __( 'Dossier parent', 'photovault' ),
		'parent_item_colon' => __( 'Dossier parent :', 'photovault' ),
		'edit_item'         => __( 'Modifier le dossier', 'photovault' ),
		'update_item'       => __( 'Mettre à jour le dossier', 'photovault' ),
		'add_new_item'      => __( 'Ajouter un nouveau dossier', 'photovault' ),
		'new_item_name'     => __( 'Nom du nouveau dossier', 'photovault' ),
		'menu_name'         => __( 'Dossiers', 'photovault' ),
	);

	$folder_args = array(
		'hierarchical'      => true,
		'labels'            => $folder_labels,
		'show_ui'           => true,
		'show_admin_column' => true,
		'query_var'         => true,
		'rewrite'           => array( 'slug' => 'folder' ),
		'show_in_rest'      => true, // Requis pour REST API et Gutenberg
	);

	register_taxonomy( 'media_folder', array( 'media_item' ), $folder_args );

	// Taxonomie 'media_category' (Catégories de photos)
	$category_labels = array(
		'name'              => _x( 'Catégories Média', 'taxonomy general name', 'photovault' ),
		'singular_name'     => _x( 'Catégorie Média', 'taxonomy singular name', 'photovault' ),
		'search_items'      => __( 'Rechercher des catégories', 'photovault' ),
		'all_items'         => __( 'Toutes les catégories', 'photovault' ),
		'parent_item'       => __( 'Catégorie parente', 'photovault' ),
		'parent_item_colon' => __( 'Catégorie parente :', 'photovault' ),
		'edit_item'         => __( 'Modifier la catégorie', 'photovault' ),
		'update_item'       => __( 'Mettre à jour la catégorie', 'photovault' ),
		'add_new_item'      => __( 'Ajouter une nouvelle catégorie', 'photovault' ),
		'new_item_name'     => __( 'Nom de la nouvelle catégorie', 'photovault' ),
		'menu_name'         => __( 'Catégories', 'photovault' ),
	);

	$category_args = array(
		'hierarchical'      => true,
		'labels'            => $category_labels,
		'show_ui'           => true,
		'show_admin_column' => true,
		'query_var'         => true,
		'rewrite'           => array( 'slug' => 'media-category' ),
		'show_in_rest'      => true,
	);

	register_taxonomy( 'media_category', array( 'media_item' ), $category_args );
}
add_action( 'init', 'photovault_register_taxonomies' );
