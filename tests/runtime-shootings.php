<?php
/**
 * WordPress runtime verification for shooting reservations.
 *
 * Run with: wp eval-file tests/runtime-shootings.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function photovault_shootings_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix           = strtolower( wp_generate_password( 8, false, false ) );
$previous_user_id = get_current_user_id();
$user_ids         = array();
$shooting_ids     = array();
$captured_mail    = array();
$mail_filter      = static function ( $return, $attributes ) use ( &$captured_mail ) {
	$captured_mail[] = $attributes;
	return true;
};
add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );

try {
	photovault_register_roles();
	photovault_register_shooting_post_type();
	foreach ( array( 'owner', 'other' ) as $label ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'pv-shooting-' . $label . '-' . $suffix,
				'user_email' => 'pv-shooting-' . $label . '-' . $suffix . '@photovault.test',
				'user_pass'  => wp_generate_password( 24, true, true ),
				'role'       => 'client',
			)
		);
		photovault_shootings_runtime_assert( ! is_wp_error( $user_id ), 'Runtime shooting user creation failed.' );
		$user_ids[] = (int) $user_id;
	}

	$future_date = wp_date( 'Y-m-d', time() + ( 10 * DAY_IN_SECONDS ) );
	$values = array(
		'type'          => 'portrait',
		'desired_date'  => $future_date,
		'location'      => 'Studio PhotoVault, Porto-Novo',
		'message'       => 'Portrait editorial pour documenter une nouvelle etape professionnelle.',
		'contact_name'  => 'Runtime Owner',
		'contact_email' => get_userdata( $user_ids[0] )->user_email,
		'contact_phone' => '+2290100000000',
	);

	wp_set_current_user( $user_ids[0] );
	if ( function_exists( 'identity_security_kit_email_verified_meta_key' ) ) {
		update_user_meta( $user_ids[0], identity_security_kit_email_verified_meta_key(), '1' );
	}
	$shooting_id = photovault_create_shooting( $values, $user_ids[0] );
	photovault_shootings_runtime_assert( is_int( $shooting_id ) && $shooting_id > 0, 'Valid shooting creation failed.' );
	$shooting_ids[] = $shooting_id;
	photovault_shootings_runtime_assert( 2 === count( $captured_mail ), 'Client and administrator creation emails were not emitted.' );
	photovault_shootings_runtime_assert( false !== strpos( $captured_mail[0]['message'], '<table role="presentation"' ), 'Shooting notification did not use the professional HTML layout.' );
	photovault_shootings_runtime_assert( in_array( 'Content-Type: text/html; charset=UTF-8', $captured_mail[0]['headers'], true ), 'Shooting notification is missing its scoped HTML content type.' );
	photovault_shootings_runtime_assert( 'pending' === photovault_get_shooting_data( $shooting_id )['status'], 'New shooting did not start pending.' );
	photovault_shootings_runtime_assert( array( $shooting_id ) === wp_list_pluck( photovault_get_user_shootings( $user_ids[0] ), 'id' ), 'Owner shooting list did not resolve the reservation.' );

	$duplicate = photovault_create_shooting( $values, $user_ids[0] );
	photovault_shootings_runtime_assert( is_wp_error( $duplicate ) && 'shooting_duplicate' === $duplicate->get_error_code(), 'Duplicate active reservation was accepted.' );
	$invalid_date = $values;
	$invalid_date['desired_date'] = '2020-01-01';
	$invalid = photovault_create_shooting( $invalid_date, $user_ids[0] );
	photovault_shootings_runtime_assert( is_wp_error( $invalid ) && 'shooting_invalid_date' === $invalid->get_error_code(), 'Past reservation date was accepted.' );
	$mismatched_contact = $values;
	$mismatched_contact['contact_email'] = 'someone-else@photovault.test';
	$mismatch = photovault_create_shooting( $mismatched_contact, $user_ids[0] );
	photovault_shootings_runtime_assert( is_wp_error( $mismatch ) && 'shooting_contact_mismatch' === $mismatch->get_error_code(), 'A client could send booking notifications to another email.' );

	wp_set_current_user( $user_ids[1] );
	photovault_shootings_runtime_assert( ! photovault_user_can_read_shooting( $shooting_id, $user_ids[1] ), 'Another client could read the owner reservation.' );
	photovault_shootings_runtime_assert( array() === photovault_get_user_shootings( $user_ids[0] ), 'Another client could list the owner reservations.' );
	$cross_transition = photovault_transition_shooting( $shooting_id, 'cancelled', $user_ids[1] );
	photovault_shootings_runtime_assert( is_wp_error( $cross_transition ) && 'shooting_transition_forbidden' === $cross_transition->get_error_code(), 'Another client could cancel the owner reservation.' );

	wp_set_current_user( $user_ids[0] );
	$owner_confirm = photovault_transition_shooting( $shooting_id, 'confirmed', $user_ids[0] );
	photovault_shootings_runtime_assert( is_wp_error( $owner_confirm ) && 'shooting_transition_forbidden' === $owner_confirm->get_error_code(), 'Owner could self-confirm a reservation.' );
	photovault_shootings_runtime_assert( true === photovault_transition_shooting( $shooting_id, 'cancelled', $user_ids[0] ), 'Owner cancellation failed.' );
	$subjects = wp_list_pluck( $captured_mail, 'subject' );
	photovault_shootings_runtime_assert( in_array( '[PhotoVault] Reservation annulee', $subjects, true ), 'Owner cancellation did not notify the studio.' );

	$values['type'] = 'artistic';
	$values['desired_date'] = wp_date( 'Y-m-d', time() + ( 12 * DAY_IN_SECONDS ) );
	$second_id = photovault_create_shooting( $values, $user_ids[0] );
	photovault_shootings_runtime_assert( is_int( $second_id ), 'Second reservation creation failed.' );
	$shooting_ids[] = $second_id;

	$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	photovault_shootings_runtime_assert( ! empty( $administrators ), 'Administrator required for transition verification.' );
	$admin_id = (int) $administrators[0];
	wp_set_current_user( $admin_id );
	photovault_shootings_runtime_assert( photovault_user_can_read_shooting( $second_id, $admin_id ), 'Administrator could not read all reservations.' );
	photovault_shootings_runtime_assert( true === photovault_transition_shooting( $second_id, 'confirmed', $admin_id ), 'Administrator confirmation failed.' );
	photovault_shootings_runtime_assert( true === photovault_transition_shooting( $second_id, 'completed', $admin_id ), 'Administrator completion failed.' );
	$terminal = photovault_transition_shooting( $second_id, 'cancelled', $admin_id );
	photovault_shootings_runtime_assert( is_wp_error( $terminal ) && 'shooting_invalid_transition' === $terminal->get_error_code(), 'Terminal reservation was changed.' );
	photovault_shootings_runtime_assert( count( $captured_mail ) >= 7, 'Lifecycle notification emails were not emitted.' );
	ob_start();
	photovault_render_shootings_admin_page();
	$admin_html = ob_get_clean();
	photovault_shootings_runtime_assert( false !== strpos( $admin_html, 'Runtime Owner' ) && false !== strpos( $admin_html, 'Reservations de shootings' ), 'Administrator shooting workspace did not render reservation data.' );

	echo wp_json_encode(
		array(
			'creation_validation' => true,
			'ownership_isolation' => true,
			'lifecycle'           => 'pending_confirmed_completed_or_cancelled',
			'admin_access'        => true,
			'admin_workspace'     => true,
			'multipart_email'     => true,
		)
	);
} finally {
	remove_filter( 'pre_wp_mail', $mail_filter, 10 );
	wp_set_current_user( $previous_user_id );
	foreach ( $shooting_ids as $shooting_id ) {
		wp_delete_post( $shooting_id, true );
	}
	if ( $user_ids ) {
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . photovault_get_media_audit_table() . " WHERE user_id IN ({$placeholders}) AND event LIKE %s", array_merge( $user_ids, array( 'shooting_%' ) ) ) );
	}
	foreach ( $user_ids as $user_id ) {
		wp_delete_user( $user_id );
	}
}
