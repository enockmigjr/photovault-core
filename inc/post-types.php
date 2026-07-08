<?php
/**
 * Custom Post Types du thème PhotoVault.
 *
 * @package PhotoVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enregistrement du Custom Post Type 'media_item'.
 */
function photovault_register_post_types() {
	$labels = array(
		'name'               => _x( 'Médias', 'post type general name', 'photovault' ),
		'singular_name'      => _x( 'Média', 'post type singular name', 'photovault' ),
		'menu_name'          => _x( 'PhotoVault', 'admin menu', 'photovault' ),
		'name_admin_bar'     => _x( 'Média', 'add new on admin bar', 'photovault' ),
		'add_new'            => _x( 'Ajouter Nouveau', 'media_item', 'photovault' ),
		'add_new_item'       => __( 'Ajouter un nouveau média', 'photovault' ),
		'new_item'           => __( 'Nouveau média', 'photovault' ),
		'edit_item'          => __( 'Modifier le média', 'photovault' ),
		'view_item'          => __( 'Voir le média', 'photovault' ),
		'all_items'          => __( 'Tous les médias', 'photovault' ),
		'search_items'       => __( 'Rechercher des médias', 'photovault' ),
		'parent_item_colon'  => __( 'Médias parents :', 'photovault' ),
		'not_found'          => __( 'Aucun média trouvé.', 'photovault' ),
		'not_found_in_trash' => __( 'Aucun média trouvé dans la corbeille.', 'photovault' )
	);

	$args = array(
		'labels'             => $labels,
		'public'             => true,
		'publicly_queryable' => true,
		'show_ui'            => true,
		'show_in_menu'       => true,
		'query_var'          => true,
		'rewrite'            => array( 'slug' => 'gallery', 'with_front' => false ),
		'has_archive'        => true,
		'hierarchical'       => false,
		'menu_position'      => 5,
		'menu_icon'          => 'dashicons-images-alt2',
		'show_in_rest'       => true, // Essentiel pour l'API REST
		'supports'           => array( 'title', 'editor', 'thumbnail', 'author' ),
	);

	register_post_type( 'media_item', $args );
}
add_action( 'init', 'photovault_register_post_types' );

/**
 * Ajouter les Metaboxes dans l'éditeur de media_item.
 */
function photovault_add_protection_metabox() {
	// Options de protection (sidebar)
	add_meta_box(
		'photovault_protection_meta',
		__( 'Options de protection', 'photovault' ),
		'photovault_protection_metabox_callback',
		'media_item',
		'side',
		'default'
	);

	// Metabox d'upload centrale pour l'image principale
	add_meta_box(
		'photovault_media_image_meta',
		__( 'Fichier Média (Image)', 'photovault' ),
		'photovault_media_image_metabox_callback',
		'media_item',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'photovault_add_protection_metabox' );

/**
 * Rendu de la Metabox de protection.
 */
function photovault_protection_metabox_callback( $post ) {
	wp_nonce_field( 'photovault_protection_save', 'photovault_protection_nonce' );
	$value = get_post_meta( $post->ID, 'is_protected', true );
	?>
	<p>
		<label for="photovault_is_protected">
			<input type="checkbox" name="photovault_is_protected" id="photovault_is_protected" value="1" <?php checked( $value, '1' ); ?>>
			<?php _e( '🔒 Activer la protection', 'photovault' ); ?>
		</label>
	</p>
	<p class="description">
		<?php _e( 'Empêche le clic droit, bloque le téléchargement direct pour les utilisateurs et ajoute un filigrane de sécurité.', 'photovault' ); ?>
	</p>
	<?php
}

/**
 * Rendu de la Metabox de sélection d'image principale.
 */
function photovault_media_image_metabox_callback( $post ) {
	wp_nonce_field( 'photovault_media_image_save', 'photovault_media_image_nonce' );
	$thumbnail_id = get_post_thumbnail_id( $post->ID );
	?>
	<div id="photovault-media-uploader-container" style="text-align: center; padding: 20px; border: 2px dashed #ccd0d4; border-radius: 4px; background: #fbfbfb; color: #2c3338;">
		<div id="photovault-media-preview" style="margin-bottom: 15px; display: flex; justify-content: center; align-items: center; min-height: 100px;">
			<?php if ( $thumbnail_id ) : ?>
				<?php echo wp_get_attachment_image( $thumbnail_id, 'medium', false, array( 'style' => 'max-width: 100%; max-height: 250px; height: auto; border-radius: 4px; border: 1px solid #ccd0d4;' ) ); ?>
			<?php else : ?>
				<p style="color: #646970; margin: 10px 0; font-size: 13px;"><?php _e( 'Aucune image sélectionnée. C\'est cette image qui sera affichée dans la galerie et protégée.', 'photovault' ); ?></p>
			<?php endif; ?>
		</div>
		<input type="hidden" name="photovault_thumbnail_id" id="photovault_thumbnail_id" value="<?php echo esc_attr( $thumbnail_id ); ?>">
		<button type="button" id="photovault-upload-btn" class="button button-primary"><?php _e( 'Sélectionner ou Téléverser une Image', 'photovault' ); ?></button>
		<button type="button" id="photovault-remove-btn" class="button" style="margin-left: 10px; color: #b32d2e; border-color: #b32d2e; display: <?php echo $thumbnail_id ? 'inline-block' : 'none'; ?>;"><?php _e( 'Supprimer', 'photovault' ); ?></button>
	</div>
	<script>
	jQuery(document).ready(function($){
		var mediaUploader;
		$('#photovault-upload-btn').click(function(e) {
			e.preventDefault();
			if (mediaUploader) {
				mediaUploader.open();
				return;
			}
			mediaUploader = wp.media({
				title: '<?php echo esc_js( __( 'Choisir l\'image principale du média', 'photovault' ) ); ?>',
				button: {
					text: '<?php echo esc_js( __( 'Utiliser cette image', 'photovault' ) ); ?>'
				},
				multiple: false
			});
			mediaUploader.on('select', function() {
				var attachment = mediaUploader.state().get('selection').first().toJSON();
				$('#photovault_thumbnail_id').val(attachment.id);
				var imgHtml = '<img src="' + attachment.url + '" style="max-width: 100%; max-height: 250px; height: auto; border-radius: 4px; border: 1px solid #ccd0d4;" />';
				$('#photovault-media-preview').html(imgHtml);
				$('#photovault-remove-btn').show();
			});
			mediaUploader.open();
		});
		$('#photovault-remove-btn').click(function(e) {
			e.preventDefault();
			$('#photovault_thumbnail_id').val('');
			$('#photovault-media-preview').html('<p style="color: #646970; margin: 10px 0; font-size: 13px;"><?php echo esc_js( __( 'Aucune image sélectionnée. C\'est cette image qui sera affichée dans la galerie et protégée.', 'photovault' ) ); ?></p>');
			$(this).hide();
		});
	});
	</script>
	<?php
}

