<?php
/**
 * REST transport for public application workflows.
 *
 * Business rules remain in the contact, access request, and shooting services.
 *
 * @package PhotoVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return a consistent successful application response.
 *
 * @param string $message User-facing message.
 * @param array  $data    Response payload.
 * @param int    $status  HTTP status.
 */
function photovault_application_rest_success( $message, $data = array(), $status = 200 ) {
	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => (string) $message,
			'data'    => is_array( $data ) ? $data : array(),
			'errors'  => array(),
			'meta'    => array(),
		),
		$status
	);
}

/**
 * Return a consistent failed application response.
 *
 * @param WP_Error|string $error  Error or message.
 * @param int             $status HTTP status.
 */
function photovault_application_rest_error( $error, $status = 422 ) {
	$error = is_wp_error( $error ) ? $error : new WP_Error( 'application_error', (string) $error );
	$code  = $error->get_error_code();
	$data  = $error->get_error_data( $code );

	if ( is_array( $data ) && isset( $data['status'] ) ) {
		$status = absint( $data['status'] );
	}

	return new WP_REST_Response(
		array(
			'success' => false,
			'message' => $error->get_error_message(),
			'data'    => array(),
			'errors'  => is_array( $data ) && isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array(),
			'meta'    => array( 'code' => $code ),
		),
		$status
	);
}

/** Map domain error codes to useful HTTP statuses. */
function photovault_application_rest_error_status( WP_Error $error ) {
	$code = $error->get_error_code();

	if ( false !== strpos( $code, 'forbidden' ) || false !== strpos( $code, 'unverified' ) ) {
		return 403;
	}
	if ( false !== strpos( $code, 'not_found' ) ) {
		return 404;
	}
	if ( 'rate_limited' === $code ) {
		return 429;
	}
	if ( false !== strpos( $code, 'duplicate' ) || false !== strpos( $code, 'transition' ) ) {
		return 409;
	}

	return 422;
}

/** Read request values regardless of JSON or form encoding. */
function photovault_application_rest_params( WP_REST_Request $request ) {
	$json = $request->get_json_params();
	return is_array( $json ) ? $json : $request->get_params();
}

/** Return the clean no-JavaScript preferences endpoint. */
function photovault_get_account_preferences_action_url() {
	return home_url( '/account/preferences-action/' );
}

/** Persist validated presentation preferences for one account. */
function photovault_application_save_preferences( $user_id, $params ) {
	$density = sanitize_key( $params['gallery_density'] ?? 'editorial' );
	$landing = sanitize_key( $params['dashboard_landing'] ?? 'overview' );
	$density = in_array( $density, array( 'editorial', 'compact' ), true ) ? $density : 'editorial';
	$landing = in_array( $landing, array( 'overview', 'favorites', 'access', 'bookings' ), true ) ? $landing : 'overview';

	update_user_meta( $user_id, 'photovault_gallery_density', $density );
	update_user_meta( $user_id, 'photovault_reduce_motion', ! empty( $params['reduce_motion'] ) ? '1' : '0' );
	update_user_meta( $user_id, 'photovault_dashboard_landing', $landing );

	return array(
		'gallery_density'  => $density,
		'reduce_motion'    => ! empty( $params['reduce_motion'] ),
		'dashboard_landing' => $landing,
	);
}

