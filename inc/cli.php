<?php
/**
 * WP-CLI commands for PhotoVault Core maintenance.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * PhotoVault maintenance commands.
 */
class PhotoVault_Core_CLI_Command {
	/**
	 * Move sensitive media originals to private storage.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum number of media items to process. Defaults to 25, max 500.
	 *
	 * [--dry-run]
	 * : List media that would be processed without moving files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp photovault secure-originals --limit=100
	 *     wp photovault secure-originals --dry-run
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @param array<string,mixed> $assoc_args Associative arguments.
	 */
	public function secure_originals( $args, $assoc_args ) {
		$limit   = isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 25;
		$limit   = max( 1, min( 500, $limit ) );
		$dry_run = ! empty( $assoc_args['dry-run'] );

		if ( ! function_exists( 'photovault_get_unsecured_sensitive_media_ids' ) || ! function_exists( 'photovault_maybe_secure_media_original' ) ) {
			WP_CLI::error( 'PhotoVault private storage functions are unavailable.' );
		}

		$ids = photovault_get_unsecured_sensitive_media_ids( $limit );
		if ( empty( $ids ) ) {
			WP_CLI::success( 'No sensitive originals need migration.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( sprintf( '%d media item(s) would be migrated:', count( $ids ) ) );
			foreach ( $ids as $media_id ) {
				WP_CLI::log( sprintf( '- #%d %s', $media_id, get_the_title( $media_id ) ) );
			}
			return;
		}

		$secured = 0;
		$failed  = 0;
		foreach ( $ids as $media_id ) {
			$result = photovault_maybe_secure_media_original( $media_id );
			if ( is_wp_error( $result ) ) {
				$failed++;
				WP_CLI::warning( sprintf( '#%d failed: %s', $media_id, $result->get_error_code() ) );
				continue;
			}

			$secured++;
			WP_CLI::log( sprintf( '#%d secured.', $media_id ) );
		}

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d secured, %d failed.', $secured, $failed ) );
			return;
		}

		WP_CLI::success( sprintf( '%d sensitive original(s) secured.', $secured ) );
	}
}

WP_CLI::add_command( 'photovault', 'PhotoVault_Core_CLI_Command' );