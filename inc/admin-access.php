<?php
/**
 * Admin access and download workspace for PhotoVault Core.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the access/downloads submenu.
 */
function photovault_register_access_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=media_item',
		__( 'Acces et telechargements', 'photovault' ),
		__( 'Acces & downloads', 'photovault' ),
		'photovault_manage_media',
		'photovault-access-downloads',
		'photovault_render_access_downloads_page'
	);
}
add_action( 'admin_menu', 'photovault_register_access_admin_menu' );

/**
 * Return media IDs for admin reporting.
 *
 * @param array<string,mixed> $args Query args.
 * @return int[]
 */
function photovault_get_admin_media_ids( $args = array() ) {
	$defaults = array(
		'posts_per_page' => 50,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'meta_query'     => array(),
	);
	$args = wp_parse_args( $args, $defaults );

	$query_args = array(
		'post_type'      => 'media_item',
		'post_status'    => array( 'publish', 'private' ),
		'posts_per_page' => max( 1, min( 100, absint( $args['posts_per_page'] ) ) ),
		'orderby'        => sanitize_key( $args['orderby'] ),
		'order'          => 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC',
		'fields'         => 'ids',
	);

	if ( ! empty( $args['meta_query'] ) && is_array( $args['meta_query'] ) ) {
		$query_args['meta_query'] = $args['meta_query'];
	}

	$query = new WP_Query( $query_args );

	return array_map( 'absint', $query->posts );
}

/**
 * Count media by access class.
 *
 * @return array<string,int>
 */
function photovault_get_access_report_counts() {
	$stats = function_exists( 'photovault_get_photographer_stats' ) ? photovault_get_photographer_stats( 0 ) : array();

	return array(
		'total'     => isset( $stats['total'] ) ? (int) $stats['total'] : 0,
		'public'    => isset( $stats['public'] ) ? (int) $stats['public'] : 0,
		'private'   => isset( $stats['private'] ) ? (int) $stats['private'] : 0,
		'protected' => isset( $stats['protected'] ) ? (int) $stats['protected'] : 0,
		'downloads' => isset( $stats['downloads'] ) ? (int) $stats['downloads'] : 0,
	);
}

/**
 * Render a compact card.
 *
 * @param string $label Card label.
 * @param int    $value Card value.
 * @param string $note  Supporting note.
 */
function photovault_render_access_count_card( $label, $value, $note ) {
	?>
	<div class="pv-access-card">
		<span><?php echo esc_html( $label ); ?></span>
		<strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong>
		<em><?php echo esc_html( $note ); ?></em>
	</div>
	<?php
}

/**
 * Render the access and downloads admin page.
 */
