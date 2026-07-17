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
			$seed_version = (int) get_option( 'photovault_demo_seed_version', 1 );
			if ( $seed_version < 2 ) {
				$grant_count = $this->seed_demo_access_grants();
				update_option( 'photovault_demo_seed_version', 2, false );
				WP_CLI::success( sprintf( 'Upgraded the demonstration dataset with %d collection access grants.', $grant_count ) );
				return;
			}
			if ( $seed_version < 3 ) {
				$summary = $this->enrich_demo_content();
				update_option( 'photovault_demo_seed_version', 3, false );
				WP_CLI::success( sprintf( 'Enriched demo: %d images, %d users, %d articles and %d works updated.', $summary['images'], $summary['users'], $summary['posts'], $summary['media'] ) );
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

		$this->enrich_demo_content();
		update_option( 'photovault_demo_seed_completed', current_time( 'mysql', true ), false );
		update_option( 'photovault_demo_seed_version', 3, false );
		WP_CLI::success( sprintf( 'Seeded %d media, 12 articles, %d visitors, 45 access requests, 36 shootings and operational logs.', count( $media_ids ), count( $user_ids ) ) );
	}

	/**
	 * Replace generic fixtures with a credible editorial demonstration catalog.
	 *
	 * @return array{images:int,users:int,posts:int,media:int} Enrichment counters.
	 */
	private function enrich_demo_content() {
		$people = array(
			array( 'Aïcha Dossou', 'aicha.dossou', 'subscriber' ),
			array( 'Marius Ahouandjinou', 'marius.ahouandjinou', 'subscriber' ),
			array( 'Grâce Hounkpatin', 'grace.hounkpatin', 'subscriber' ),
			array( 'Sènami Kpodar', 'senami.kpodar', 'subscriber' ),
			array( 'Caleb Adjovi', 'caleb.adjovi', 'subscriber' ),
			array( 'Nadia Houessou', 'nadia.houessou', 'subscriber' ),
			array( 'Joël Agossou', 'joel.agossou', 'subscriber' ),
			array( 'Mireille Kora', 'mireille.kora', 'subscriber' ),
			array( 'Ulrich Zinsou', 'ulrich.zinsou', 'subscriber' ),
			array( 'Fadila Salami', 'fadila.salami', 'subscriber' ),
			array( 'David Tchibozo', 'david.tchibozo', 'subscriber' ),
			array( 'Rachelle Gandonou', 'rachelle.gandonou', 'subscriber' ),
			array( 'Serges Lokossou', 'serges.lokossou', 'subscriber' ),
			array( 'Prisca Kiki', 'prisca.kiki', 'subscriber' ),
			array( 'Romain Adéoti', 'romain.adeoti', 'subscriber' ),
			array( 'Inès Avocè', 'ines.avoce', 'editor' ),
			array( 'Landry Sossa', 'landry.sossa', 'editor' ),
			array( 'Mélissa Dovonou', 'melissa.dovonou', 'editor' ),
			array( 'Amour Houndété', 'amour.houndete', 'author' ),
			array( 'Ruth Kinninvo', 'ruth.kinninvo', 'author' ),
			array( 'Yannick Codjia', 'yannick.codjia', 'author' ),
			array( 'Ornella Gbaguidi', 'ornella.gbaguidi', 'author' ),
			array( 'Cédric Dansou', 'cedric.dansou', 'contributor' ),
			array( 'Léa Assogba', 'lea.assogba', 'contributor' ),
			array( 'Kevin Gnimadi', 'kevin.gnimadi', 'contributor' ),
		);
		$user_ids = array();
		foreach ( $people as $index => $person ) {
			$user = get_user_by( 'login', 'pv_' . $person[1] );
			if ( ! $user && $index < 15 ) {
				$user = get_user_by( 'login', 'pv_demo_' . sprintf( '%02d', $index + 1 ) );
			}
			$user_id = $user ? $user->ID : wp_create_user( 'pv_' . $person[1], wp_generate_password( 32, true, true ), $person[1] . '@example.test' );
			if ( is_wp_error( $user_id ) ) {
				continue;
			}
			wp_update_user( array( 'ID' => $user_id, 'display_name' => $person[0], 'first_name' => strtok( $person[0], ' ' ), 'role' => $person[2], 'description' => 'Membre de la communauté PhotoVault, sensible aux récits visuels, au patrimoine et à la photographie contemporaine.' ) );
			update_user_meta( $user_id, '_photovault_demo_seed', '1' );
			$user_ids[ $person[2] ][] = (int) $user_id;
		}

		$image_sources = array(
			array( 'ville-pluie', 'https://images.unsplash.com/photo-1511818966892-d7d671e672a2?auto=format&fit=crop&w=1600&q=82', 'Rue urbaine après la pluie' ),
			array( 'portrait-lumiere', 'https://images.unsplash.com/photo-1529139574466-a303027c1d8b?auto=format&fit=crop&w=1600&q=82', 'Portrait en lumière naturelle' ),
			array( 'territoire', 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1600&q=82', 'Paysage et territoire' ),
			array( 'architecture', 'https://images.unsplash.com/photo-1494526585095-c41746248156?auto=format&fit=crop&w=1600&q=82', 'Architecture habitée' ),
			array( 'portrait-rue', 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?auto=format&fit=crop&w=1600&q=82', 'Portrait documentaire' ),
			array( 'silhouette', 'https://images.unsplash.com/photo-1517841905240-472988babdf9?auto=format&fit=crop&w=1600&q=82', 'Présence et silhouette' ),
			array( 'ville-nuit', 'https://images.unsplash.com/photo-1518005020951-eccb494ad742?auto=format&fit=crop&w=1600&q=82', 'Ville à la tombée du jour' ),
			array( 'mer-horizon', 'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?auto=format&fit=crop&w=1600&q=82', 'Horizon maritime' ),
			array( 'matiere', 'https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&w=1600&q=82', 'Matière et abstraction' ),
			array( 'foret', 'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?auto=format&fit=crop&w=1600&q=82', 'Forêt et mémoire du paysage' ),
			array( 'route', 'https://images.unsplash.com/photo-1470770841072-f978cf4d019e?auto=format&fit=crop&w=1600&q=82', 'Route à travers le territoire' ),
			array( 'atelier', 'https://images.unsplash.com/photo-1452587925148-ce544e77e70d?auto=format&fit=crop&w=1600&q=82', 'Atelier photographique' ),
			array( 'gestes', 'https://images.unsplash.com/photo-1488426862026-3ee34a7d66df?auto=format&fit=crop&w=1600&q=82', 'Gestes du quotidien' ),
			array( 'monument', 'https://images.unsplash.com/photo-1496568816309-51d7c20e3b21?auto=format&fit=crop&w=1600&q=82', 'Patrimoine architectural' ),
			array( 'marche', 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?auto=format&fit=crop&w=1600&q=82', 'Scène de marché' ),
			array( 'fenetre', 'https://images.unsplash.com/photo-1484101403633-562f891dc89a?auto=format&fit=crop&w=1600&q=82', 'Intérieur et lumière' ),
			array( 'mouvement', 'https://images.unsplash.com/photo-1500534623283-312aade485b7?auto=format&fit=crop&w=1600&q=82', 'Mouvement dans le paysage' ),
			array( 'rivage', 'https://images.unsplash.com/photo-1433086966358-54859d0ed716?auto=format&fit=crop&w=1600&q=82', 'Rivage et eau vive' ),
		);
		$attachment_ids = array();
		foreach ( $image_sources as $source ) {
			$existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_photovault_demo_image_key', 'meta_value' => $source[0], 'fields' => 'ids', 'posts_per_page' => 1 ) );
			$attachment_id = $existing ? (int) $existing[0] : $this->sideload_demo_image( $source[1], $source[2], $source[0] );
			if ( $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		$collections = array(
			'Fragments urbains' => 'Façades, circulation, enseignes et gestes ordinaires composent un portrait vivant des villes du golfe de Guinée.',
			'Présences' => 'Des portraits construits dans la durée, au plus près des visages, des postures et de ce que le silence révèle.',
			'Après la pluie' => 'La ville lavée, les reflets sur l’asphalte et la lumière neuve qui transforme brièvement les rues familières.',
			'Mémoire silencieuse' => 'Une archive protégée consacrée aux lieux intimes, aux objets transmis et aux histoires qui ne se racontent qu’à voix basse.',
			'Territoires' => 'Du littoral aux routes intérieures, cette série documente les paysages, leurs usages et leurs transformations.',
			'Mouvements' => 'Corps, transports et foules saisis entre netteté et effacement pour rendre visible le rythme du quotidien.',
		);
		foreach ( $collections as $index => $description ) {
			$term = get_term_by( 'slug', 'demo-' . sanitize_title( $index ), 'media_folder' );
			if ( $term ) {
				wp_update_term( $term->term_id, 'media_folder', array( 'description' => $description ) );
				if ( $attachment_ids ) {
					update_term_meta( $term->term_id, 'cover_image_id', $attachment_ids[ array_search( $index, array_keys( $collections ), true ) % count( $attachment_ids ) ] );
				}
			}
		}

		$article_data = array(
			array( 'Photographier Cotonou après la pluie', 'Quand l’averse cesse, la ville devient un laboratoire de reflets, de gestes pressés et de couleurs ravivées.', '<p>À Cotonou, la pluie ne suspend pas la ville : elle en change la cadence. Les conducteurs cherchent les bandes d’asphalte les plus sèches, les vendeuses déplacent leurs étals et les façades se reflètent dans des flaques qui ne dureront parfois que quelques minutes.</p><p>Je travaille alors avec une focale légère et une vitesse assez lente pour conserver le mouvement. L’objectif n’est pas de produire une carte postale, mais de montrer comment chacun négocie avec l’eau, la lumière et la circulation.</p><p>Cette série s’inscrit dans une archive au long cours. Revenir aux mêmes carrefours permet de mesurer les transformations de la ville autant que celles de mon propre regard.</p>' ),
			array( 'La lumière de 17 heures', 'Une heure fragile où les visages se détachent, les ombres s’allongent et les matières retrouvent leur relief.', '<p>La lumière de fin d’après-midi n’est jamais une simple recette esthétique. Elle révèle les textures sans les écraser et installe une proximité particulière avec les personnes photographiées.</p><p>Pour les portraits, je privilégie un lieu familier et une préparation courte. Nous observons ensemble la trajectoire du soleil, puis la séance commence lorsque la lumière devient latérale.</p><p>Les images retenues ne sont pas forcément les plus parfaites. Ce sont celles où le modèle cesse de poser et reprend possession de son geste.</p>' ),
			array( 'Pourquoi certaines images restent privées', 'Protéger une photographie peut être une décision éthique, contractuelle ou simplement humaine.', '<p>Tout ce qui peut être photographié ne doit pas forcément être exposé. Certaines séries naissent dans un contexte familial, professionnel ou documentaire qui exige une diffusion limitée.</p><p>PhotoVault distingue donc clairement les œuvres publiques, les aperçus protégés et les originaux remis aux personnes autorisées. Cette séparation ne cherche pas à créer artificiellement de la rareté : elle respecte la confiance accordée au moment de la prise de vue.</p><p>Une archive responsable conserve aussi la trace des consentements, des accès et des usages prévus. La technique sert ici une relation, jamais l’inverse.</p>' ),
			array( 'Construire une série photographique', 'Passer de belles images isolées à un récit cohérent demande du temps, des choix et beaucoup de renoncements.', '<p>Une série commence rarement par un titre définitif. Elle naît d’une question assez précise pour guider le regard, mais assez ouverte pour accueillir l’imprévu.</p><p>Après chaque prise de vue, j’imprime une sélection de travail. Les rapprochements apparaissent par les gestes, les couleurs, les distances et les silences plutôt que par la seule chronologie.</p><p>Le montage final alterne plans larges et détails. Chaque image doit apporter une information nouvelle tout en laissant à la suivante l’espace nécessaire pour respirer.</p>' ),
			array( 'Ce qu’une archive conserve vraiment', 'Au-delà des fichiers, une archive préserve des relations, des lieux et le contexte qui donne du sens aux images.', '<p>Un fichier parfaitement sauvegardé peut devenir muet si son lieu, sa date et son histoire disparaissent. Documenter une photographie fait donc partie du travail photographique.</p><p>Je conserve les sélections, les légendes, les autorisations et quelques notes de terrain. Ces éléments permettent de relire une image des années plus tard sans inventer ce que la mémoire a oublié.</p><p>L’archive n’est pas un cimetière numérique. Elle reste active : de nouvelles associations apparaissent, certaines images changent de statut et des récits anciens rencontrent le présent.</p>' ),
			array( 'Portrait : créer avant de diriger', 'Une séance réussie se construit avec la personne photographiée, bien avant le premier déclenchement.', '<p>La direction de portrait commence par une conversation. Il faut comprendre l’usage des images, ce que la personne souhaite montrer et ce qu’elle préfère préserver.</p><p>Je donne peu d’instructions à la fois. Une position, un regard, un mouvement simple : le cadre reste précis sans transformer la séance en performance rigide.</p><p>La sélection finale associe des portraits immédiatement lisibles à des images plus discrètes. Ensemble, elles racontent davantage qu’une photographie unique.</p>' ),
			array( 'Porto-Novo, patrimoine en mouvement', 'Lire la ville à travers ses architectures, ses usages quotidiens et les traces laissées par plusieurs époques.', '<p>Photographier le patrimoine de Porto-Novo suppose de résister à l’image figée du monument. Les bâtiments vivent avec les commerces, les déplacements et les habitudes de celles et ceux qui les entourent.</p><p>Je reviens à différentes heures pour observer comment la lumière et les usages redessinent un même lieu. Le matin décrit les volumes ; le soir révèle les présences.</p><p>Cette méthode produit une archive située, attentive autant à l’architecture qu’à la vie qui lui donne sa véritable échelle.</p>' ),
			array( 'Choisir le noir et blanc', 'Retirer la couleur n’est pas neutraliser le monde : c’est déplacer l’attention vers le rythme, la matière et la lumière.', '<p>Le noir et blanc intervient lorsque la couleur disperse le regard ou lorsque la série repose d’abord sur les formes et les contrastes.</p><p>La décision se prend dès la prise de vue. J’observe alors les rapports de densité, la séparation des plans et la façon dont la peau répond à la lumière disponible.</p><p>Le traitement reste mesuré. Les noirs conservent du détail et les hautes lumières évitent l’effet spectaculaire pour préserver la présence du sujet.</p>' ),
			array( 'Les gestes du marché', 'Un travail documentaire sur les mains, les échanges et l’intelligence quotidienne des espaces marchands.', '<p>Dans un marché, chaque geste répond à une nécessité : peser, emballer, compter, appeler, négocier ou protéger la marchandise de la pluie.</p><p>Je photographie avec une grande attention aux circulations afin de ne pas interrompre le travail. Les plans rapprochés sont toujours précédés d’un échange et d’un accord clair.</p><p>La série alterne détails et vues d’ensemble. Elle montre l’économie visible, mais aussi les relations et les savoir-faire qui la rendent possible.</p>' ),
			array( 'Préparer un shooting familial', 'Quelques décisions simples permettent de garder une séance naturelle tout en obtenant une série cohérente.', '<p>Le lieu compte davantage qu’un décor sophistiqué. Une maison, une cour ou un quartier familier aide chacun à retrouver rapidement ses gestes naturels.</p><p>Les vêtements peuvent partager une gamme de couleurs sans devenir uniformes. Je recommande surtout d’éviter les éléments inconfortables qui détournent l’attention pendant la séance.</p><p>Nous prévoyons les images indispensables, puis laissons une place importante aux interactions spontanées. C’est souvent entre deux portraits préparés que surgit l’image la plus juste.</p>' ),
			array( 'Du fichier au tirage', 'Le tirage donne à la photographie une échelle, une matière et une présence que l’écran ne peut pas reproduire.', '<p>Le choix du papier dépend de la série. Une surface mate convient aux images intimes et aux noirs profonds ; un papier plus texturé peut soutenir les paysages et les matières.</p><p>Chaque fichier est préparé pour son format final. Netteté, contraste et densité sont contrôlés sur une épreuve avant la production définitive.</p><p>Le tirage n’est pas une copie du fichier. Il constitue la forme achevée d’une image pensée pour être regardée dans un espace réel.</p>' ),
			array( 'Éditer sans perdre l’émotion', 'Le tri photographique permet de faire émerger un récit sans effacer les accidents qui lui donnent sa vérité.', '<p>La première sélection reste volontairement large. Je laisse passer quelques jours avant de revoir les images, afin que le souvenir de la séance ne décide pas seul de leur valeur.</p><p>Je recherche ensuite les répétitions, les ruptures et les images de transition. Une photographie forte peut être écartée si elle rompt le langage de l’ensemble.</p><p>Éditer consiste à protéger l’émotion de l’accumulation. Moins d’images, mieux reliées, laissent souvent une mémoire plus durable.</p>' ),
		);
		$post_ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_photovault_demo_seed', 'posts_per_page' => 12, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
		$author_ids = array_merge( $user_ids['author'] ?? array(), $user_ids['editor'] ?? array() );
		foreach ( $article_data as $index => $article ) {
			$post_id = isset( $post_ids[ $index ] ) ? (int) $post_ids[ $index ] : 0;
			$post_id = wp_insert_post( array( 'ID' => $post_id, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => $author_ids[ $index % count( $author_ids ) ], 'post_title' => $article[0], 'post_excerpt' => $article[1], 'post_content' => $article[2] ), true );
			if ( is_wp_error( $post_id ) ) {
				continue;
			}
			update_post_meta( $post_id, '_photovault_demo_seed', '1' );
			wp_set_post_terms( $post_id, array( 'Carnets visuels', 0 === $index % 2 ? 'Regards de terrain' : 'Pratique photographique' ), 'category' );
			wp_set_post_terms( $post_id, array( 'photographie', 'archives', 0 === $index % 3 ? 'Bénin' : 'création' ), 'post_tag' );
			if ( $attachment_ids ) {
				set_post_thumbnail( $post_id, $attachment_ids[ $index % count( $attachment_ids ) ] );
			}
		}

		$work_titles = array( 'Reflets de Ganhi', 'Présence I', 'Le mur ocre', 'Après l’averse', 'Traversée de Dantokpa', 'Silence du rivage', 'Fenêtre sur Zongo', 'Mémoire d’une cour', 'Route des palmiers', 'Veilleuse', 'Les mains du marché', 'Dernière lumière', 'Façade habitée', 'Passage', 'Horizon salin', 'Portrait au seuil', 'Rumeur de la ville', 'Matière vive', 'Retour du lac', 'Dimanche à Porto-Novo', 'Sous les arcades', 'Équilibre', 'Le temps des manguiers', 'Marche lente' );
		$media_ids = get_posts( array( 'post_type' => 'media_item', 'post_status' => 'any', 'meta_key' => '_photovault_demo_seed', 'posts_per_page' => 60, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
		foreach ( $media_ids as $index => $media_id ) {
			$title = $work_titles[ $index % count( $work_titles ) ] . ( $index >= count( $work_titles ) ? ' II' : '' );
			wp_update_post( array( 'ID' => $media_id, 'post_author' => $author_ids[ $index % count( $author_ids ) ], 'post_title' => $title, 'post_excerpt' => sprintf( 'Photographie réalisée au Bénin en %d, issue d’un travail sur la lumière, la mémoire et les transformations du territoire.', 2022 + ( $index % 5 ) ), 'post_content' => '<p>Cette œuvre appartient à une recherche documentaire menée sur plusieurs années. Elle observe la relation entre les personnes, les architectures et la lumière disponible, sans interrompre le rythme naturel du lieu.</p><p>Le cadrage conserve volontairement des signes du contexte afin que l’image reste une trace située plutôt qu’une scène abstraite.</p>' ) );
			if ( $attachment_ids ) {
				set_post_thumbnail( $media_id, $attachment_ids[ $index % count( $attachment_ids ) ] );
			}
		}

		return array( 'images' => count( $attachment_ids ), 'users' => array_sum( array_map( 'count', $user_ids ) ), 'posts' => count( $article_data ), 'media' => count( $media_ids ) );
	}

	/**
	 * Import one remote demonstration image through the WordPress media pipeline.
	 *
	 * @param string $url Remote image URL.
	 * @param string $title Attachment title and alternative text.
	 * @param string $key Stable seed key.
	 * @return int Attachment ID, or zero when the download fails.
	 */
	private function sideload_demo_image( $url, $title, $key ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$temp_file = download_url( $url, 60 );
		if ( is_wp_error( $temp_file ) ) {
			WP_CLI::warning( sprintf( 'Image %s unavailable: %s', $key, $temp_file->get_error_message() ) );
			return 0;
		}
		$file = array( 'name' => 'photovault-' . sanitize_file_name( $key ) . '.jpg', 'tmp_name' => $temp_file );
		$attachment_id = media_handle_sideload( $file, 0, $title );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $temp_file );
			WP_CLI::warning( sprintf( 'Image %s rejected: %s', $key, $attachment_id->get_error_message() ) );
			return 0;
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $title );
		update_post_meta( $attachment_id, '_photovault_demo_image_key', $key );
		return (int) $attachment_id;
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
