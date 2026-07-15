<?php
/**
 * Private shooting reservations and validated lifecycle.
 *
 * @package PhotoVaultCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function photovault_get_shooting_types() {
	$types = array(
		'portrait'      => __( 'Portrait prive', 'photovault' ),
		'family'        => __( 'Couple et famille', 'photovault' ),
		'event'         => __( 'Evenement', 'photovault' ),
		'corporate'     => __( 'Corporate', 'photovault' ),
		'artistic'      => __( 'Projet artistique', 'photovault' ),
		'custom'        => __( 'Commande sur mesure', 'photovault' ),
	);

	return apply_filters( 'photovault_shooting_types', $types );
}

function photovault_get_shooting_statuses() {
	return array(
		'pending'   => __( 'En attente', 'photovault' ),
		'confirmed' => __( 'Confirmee', 'photovault' ),
		'cancelled' => __( 'Annulee', 'photovault' ),
		'completed' => __( 'Terminee', 'photovault' ),
	);
}

function photovault_register_shooting_post_type() {
	register_post_type(
		'photovault_shooting',
		array(
			'labels'              => array(
				'name'          => __( 'Shootings', 'photovault' ),
				'singular_name' => __( 'Shooting', 'photovault' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'supports'            => array( 'title', 'author' ),
			'capability_type'     => 'post',
		)
	);
}
add_action( 'init', 'photovault_register_shooting_post_type' );

function photovault_get_shooting_data( $shooting_id ) {
	$shooting_id = absint( $shooting_id );
	$post        = get_post( $shooting_id );
	if ( ! $post || 'photovault_shooting' !== $post->post_type ) {
		return null;
	}

	return array(
		'id'           => $shooting_id,
		'user_id'      => (int) $post->post_author,
		'type'         => sanitize_key( get_post_meta( $shooting_id, '_photovault_shooting_type', true ) ),
		'desired_date' => (string) get_post_meta( $shooting_id, '_photovault_shooting_date', true ),
		'location'     => (string) get_post_meta( $shooting_id, '_photovault_shooting_location', true ),
		'message'      => (string) get_post_meta( $shooting_id, '_photovault_shooting_message', true ),
		'contact_name' => (string) get_post_meta( $shooting_id, '_photovault_shooting_contact_name', true ),
		'contact_email'=> (string) get_post_meta( $shooting_id, '_photovault_shooting_contact_email', true ),
		'contact_phone'=> (string) get_post_meta( $shooting_id, '_photovault_shooting_contact_phone', true ),
		'status'       => sanitize_key( get_post_meta( $shooting_id, '_photovault_shooting_status', true ) ) ?: 'pending',
		'created_at'   => $post->post_date_gmt,
		'updated_at'   => (string) get_post_meta( $shooting_id, '_photovault_shooting_updated_at', true ),
	);
}

function photovault_user_can_read_shooting( $shooting_id, $user_id = 0 ) {
	$data    = photovault_get_shooting_data( $shooting_id );
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();

	return $data && $user_id && ( $data['user_id'] === $user_id || photovault_user_can( $user_id, 'photovault_manage_shootings' ) );
}

function photovault_get_user_shootings( $user_id = 0, $limit = 30 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	$limit   = max( 1, min( 100, absint( $limit ) ) );
	if ( ! $user_id || ( get_current_user_id() !== $user_id && ! photovault_current_user_can( 'photovault_manage_shootings' ) ) ) {
		return array();
	}

	$query = new WP_Query(
		array(
			'post_type'      => 'photovault_shooting',
			'post_status'    => 'private',
			'author'         => $user_id,
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	return array_values( array_filter( array_map( 'photovault_get_shooting_data', wp_list_pluck( $query->posts, 'ID' ) ) ) );
}

function photovault_validate_shooting_date( $value ) {
	$value = sanitize_text_field( (string) $value );
	$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	$latest = $today->modify( '+2 years' );
	$errors = DateTimeImmutable::getLastErrors();
	if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date < $today || $date > $latest ) {
		return new WP_Error( 'shooting_invalid_date', __( 'Choisissez une date valide dans les deux prochaines annees.', 'photovault' ) );
	}

	return $date->format( 'Y-m-d' );
}

function photovault_create_shooting( $values, $user_id = 0 ) {
	$user_id = $user_id ? absint( $user_id ) : get_current_user_id();
	if ( ! $user_id || ( get_current_user_id() !== $user_id && ! photovault_current_user_can( 'photovault_manage_shootings' ) ) ) {
		return new WP_Error( 'shooting_forbidden', __( 'Vous ne pouvez pas creer cette reservation.', 'photovault' ) );
	}
	if ( ! photovault_user_can( $user_id, 'photovault_manage_shootings' ) && ! photovault_user_has_verified_identity( $user_id ) ) {
		return new WP_Error( 'shooting_identity_unverified', __( 'Verifiez votre adresse e-mail avant de reserver.', 'photovault' ) );
	}

	$values = is_array( $values ) ? $values : array();
	$type   = sanitize_key( $values['type'] ?? '' );
	if ( ! array_key_exists( $type, photovault_get_shooting_types() ) ) {
		return new WP_Error( 'shooting_invalid_type', __( 'Choisissez un type de shooting valide.', 'photovault' ) );
	}
	$date = photovault_validate_shooting_date( $values['desired_date'] ?? '' );
	if ( is_wp_error( $date ) ) {
		return $date;
	}

	$location      = substr( sanitize_text_field( $values['location'] ?? '' ), 0, 160 );
	$message       = substr( sanitize_textarea_field( $values['message'] ?? '' ), 0, 2000 );
	$contact_name  = substr( sanitize_text_field( $values['contact_name'] ?? '' ), 0, 120 );
	$contact_email = sanitize_email( $values['contact_email'] ?? '' );
	$contact_phone = substr( sanitize_text_field( $values['contact_phone'] ?? '' ), 0, 40 );
	$account        = get_userdata( $user_id );
	if ( strlen( $location ) < 3 || strlen( $message ) < 10 || strlen( $contact_name ) < 2 || ! is_email( $contact_email ) || ( $contact_phone && ! preg_match( '/^\+[1-9][0-9]{7,14}$/', $contact_phone ) ) ) {
		return new WP_Error( 'shooting_invalid_fields', __( 'Completez les informations de contact et le projet.', 'photovault' ) );
	}
	if ( ! photovault_user_can( $user_id, 'photovault_manage_shootings' ) && ( ! $account || strtolower( $contact_email ) !== strtolower( $account->user_email ) ) ) {
		return new WP_Error( 'shooting_contact_mismatch', __( 'Utilisez l adresse e-mail verifiee de votre compte.', 'photovault' ) );
	}

	$duplicate = get_posts(
		array(
			'post_type'      => 'photovault_shooting',
			'post_status'    => 'private',
			'author'         => $user_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => '_photovault_shooting_type', 'value' => $type ),
				array( 'key' => '_photovault_shooting_date', 'value' => $date ),
				array( 'key' => '_photovault_shooting_status', 'value' => array( 'pending', 'confirmed' ), 'compare' => 'IN' ),
			),
		)
	);
	if ( $duplicate ) {
		return new WP_Error( 'shooting_duplicate', __( 'Une reservation active existe deja pour ce type et cette date.', 'photovault' ) );
	}

	$post_id = wp_insert_post(
		array(
			'post_type'   => 'photovault_shooting',
			'post_status' => 'private',
			'post_author' => $user_id,
			'post_title'  => sprintf( '%s - %s - %s', photovault_get_shooting_types()[ $type ], $contact_name, $date ),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	$meta = array(
		'_photovault_shooting_type'          => $type,
		'_photovault_shooting_date'          => $date,
		'_photovault_shooting_location'      => $location,
		'_photovault_shooting_message'       => $message,
		'_photovault_shooting_contact_name'  => $contact_name,
		'_photovault_shooting_contact_email' => $contact_email,
		'_photovault_shooting_contact_phone' => $contact_phone,
		'_photovault_shooting_status'        => 'pending',
		'_photovault_shooting_updated_at'    => current_time( 'mysql', true ),
	);
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	photovault_log_media_event( 'shooting_created', 'success', 0, array( 'shooting_id' => $post_id, 'user_id' => $user_id, 'type' => $type ) );
	photovault_send_shooting_notifications( $post_id, 'created' );

	return (int) $post_id;
}

function photovault_get_shooting_transitions() {
	return array(
		'pending'   => array( 'confirmed', 'cancelled' ),
		'confirmed' => array( 'completed', 'cancelled' ),
		'cancelled' => array(),
		'completed' => array(),
	);
}

function photovault_transition_shooting( $shooting_id, $new_status, $actor_user_id = 0 ) {
	$data          = photovault_get_shooting_data( $shooting_id );
	$new_status    = sanitize_key( $new_status );
	$actor_user_id = $actor_user_id ? absint( $actor_user_id ) : get_current_user_id();
	$current_user_id = get_current_user_id();
	if ( ! $data || ! $actor_user_id ) {
		return new WP_Error( 'shooting_not_found', __( 'Reservation introuvable.', 'photovault' ) );
	}
	if ( $current_user_id !== $actor_user_id && ! photovault_current_user_can( 'photovault_manage_shootings' ) ) {
		return new WP_Error( 'shooting_transition_forbidden', __( 'Cette transition ne vous est pas autorisee.', 'photovault' ) );
	}

	$is_manager = photovault_user_can( $actor_user_id, 'photovault_manage_shootings' );
	$is_owner   = $data['user_id'] === $actor_user_id;
	if ( ! $is_manager && ( ! $is_owner || 'cancelled' !== $new_status ) ) {
		return new WP_Error( 'shooting_transition_forbidden', __( 'Cette transition ne vous est pas autorisee.', 'photovault' ) );
	}
	$allowed = photovault_get_shooting_transitions()[ $data['status'] ] ?? array();
	if ( ! in_array( $new_status, $allowed, true ) ) {
		return new WP_Error( 'shooting_invalid_transition', __( 'Cette transition de statut est invalide.', 'photovault' ) );
	}

	update_post_meta( $shooting_id, '_photovault_shooting_status', $new_status );
	update_post_meta( $shooting_id, '_photovault_shooting_updated_at', current_time( 'mysql', true ) );
	photovault_log_media_event( 'shooting_status_updated', 'success', 0, array( 'shooting_id' => $shooting_id, 'user_id' => $data['user_id'], 'from_status' => $data['status'], 'to_status' => $new_status ) );
	photovault_send_shooting_notifications( $shooting_id, 'status', $actor_user_id );

	return true;
}

function photovault_send_shooting_email( $to, $subject, $content ) {
	if ( function_exists( 'identity_security_kit_send_transactional_email' ) ) {
		return identity_security_kit_send_transactional_email( $to, $subject, $content );
	}

	$body = implode( "\n\n", array_filter( array_merge( array( $content['title'] ?? '', $content['greeting'] ?? '', $content['intro'] ?? '' ), $content['details'] ?? array(), array( $content['action_url'] ?? '' ) ) ) );
	return wp_mail( sanitize_email( $to ), sanitize_text_field( $subject ), $body );
}

function photovault_send_shooting_notifications( $shooting_id, $event, $actor_user_id = 0 ) {
	$data = photovault_get_shooting_data( $shooting_id );
	if ( ! $data ) {
		return false;
	}
	$types    = photovault_get_shooting_types();
	$statuses = photovault_get_shooting_statuses();
	$details  = array(
		sprintf( __( 'Type : %s', 'photovault' ), $types[ $data['type'] ] ?? $data['type'] ),
		sprintf( __( 'Date souhaitee : %s', 'photovault' ), wp_date( get_option( 'date_format' ), strtotime( $data['desired_date'] ) ) ),
		sprintf( __( 'Lieu : %s', 'photovault' ), $data['location'] ),
		sprintf( __( 'Statut : %s', 'photovault' ), $statuses[ $data['status'] ] ?? $data['status'] ),
	);
	$content = array(
		'preheader'    => __( 'Mise a jour de votre demande de shooting PhotoVault.', 'photovault' ),
		'eyebrow'      => __( 'Studio PhotoVault', 'photovault' ),
		'title'        => 'created' === $event ? __( 'Votre demande est bien recue', 'photovault' ) : __( 'Votre reservation evolue', 'photovault' ),
		'greeting'     => sprintf( __( 'Bonjour %s,', 'photovault' ), $data['contact_name'] ),
		'intro'        => 'created' === $event ? __( 'Nous allons examiner votre projet et revenir vers vous pour confirmer la date et les details.', 'photovault' ) : __( 'Le statut de votre demande de shooting vient d etre mis a jour.', 'photovault' ),
		'details'      => $details,
		'action_url'   => add_query_arg( 'section', 'bookings', home_url( '/dashboard/' ) ),
		'action_label' => __( 'Suivre ma reservation', 'photovault' ),
		'notice'       => __( 'Une demande reste modifiable uniquement par le studio. Vous pouvez l annuler depuis votre espace tant qu elle n est pas terminee.', 'photovault' ),
	);
	$sent = photovault_send_shooting_email( $data['contact_email'], sprintf( __( '[PhotoVault] Shooting %s', 'photovault' ), $statuses[ $data['status'] ] ?? $data['status'] ), $content );
	if ( 'created' === $event ) {
		$admin_content = $content;
		$admin_content['title'] = __( 'Nouvelle demande de shooting', 'photovault' );
		$admin_content['greeting'] = sprintf( __( 'Demande de %s', 'photovault' ), $data['contact_name'] );
		$admin_content['intro'] = $data['message'];
		$admin_content['action_url'] = admin_url( 'edit.php?post_type=media_item&page=photovault-shootings' );
		$admin_content['action_label'] = __( 'Examiner la demande', 'photovault' );
		$sent = photovault_send_shooting_email( get_option( 'admin_email' ), __( '[PhotoVault] Nouvelle demande de shooting', 'photovault' ), $admin_content ) && $sent;
	} elseif ( 'cancelled' === $data['status'] && $actor_user_id && ! photovault_user_can( $actor_user_id, 'photovault_manage_shootings' ) ) {
		$admin_content = $content;
		$admin_content['title'] = __( 'Reservation annulee par le client', 'photovault' );
		$admin_content['greeting'] = sprintf( __( '%s a annule sa demande.', 'photovault' ), $data['contact_name'] );
		$admin_content['intro'] = __( 'La demande est maintenant fermee et ne peut plus changer de statut.', 'photovault' );
		$admin_content['action_url'] = admin_url( 'edit.php?post_type=media_item&page=photovault-shootings' );
		$admin_content['action_label'] = __( 'Ouvrir les reservations', 'photovault' );
		$sent = photovault_send_shooting_email( get_option( 'admin_email' ), __( '[PhotoVault] Reservation annulee', 'photovault' ), $admin_content ) && $sent;
	}

	return $sent;
}

function photovault_handle_create_shooting() {
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( home_url( '/login/' ) );
		exit;
	}
	check_admin_referer( 'photovault_create_shooting', 'photovault_shooting_nonce' );
	if ( ! photovault_rate_limit( 'shooting_create', 5, HOUR_IN_SECONDS ) ) {
		wp_safe_redirect( add_query_arg( 'booking', 'rate_limited', home_url( '/booking/' ) ) );
		exit;
	}

	$result = photovault_create_shooting(
		array(
			'type'          => isset( $_POST['shooting_type'] ) ? wp_unslash( $_POST['shooting_type'] ) : '',
			'desired_date'  => isset( $_POST['shooting_date'] ) ? wp_unslash( $_POST['shooting_date'] ) : '',
			'location'      => isset( $_POST['shooting_location'] ) ? wp_unslash( $_POST['shooting_location'] ) : '',
			'message'       => isset( $_POST['shooting_message'] ) ? wp_unslash( $_POST['shooting_message'] ) : '',
			'contact_name'  => isset( $_POST['shooting_contact_name'] ) ? wp_unslash( $_POST['shooting_contact_name'] ) : '',
			'contact_email' => isset( $_POST['shooting_contact_email'] ) ? wp_unslash( $_POST['shooting_contact_email'] ) : '',
			'contact_phone' => isset( $_POST['shooting_contact_phone'] ) ? wp_unslash( $_POST['shooting_contact_phone'] ) : '',
		)
	);
	$status = is_wp_error( $result ) ? $result->get_error_code() : 'success';
	wp_safe_redirect( add_query_arg( 'booking', sanitize_key( $status ), is_wp_error( $result ) ? home_url( '/booking/' ) : add_query_arg( 'section', 'bookings', home_url( '/dashboard/' ) ) ) );
	exit;
}
add_action( 'admin_post_photovault_create_shooting', 'photovault_handle_create_shooting' );

function photovault_handle_shooting_transition() {
	$shooting_id = isset( $_POST['shooting_id'] ) ? absint( $_POST['shooting_id'] ) : 0;
	$new_status  = isset( $_POST['shooting_status'] ) ? sanitize_key( wp_unslash( $_POST['shooting_status'] ) ) : '';
	check_admin_referer( 'photovault_shooting_transition_' . $shooting_id, 'photovault_shooting_nonce' );
	$result = photovault_transition_shooting( $shooting_id, $new_status );
	$status = is_wp_error( $result ) ? $result->get_error_code() : 'updated';
	$target = photovault_current_user_can( 'photovault_manage_shootings' ) ? admin_url( 'edit.php?post_type=media_item&page=photovault-shootings' ) : add_query_arg( 'section', 'bookings', home_url( '/dashboard/' ) );
	wp_safe_redirect( add_query_arg( 'shooting', sanitize_key( $status ), $target ) );
	exit;
}
add_action( 'admin_post_photovault_shooting_transition', 'photovault_handle_shooting_transition' );

function photovault_register_shootings_admin_menu() {
	add_submenu_page(
		'edit.php?post_type=media_item',
		__( 'Shootings', 'photovault' ),
		__( 'Shootings', 'photovault' ),
		'photovault_manage_shootings',
		'photovault-shootings',
		'photovault_render_shootings_admin_page'
	);
}
add_action( 'admin_menu', 'photovault_register_shootings_admin_menu' );

function photovault_render_shootings_admin_page() {
	if ( ! photovault_current_user_can( 'photovault_manage_shootings' ) ) {
		wp_die( esc_html__( 'Acces refuse.', 'photovault' ) );
	}
	$query = new WP_Query( array( 'post_type' => 'photovault_shooting', 'post_status' => 'private', 'posts_per_page' => 100, 'orderby' => 'date', 'order' => 'DESC' ) );
	$types = photovault_get_shooting_types();
	$statuses = photovault_get_shooting_statuses();
	$notice = isset( $_GET['shooting'] ) ? sanitize_key( wp_unslash( $_GET['shooting'] ) ) : '';
	?>
	<div class="wrap"><h1><?php esc_html_e( 'Reservations de shootings', 'photovault' ); ?></h1><p><?php esc_html_e( 'Confirmez les projets, annulez les demandes impossibles et marquez les seances realisees.', 'photovault' ); ?></p>
	<?php if ( $notice ) : ?><div class="notice <?php echo 'updated' === $notice ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( 'updated' === $notice ? __( 'Le statut de la reservation a ete mis a jour et le client a ete informe.', 'photovault' ) : __( 'Le statut n a pas pu etre modifie.', 'photovault' ) ); ?></p></div><?php endif; ?>
	<table class="widefat fixed striped"><thead><tr><th><?php esc_html_e( 'Client', 'photovault' ); ?></th><th><?php esc_html_e( 'Projet', 'photovault' ); ?></th><th><?php esc_html_e( 'Date et lieu', 'photovault' ); ?></th><th><?php esc_html_e( 'Statut', 'photovault' ); ?></th><th><?php esc_html_e( 'Actions', 'photovault' ); ?></th></tr></thead><tbody>
	<?php if ( ! $query->posts ) : ?><tr><td colspan="5"><?php esc_html_e( 'Aucune reservation.', 'photovault' ); ?></td></tr><?php endif; ?>
	<?php foreach ( $query->posts as $post ) : $item = photovault_get_shooting_data( $post->ID ); $allowed = photovault_get_shooting_transitions()[ $item['status'] ] ?? array(); ?>
	<tr><td><strong><?php echo esc_html( $item['contact_name'] ); ?></strong><br><a href="mailto:<?php echo esc_attr( $item['contact_email'] ); ?>"><?php echo esc_html( $item['contact_email'] ); ?></a><br><?php echo esc_html( $item['contact_phone'] ); ?></td><td><strong><?php echo esc_html( $types[ $item['type'] ] ?? $item['type'] ); ?></strong><p><?php echo esc_html( $item['message'] ); ?></p></td><td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $item['desired_date'] ) ) ); ?><br><?php echo esc_html( $item['location'] ); ?></td><td><?php echo esc_html( $statuses[ $item['status'] ] ?? $item['status'] ); ?></td><td><?php foreach ( $allowed as $next ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 6px 6px 0"><input type="hidden" name="action" value="photovault_shooting_transition"><input type="hidden" name="shooting_id" value="<?php echo esc_attr( $item['id'] ); ?>"><input type="hidden" name="shooting_status" value="<?php echo esc_attr( $next ); ?>"><?php wp_nonce_field( 'photovault_shooting_transition_' . $item['id'], 'photovault_shooting_nonce' ); ?><button class="button<?php echo 'confirmed' === $next || 'completed' === $next ? ' button-primary' : ''; ?>" type="submit"><?php echo esc_html( $statuses[ $next ] ); ?></button></form><?php endforeach; ?></td></tr>
	<?php endforeach; ?></tbody></table></div>
	<?php
}
