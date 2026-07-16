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
	 * Seed a rich, idempotent demonstration dataset across active PhotoVault modules.
	 *
	 * ## EXAMPLES
	 *
	 *     wp photovault seed_demo
	 */
	public function seed_demo() {
		global $wpdb;
		if ( get_option( 'photovault_demo_seed_completed' ) ) {
			if ( (int) get_option( 'photovault_demo_seed_version', 1 ) < 2 ) {
				$grant_count = $this->seed_demo_access_grants();
				update_option( 'photovault_demo_seed_version', 2, false );
				WP_CLI::success( sprintf( 'Upgraded the demonstration dataset with %d collection access grants.', $grant_count ) );
				return;
			}

			WP_CLI::success( 'The PhotoVault demonstration dataset is already installed.' );
			return;
		}

		$author_id   = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
		$attachments = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => 24, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ) );
		if ( empty( $attachments ) ) {
			WP_CLI::error( 'At least one local image attachment is required before seeding the gallery.' );
		}
		$now         = current_time( 'mysql', true );
		$collections = array( 'Fragments urbains', 'Presences', 'Apres la pluie', 'Memoire silencieuse', 'Territoires', 'Mouvements' );
		$categories  = array( 'Portrait', 'Architecture', 'Documentaire', 'Patrimoine', 'Paysage', 'Abstraction' );
		$folder_ids  = array();
		$category_ids = array();
		foreach ( $collections as $name ) {
			$term = wp_insert_term( $name, 'media_folder', array( 'slug' => 'demo-' . sanitize_title( $name ) ) );
			$folder_ids[] = is_wp_error( $term ) ? (int) get_term_by( 'slug', 'demo-' . sanitize_title( $name ), 'media_folder' )->term_id : (int) $term['term_id'];
		}
		foreach ( $categories as $name ) {
			$term = wp_insert_term( $name, 'media_category', array( 'slug' => 'demo-' . sanitize_title( $name ) ) );
			$category_ids[] = is_wp_error( $term ) ? (int) get_term_by( 'slug', 'demo-' . sanitize_title( $name ), 'media_category' )->term_id : (int) $term['term_id'];
		}

		$media_ids = array();
		for ( $index = 1; $index <= 48; $index++ ) {
			$media_id = wp_insert_post(
				array(
					'post_type'    => 'media_item',
					'post_status'  => $index % 4 ? 'publish' : 'private',
					'post_author'  => $author_id,
					'post_title'   => sprintf( 'Archive %02d - %s', $index, $collections[ ( $index - 1 ) % count( $collections ) ] ),
					'post_content' => sprintf( 'Etude photographique %02d consacree a la lumiere, au territoire et a la memoire des lieux.', $index ),
				),
				true
			);
			if ( is_wp_error( $media_id ) ) {
				continue;
			}
			$media_ids[] = (int) $media_id;
			set_post_thumbnail( $media_id, $attachments[ ( $index - 1 ) % count( $attachments ) ] );
			wp_set_object_terms( $media_id, $folder_ids[ ( $index - 1 ) % count( $folder_ids ) ], 'media_folder' );
			wp_set_object_terms( $media_id, $category_ids[ ( $index - 1 ) % count( $category_ids ) ], 'media_category' );
			wp_set_object_terms( $media_id, array( 'demo', 'archive-' . ( 2022 + ( $index % 5 ) ) ), 'media_tag' );
			update_post_meta( $media_id, 'is_protected', 0 === $index % 3 ? '1' : '0' );
			update_post_meta( $media_id, 'photovault_views_count', 40 + ( $index * 17 ) );
			update_post_meta( $media_id, 'photovault_downloads_count', $index % 11 );
			update_post_meta( $media_id, '_photovault_demo_seed', '1' );
		}

		for ( $index = 1; $index <= 12; $index++ ) {
			$post_id = wp_insert_post(
				array(
					'post_type'    => 'post',
					'post_status'  => 'publish',
					'post_author'  => $author_id,
					'post_title'   => sprintf( 'Carnet %02d - La lumiere et la memoire', $index ),
					'post_excerpt' => 'Notes de terrain, choix de lumiere et construction d une archive visuelle.',
					'post_content' => '<p>Chaque serie commence par une marche, une attente et une observation attentive du territoire.</p><p>Ce carnet partage les intentions, les rencontres et les decisions qui ont faconne les images.</p>',
				),
				true
			);
			if ( ! is_wp_error( $post_id ) ) {
				set_post_thumbnail( $post_id, $attachments[ ( $index + 3 ) % count( $attachments ) ] );
				update_post_meta( $post_id, '_photovault_demo_seed', '1' );
			}
		}

		$user_ids = array();
		for ( $index = 1; $index <= 15; $index++ ) {
			$email = sprintf( 'demo.visitor%02d@photovault.test', $index );
			$user  = get_user_by( 'email', $email );
			$user_id = $user ? $user->ID : wp_create_user( 'pv_demo_' . sprintf( '%02d', $index ), wp_generate_password( 32, true, true ), $email );
			if ( is_wp_error( $user_id ) ) {
				continue;
			}
			$user_ids[] = (int) $user_id;
			wp_update_user( array( 'ID' => $user_id, 'display_name' => sprintf( 'Visiteur PhotoVault %02d', $index ), 'role' => 'subscriber' ) );
			update_user_meta( $user_id, '_photovault_demo_seed', '1' );
			update_user_meta( $user_id, 'identity_email_verified', '1' );
		}

		$statuses = array( 'pending', 'approved', 'rejected' );
		for ( $index = 1; $index <= 45; $index++ ) {
			$user_id = $user_ids[ ( $index - 1 ) % count( $user_ids ) ];
			$user     = get_userdata( $user_id );
			$wpdb->insert(
				photovault_get_access_requests_table(),
				array( 'user_id' => $user_id, 'name' => $user->display_name, 'email' => $user->user_email, 'subject' => 'Consultation editoriale', 'collection' => $collections[ ( $index - 1 ) % count( $collections ) ], 'message' => 'Je souhaite consulter cette serie dans le cadre d une recherche et mieux comprendre sa demarche.', 'status' => $statuses[ $index % 3 ], 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $index * HOUR_IN_SECONDS ), 'updated_at' => $now ),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
		$this->seed_demo_access_grants();

		$shooting_types = array_keys( photovault_get_shooting_types() );
		$shooting_statuses = array_keys( photovault_get_shooting_statuses() );
		for ( $index = 1; $index <= 36; $index++ ) {
			$user_id = $user_ids[ ( $index - 1 ) % count( $user_ids ) ];
			$user     = get_userdata( $user_id );
			$shooting_id = wp_insert_post( array( 'post_type' => 'photovault_shooting', 'post_status' => 'private', 'post_author' => $user_id, 'post_title' => 'Shooting demo ' . sprintf( '%02d', $index ) ), true );
			if ( is_wp_error( $shooting_id ) ) {
				continue;
			}
			$meta = array(
				'_photovault_shooting_type' => $shooting_types[ $index % count( $shooting_types ) ],
				'_photovault_shooting_date' => gmdate( 'Y-m-d', strtotime( '+' . ( 7 + $index ) . ' days' ) ),
				'_photovault_shooting_location' => 0 === $index % 2 ? 'Cotonou' : 'Porto-Novo',
				'_photovault_shooting_message' => 'Creer une serie naturelle, sobre et attentive aux gestes et a la lumiere disponible.',
				'_photovault_shooting_contact_name' => $user->display_name,
				'_photovault_shooting_contact_email' => $user->user_email,
				'_photovault_shooting_contact_phone' => '+229010000' . sprintf( '%04d', $index ),
				'_photovault_shooting_status' => $shooting_statuses[ $index % count( $shooting_statuses ) ],
				'_photovault_shooting_updated_at' => $now,
				'_photovault_demo_seed' => '1',
			);
			foreach ( $meta as $key => $value ) {
				update_post_meta( $shooting_id, $key, $value );
			}
		}

		for ( $index = 1; $index <= 120; $index++ ) {
			photovault_log_media_event( array( 'media_preview', 'media_download', 'access_denied', 'thumbnail_generated' )[ $index % 4 ], array( 'info', 'success', 'warning' )[ $index % 3 ], $media_ids[ ( $index - 1 ) % count( $media_ids ) ], array( 'fixture' => 'demo', 'sequence' => $index, 'variant' => 0 === $index % 2 ? 'thumbnail' : 'preview' ) );
		}

		if ( function_exists( 'identity_security_kit_log_event' ) ) {
			for ( $index = 1; $index <= 60; $index++ ) {
				identity_security_kit_log_event( array( 'login_success', 'profile_updated', 'mfa_challenge', 'email_verified' )[ $index % 4 ], array( 'info', 'success', 'warning' )[ $index % 3 ], $user_ids[ ( $index - 1 ) % count( $user_ids ) ], array( 'fixture' => 'demo', 'sequence' => $index ) );
			}
		}

		if ( function_exists( 'newsletter_campaign_kit_subscribe_email' ) ) {
			$topic_ids = array();
			foreach ( array( 'Carnets visuels', 'Nouvelles collections', 'Expositions', 'Services photographiques' ) as $index => $name ) {
				$wpdb->insert( newsletter_campaign_kit_get_topics_table(), array( 'name' => $name, 'slug' => 'demo-topic-' . $index, 'description' => 'Selection editoriale PhotoVault.', 'color' => '#1f6f54', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
				$topic_ids[] = (int) $wpdb->insert_id;
			}
			$list_ids = array();
			foreach ( array( 'Lecteurs du journal', 'Clients studio', 'Collectionneurs' ) as $index => $name ) {
				$wpdb->insert( newsletter_campaign_kit_get_lists_table(), array( 'name' => $name, 'slug' => 'demo-list-' . $index, 'description' => 'Audience de demonstration PhotoVault.', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
				$list_ids[] = (int) $wpdb->insert_id;
			}
			for ( $index = 1; $index <= 80; $index++ ) {
				$email = sprintf( 'newsletter.demo%03d@photovault.test', $index );
				newsletter_campaign_kit_subscribe_email( $email, 'demo_seed', 'Je souhaite recevoir les lettres editoriales PhotoVault.' );
				$subscriber = newsletter_campaign_kit_get_subscriber_by_email( $email );
				newsletter_campaign_kit_assign_subscriber_to_list( $subscriber['id'], $list_ids[ $index % count( $list_ids ) ] );
				newsletter_campaign_kit_set_topic_preferences( $subscriber['id'], array( $topic_ids[ $index % count( $topic_ids ) ], $topic_ids[ ( $index + 1 ) % count( $topic_ids ) ] ) );
			}
			for ( $index = 1; $index <= 30; $index++ ) {
				newsletter_campaign_kit_create_campaign( array( 'title' => 'Lettre PhotoVault ' . sprintf( '%02d', $index ), 'subject' => 'Nouvelles des archives #' . $index, 'preview_text' => 'Une selection de nouvelles images et de notes de terrain.', 'html_body' => '<h1>Nouvelles des archives</h1><p>Une selection construite comme un recit visuel.</p>', 'text_body' => 'Nouvelles des archives. Une selection construite comme un recit visuel.', 'target_audience' => 'list:' . $list_ids[ $index % count( $list_ids ) ], 'topic_id' => $topic_ids[ $index % count( $topic_ids ) ] ), $author_id );
			}
		}

		update_option( 'photovault_demo_seed_completed', current_time( 'mysql', true ), false );
		update_option( 'photovault_demo_seed_version', 2, false );
		WP_CLI::success( sprintf( 'Seeded %d media, 12 articles, %d visitors, 45 access requests, 36 shootings and operational logs.', count( $media_ids ), count( $user_ids ) ) );
	}

	/**
	 * Grant the approved demo requests without touching real visitor requests.
	 *
	 * @return int Number of active demo grants resolved.
	 */
	private function seed_demo_access_grants() {
		global $wpdb;

		$requests_table = photovault_get_access_requests_table();
		$request_ids    = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal schema table; values remain prepared.
				"SELECT id FROM {$requests_table} WHERE email LIKE %s AND status = %s ORDER BY id ASC",
				'demo.visitor%@photovault.test',
				'approved'
			)
		);
		$grant_ids = array();
		foreach ( $request_ids as $request_id ) {
			$grant_id = photovault_create_access_grant_from_request( $request_id );
			if ( ! is_wp_error( $grant_id ) ) {
				$grant_ids[] = absint( $grant_id );
			}
		}

		return count( array_unique( $grant_ids ) );
	}

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
