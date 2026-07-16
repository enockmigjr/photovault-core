<?php
/**
 * Shared admin presentation and interaction helpers.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function photovault_core_enqueue_admin_assets( $hook_suffix ) {
	$is_photovault = false !== strpos( (string) $hook_suffix, 'photovault' )
		|| ( isset( $_GET['post_type'] ) && 'media_item' === sanitize_key( wp_unslash( $_GET['post_type'] ) ) );
	if ( ! $is_photovault ) {
		return;
	}
	wp_enqueue_style( 'photovault-core-admin', PHOTOVAULT_CORE_URI . 'assets/admin.css', array(), PHOTOVAULT_CORE_VERSION );
	wp_enqueue_script( 'photovault-core-admin', PHOTOVAULT_CORE_URI . 'assets/admin.js', array(), PHOTOVAULT_CORE_VERSION, true );
}
add_action( 'admin_enqueue_scripts', 'photovault_core_enqueue_admin_assets', 5 );

function photovault_render_admin_pagination( $total, $per_page, $current_page, $base_url ) {
	$total_pages = max( 1, (int) ceil( absint( $total ) / max( 1, absint( $per_page ) ) ) );
	if ( $total_pages <= 1 ) {
		return;
	}
	$links = paginate_links(
		array(
			'base'      => add_query_arg( 'paged', '%#%', $base_url ),
			'format'    => '',
			'current'   => max( 1, absint( $current_page ) ),
			'total'     => $total_pages,
			'type'      => 'list',
			'prev_text' => __( 'Previous', 'photovault' ),
			'next_text' => __( 'Next', 'photovault' ),
		)
	);
	if ( $links ) {
		echo '<nav class="pv-admin-pagination" aria-label="' . esc_attr__( 'Pagination', 'photovault' ) . '">' . wp_kses_post( $links ) . '</nav>';
	}
}