/** Process the clean fallback preferences form. */
function photovault_dispatch_account_preferences_action() {
	$request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	$target_path  = wp_parse_url( photovault_get_account_preferences_action_url(), PHP_URL_PATH );
	if ( untrailingslashit( (string) $request_path ) !== untrailingslashit( (string) $target_path ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
		status_header( 405 );
		exit;
	}
	check_admin_referer( 'photovault_save_preferences' );
	photovault_application_save_preferences( get_current_user_id(), wp_unslash( $_POST ) );
	wp_safe_redirect( add_query_arg( 'profile', 'preferences_updated', home_url( '/profile/' ) ) );
	exit;
}
add_action( 'template_redirect', 'photovault_dispatch_account_preferences_action', 0 );

/** Handle public contact and protected-access requests. */
function photovault_application_rest_contact( WP_REST_Request $request ) {
	$params = photovault_application_rest_params( $request );
	$nonce  = sanitize_text_field( $params['photovault_contact_nonce'] ?? '' );

	if ( ! wp_verify_nonce( $nonce, 'photovault_contact_action' ) ) {
		return photovault_application_rest_error(
			new WP_Error( 'invalid_nonce', __( 'La verification de securite a echoue. Actualisez la page puis reessayez.', 'photovault' ) ),
			403
		);
	}

	$request_type = sanitize_key( $params['request_type'] ?? 'general' );
	$types        = photovault_get_contact_request_types();
	if ( ! isset( $types[ $request_type ] ) ) {
		return photovault_application_rest_error( new WP_Error( 'invalid_request_type', __( 'Choisissez un type de demande valide.', 'photovault' ) ) );
	}

	$values = array(
		'name'         => $params['contact_name'] ?? '',
		'email'        => $params['email'] ?? '',
		'request_type' => $request_type,
		'subject'      => $params['contact_subject'] ?? '',
		'collection'   => $params['collection_name'] ?? '',
		'message'      => $params['contact_message'] ?? '',
	);

	if ( 'access' === $request_type ) {
		$folder = get_term_by( 'name', sanitize_text_field( $values['collection'] ), 'media_folder' );
		if ( ! $folder || is_wp_error( $folder ) ) {
			return photovault_application_rest_error( new WP_Error( 'invalid_collection', __( 'Selectionnez une collection PhotoVault existante.', 'photovault' ) ) );
		}
		$values['collection'] = $folder->name;
		$result               = photovault_create_access_request( $values );
		if ( is_wp_error( $result ) ) {
			return photovault_application_rest_error( $result, photovault_application_rest_error_status( $result ) );
		}

		return photovault_application_rest_success(
			__( 'Votre demande d acces a ete enregistree et sera examinee manuellement.', 'photovault' ),
			array( 'request_id' => absint( $result ) ),
			201
		);
	}

	if ( ! photovault_rate_limit( 'contact_message', 5, HOUR_IN_SECONDS ) ) {
		return photovault_application_rest_error( new WP_Error( 'rate_limited', __( 'Veuillez patienter avant d envoyer un nouveau message.', 'photovault' ) ), 429 );
	}
	if ( ! photovault_send_contact_notification( $values ) ) {
		return photovault_application_rest_error( new WP_Error( 'contact_delivery_failed', __( 'Le message n a pas pu etre envoye. Veuillez reessayer plus tard.', 'photovault' ) ), 503 );
	}

	return photovault_application_rest_success( __( 'Votre message a ete envoye avec succes.', 'photovault' ), array(), 201 );
}

/** Create one authenticated shooting request. */
function photovault_application_rest_create_shooting( WP_REST_Request $request ) {
	if ( ! photovault_rate_limit( 'shooting_create', 5, HOUR_IN_SECONDS ) ) {
		return photovault_application_rest_error( new WP_Error( 'rate_limited', __( 'Trop de demandes ont ete envoyees. Reessayez dans une heure.', 'photovault' ) ), 429 );
	}

	$params = photovault_application_rest_params( $request );
	$result = photovault_create_shooting(
		array(
			'type'          => $params['shooting_type'] ?? '',
			'desired_date'  => $params['shooting_date'] ?? '',
			'location'      => $params['shooting_location'] ?? '',
			'message'       => $params['shooting_message'] ?? '',
			'contact_name'  => $params['shooting_contact_name'] ?? '',
			'contact_email' => $params['shooting_contact_email'] ?? '',
			'contact_phone' => $params['shooting_contact_phone'] ?? '',
		)
	);
	if ( is_wp_error( $result ) ) {
		return photovault_application_rest_error( $result, photovault_application_rest_error_status( $result ) );
	}

	return photovault_application_rest_success(
		__( 'Votre demande de shooting a ete envoyee.', 'photovault' ),
		array(
			'shooting_id'  => absint( $result ),
			'dashboard_url' => add_query_arg( 'section', 'bookings', home_url( '/dashboard/' ) ),
		),
		201
	);
}

/** Cancel one authenticated shooting request. */
function photovault_application_rest_cancel_shooting( WP_REST_Request $request ) {
	$shooting_id = absint( $request['id'] );
	$result      = photovault_transition_shooting( $shooting_id, 'cancelled' );
	if ( is_wp_error( $result ) ) {
		return photovault_application_rest_error( $result, photovault_application_rest_error_status( $result ) );
	}

	return photovault_application_rest_success(
		__( 'La reservation a ete annulee.', 'photovault' ),
		array( 'shooting_id' => $shooting_id )
	);
}

/** Save authenticated frontend presentation preferences. */
function photovault_application_rest_save_preferences( WP_REST_Request $request ) {
	$data = photovault_application_save_preferences( get_current_user_id(), photovault_application_rest_params( $request ) );

	return photovault_application_rest_success(
		__( 'Vos preferences d experience ont ete enregistrees.', 'photovault' ),
		$data
	);
}

/** Register public application routes. */
function photovault_register_application_rest_routes() {
	register_rest_route(
		'photovault/v1',
		'/application/contact',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'photovault_application_rest_contact',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'photovault/v1',
		'/application/shootings',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'photovault_application_rest_create_shooting',
			'permission_callback' => 'is_user_logged_in',
		)
	);
	register_rest_route(
		'photovault/v1',
		'/application/shootings/(?P<id>\d+)/cancel',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'photovault_application_rest_cancel_shooting',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
	register_rest_route(
		'photovault/v1',
		'/account/preferences',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'photovault_application_rest_save_preferences',
			'permission_callback' => 'is_user_logged_in',
		)
	);
}
add_action( 'rest_api_init', 'photovault_register_application_rest_routes' );