function photovault_render_access_downloads_page() {
	if ( ! photovault_current_user_can( 'photovault_manage_media' ) ) {
		wp_die( esc_html__( 'Vous ne pouvez pas gerer les acces PhotoVault.', 'photovault' ) );
	}

	$counts = photovault_get_access_report_counts();
	$recent_sensitive_ids = photovault_get_admin_media_ids(
		array(
			'posts_per_page' => 25,
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'   => 'is_protected',
					'value' => '1',
				),
				array(
					'key'     => 'photovault_downloads_count',
					'value'   => 0,
					'type'    => 'NUMERIC',
					'compare' => '>',
				),
			),
		)
	);
	?>
	<div class="wrap photovault-access-admin">
		<h1><?php esc_html_e( 'Acces et telechargements', 'photovault' ); ?></h1>
		<p><?php esc_html_e( 'Vue operationnelle des medias publics, proteges, prives et des telechargements servis par PhotoVault Core.', 'photovault' ); ?></p>

		<div class="pv-access-grid">
			<?php photovault_render_access_count_card( __( 'Total medias', 'photovault' ), $counts['total'], __( 'Publics + prives', 'photovault' ) ); ?>
			<?php photovault_render_access_count_card( __( 'Publics', 'photovault' ), $counts['public'], __( 'Visibles dans la galerie', 'photovault' ) ); ?>
			<?php photovault_render_access_count_card( __( 'Prives', 'photovault' ), $counts['private'], __( 'Admin/proprietaire uniquement', 'photovault' ) ); ?>
			<?php photovault_render_access_count_card( __( 'Proteges', 'photovault' ), $counts['protected'], __( 'Apercu filigrane', 'photovault' ) ); ?>
			<?php photovault_render_access_count_card( __( 'Telechargements', 'photovault' ), $counts['downloads'], __( 'Originaux servis par endpoint', 'photovault' ) ); ?>
		</div>

		<section class="pv-access-panel">
			<h2><?php esc_html_e( 'Politique appliquee', 'photovault' ); ?></h2>
			<ul class="pv-access-policy">
				<li><?php esc_html_e( 'Les listings REST exigent un utilisateur connecte et filtrent les medias prives hors admin/proprietaire.', 'photovault' ); ?></li>
				<li><?php esc_html_e( 'Les apercus utilisent des variantes thumbnail/preview et non les originaux HD.', 'photovault' ); ?></li>
				<li><?php esc_html_e( 'Les telechargements exigent un nonce REST, une session connectee et la permission adequate.', 'photovault' ); ?></li>
				<li><?php esc_html_e( 'Les medias proteges non autorises sont servis avec filigrane et sans telechargement original.', 'photovault' ); ?></li>
			</ul>
		</section>

		<section class="pv-access-panel">
			<h2><?php esc_html_e( 'Medias sensibles ou telecharges recemment', 'photovault' ); ?></h2>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Media', 'photovault' ); ?></th>
						<th><?php esc_html_e( 'Statut', 'photovault' ); ?></th>
						<th><?php esc_html_e( 'Protege', 'photovault' ); ?></th>
						<th><?php esc_html_e( 'Telechargements', 'photovault' ); ?></th>
						<th><?php esc_html_e( 'Auteur', 'photovault' ); ?></th>
						<th><?php esc_html_e( 'Action', 'photovault' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $recent_sensitive_ids ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'Aucun media protege ou telecharge pour le moment.', 'photovault' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $recent_sensitive_ids as $media_id ) : ?>
							<?php
							$post      = get_post( $media_id );
							$downloads = (int) get_post_meta( $media_id, 'photovault_downloads_count', true );
							$protected = '1' === get_post_meta( $media_id, 'is_protected', true );
							?>
							<tr>
								<td><strong><?php echo esc_html( get_the_title( $media_id ) ); ?></strong></td>
								<td><code><?php echo esc_html( get_post_status( $media_id ) ); ?></code></td>
								<td><?php echo $protected ? esc_html__( 'Oui', 'photovault' ) : esc_html__( 'Non', 'photovault' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $downloads ) ); ?></td>
								<td><?php echo $post ? esc_html( get_the_author_meta( 'display_name', $post->post_author ) ) : ''; ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $media_id ) ); ?>"><?php esc_html_e( 'Editer', 'photovault' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</section>
	</div>
	<style>
		.photovault-access-admin .pv-access-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin:18px 0}.photovault-access-admin .pv-access-card,.photovault-access-admin .pv-access-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}.photovault-access-admin .pv-access-card span{display:block;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.08em}.photovault-access-admin .pv-access-card strong{display:block;margin-top:8px;font-size:28px}.photovault-access-admin .pv-access-card em{display:block;margin-top:4px;color:#646970;font-style:normal}.photovault-access-admin .pv-access-panel{margin-top:16px}.photovault-access-admin .pv-access-policy{list-style:disc;margin-left:20px}@media(max-width:1100px){.photovault-access-admin .pv-access-grid{grid-template-columns:1fr 1fr}}@media(max-width:640px){.photovault-access-admin .pv-access-grid{grid-template-columns:1fr}}
	</style>
	<?php
}