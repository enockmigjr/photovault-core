<?php
/**
 * Contact notification composition for PhotoVault frontends.
 *
 * @package PhotoVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Return the accepted public contact categories. */
function photovault_get_contact_request_types() {
	return apply_filters(
		'photovault_contact_request_types',
		array(
			'access'   => __( 'Acces a une collection protegee', 'photovault' ),
			'shooting' => __( 'Reservation shooting', 'photovault' ),
			'license'  => __( 'Licence ou tirage', 'photovault' ),
			'general'  => __( 'Question generale', 'photovault' ),
		)
	);
}

/** Send one studio notification and one acknowledgement without sender spoofing. */
function photovault_send_contact_notification( $data ) {
	$data         = is_array( $data ) ? $data : array();
	$name         = sanitize_text_field( $data['name'] ?? '' );
	$email        = sanitize_email( $data['email'] ?? '' );
	$request_type = sanitize_key( $data['request_type'] ?? '' );
	$subject      = sanitize_text_field( $data['subject'] ?? '' );
	$collection   = sanitize_text_field( $data['collection'] ?? '' );
	$message      = sanitize_textarea_field( $data['message'] ?? '' );
	$types        = photovault_get_contact_request_types();

	if ( '' === $name || ! is_email( $email ) || '' === $subject || '' === $message || ! isset( $types[ $request_type ] ) ) {
		return false;
	}

	$content = array(
		'preheader' => __( 'Un nouveau message attend une reponse du studio.', 'photovault' ),
		'eyebrow'   => __( 'Contact PhotoVault', 'photovault' ),
		'title'     => __( 'Nouveau message', 'photovault' ),
		'greeting'  => sprintf( __( 'Message de %s', 'photovault' ), $name ),
		'intro'     => $message,
		'details'   => array_filter(
			array(
				sprintf( __( 'Type : %s', 'photovault' ), $types[ $request_type ] ),
				sprintf( __( 'Sujet : %s', 'photovault' ), $subject ),
				$collection ? sprintf( __( 'Collection / oeuvre : %s', 'photovault' ), $collection ) : '',
				sprintf( __( 'Repondre a : %s', 'photovault' ), $email ),
			)
		),
		'notice'    => __( 'Utilisez Repondre dans votre messagerie pour contacter directement le visiteur.', 'photovault' ),
	);

	$recipient   = sanitize_email( get_option( 'admin_email' ) );
	$mail_subject = sprintf( __( '[PhotoVault] Contact : %s', 'photovault' ), $subject );
	if ( ! is_email( $recipient ) || ! function_exists( 'photovault_send_transactional_email' ) ) {
		return false;
	}

	$studio_sent = photovault_send_transactional_email( $recipient, $mail_subject, $content, $email );
	$acknowledgement = array(
		'preheader'    => __( 'Votre message a bien ete transmis au studio.', 'photovault' ),
		'eyebrow'      => __( 'Contact PhotoVault', 'photovault' ),
		'title'        => __( 'Message bien recu', 'photovault' ),
		'greeting'     => sprintf( __( 'Bonjour %s,', 'photovault' ), $name ),
		'intro'        => __( 'Merci pour votre message. Le studio reviendra vers vous apres examen de votre demande.', 'photovault' ),
		'details'      => array(
			sprintf( __( 'Type : %s', 'photovault' ), $types[ $request_type ] ),
			sprintf( __( 'Sujet : %s', 'photovault' ), $subject ),
		),
		'action_url'   => home_url( '/' ),
		'action_label' => __( 'Retourner sur PhotoVault', 'photovault' ),
		'notice'       => __( 'Conservez cet e-mail comme confirmation de transmission.', 'photovault' ),
	);
	$visitor_sent = photovault_send_transactional_email(
		$email,
		__( '[PhotoVault] Votre message est bien recu', 'photovault' ),
		$acknowledgement,
		$recipient
	);

	return $studio_sent && $visitor_sent;
}