/**
 * Enregistrer les images et options de protection à la sauvegarde du post.
 */
function photovault_save_protection_metabox( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// 1. Sauvegarde de la protection
	if ( isset( $_POST['photovault_protection_nonce'] ) && wp_verify_nonce( $_POST['photovault_protection_nonce'], 'photovault_protection_save' ) ) {
		$is_protected = isset( $_POST['photovault_is_protected'] ) ? '1' : '0';
		update_post_meta( $post_id, 'is_protected', $is_protected );
	}

	// 2. Sauvegarde de l'image principale
	if ( isset( $_POST['photovault_media_image_nonce'] ) && wp_verify_nonce( $_POST['photovault_media_image_nonce'], 'photovault_media_image_save' ) ) {
		if ( isset( $_POST['photovault_thumbnail_id'] ) ) {
			$thumbnail_id = sanitize_text_field( $_POST['photovault_thumbnail_id'] );
			if ( ! empty( $thumbnail_id ) ) {
				set_post_thumbnail( $post_id, intval( $thumbnail_id ) );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}
	}

	if ( function_exists( 'photovault_maybe_secure_media_original' ) ) {
		photovault_maybe_secure_media_original( $post_id );
	}
}
add_action( 'save_post', 'photovault_save_protection_metabox' );

/**
 * Charger les scripts du sélecteur de média WP dans l'éditeur de PhotoVault.
 */
function photovault_enqueue_admin_media_scripts( $hook ) {
	global $post_type;
	if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && 'media_item' === $post_type ) {
		wp_enqueue_media();
	}
}
add_action( 'admin_enqueue_scripts', 'photovault_enqueue_admin_media_scripts' );

/**
 * Corriger le warning PHP Deprecated de strip_tags(null) en pré-initialisant le titre.
 */
function photovault_fix_settings_page_title() {
	global $title;
	if ( isset( $_GET['page'] ) && 'photovault-settings' === $_GET['page'] ) {
		$title = __( 'Réglages PhotoVault', 'photovault' );
	}
}
add_action( 'admin_init', 'photovault_fix_settings_page_title' );

/**
 * Ajouter le sous-menu "Réglages" sous le menu PhotoVault dans wp-admin.
 */
function photovault_register_settings_menu() {
	add_submenu_page(
		'edit.php?post_type=media_item',
		__( 'Réglages PhotoVault', 'photovault' ),
		__( 'Réglages', 'photovault' ),
		'photovault_manage_settings',
		'photovault-settings',
		'photovault_render_settings_page'
	);
}
add_action( 'admin_menu', 'photovault_register_settings_menu' );

/**
 * Enregistrer l'option de filigrane.
 */
function photovault_register_settings_fields() {
	register_setting( 'photovault-settings-group', 'photovault_watermark_text' );
}
add_action( 'admin_init', 'photovault_register_settings_fields' );

/**
 * Rendu de la page de réglages.
 */
function photovault_render_settings_page() {
	global $title;
	$title = __( 'Réglages PhotoVault', 'photovault' );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( $title ); ?></h1>
		
		<form method="post" action="options.php">
			<?php settings_fields( 'photovault-settings-group' ); ?>
			<?php do_settings_sections( 'photovault-settings-group' ); ?>
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="photovault_watermark_text"><?php _e( 'Texte du filigrane', 'photovault' ); ?></label>
					</th>
					<td>
						<input type="text" id="photovault_watermark_text" name="photovault_watermark_text" value="<?php echo esc_attr( get_option( 'photovault_watermark_text', 'PHOTOVAULT' ) ); ?>" class="regular-text">
						<p class="description"><?php _e( 'Ce texte s\'affichera de manière répétée en diagonale sur les aperçus d\'images protégées.', 'photovault' ); ?></p>
					</td>
				</tr>
			</table>
			
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
