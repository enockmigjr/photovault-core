<?php
/**
 * WordPress runtime verification for PhotoVault business emails.
 *
 * Run with: wp eval-file tests/runtime-email-notifications.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

function photovault_email_runtime_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

global $wpdb;

$suffix          = strtolower( wp_generate_password( 8, false, false ) );
$email           = 'access-email-' . $suffix . '@photovault.test';
$request_id      = 0;
$captured_mail   = array();
$captured_alt    = array();
$previous_remote = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 10, 200 );
$mail_filter = static function ( $attributes ) use ( &$captured_mail ) {
	$captured_mail[] = $attributes;

	return $attributes;
};
$alt_filter = static function ( $phpmailer ) use ( &$captured_alt ) {
	$captured_alt[] = (string) $phpmailer->AltBody;
};
add_filter( 'wp_mail', $mail_filter, 20 );
add_action( 'phpmailer_init', $alt_filter, 20 );

try {
	$fallback_html = photovault_render_transactional_email_html( array( 'title' => array( 'invalid' ), 'details' => array( array( 'invalid' ), 'Safe detail' ) ) );
	photovault_email_runtime_assert( false === strpos( $fallback_html, 'Array' ) && false !== strpos( $fallback_html, 'Safe detail' ), 'The standalone renderer did not reject nested email values safely.' );
	$request_id = photovault_create_access_request(
		array(
			'name'       => 'Runtime Access Email',
			'email'      => $email,
			'subject'    => 'Runtime protected archive',
			'collection' => 'Runtime Collection',
			'message'    => 'Please review this runtime access request.',
		)
	);
	photovault_email_runtime_assert( is_int( $request_id ) && $request_id > 0, 'The access request was not stored.' );
	photovault_email_runtime_assert( 2 === count( $captured_mail ), 'Requester and studio creation notifications were not both sent.' );
	photovault_email_runtime_assert( false !== strpos( $captured_mail[0]['message'], '<table role="presentation"' ), 'The requester email did not use the professional HTML layout.' );
	photovault_email_runtime_assert( in_array( 'Reply-To: ' . $email, $captured_mail[1]['headers'], true ), 'The studio email does not reply to the requester.' );
	photovault_email_runtime_assert( false === strpos( implode( "\n", $captured_mail[1]['headers'] ), 'From: ' . $email ), 'The requester address was incorrectly used as the sender.' );
	photovault_email_runtime_assert( count( array_filter( $captured_alt ) ) >= 2, 'The creation notifications did not receive plain-text alternatives.' );

	$request = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . photovault_get_access_requests_table() . ' WHERE id = %d', $request_id ), ARRAY_A );
	photovault_email_runtime_assert( photovault_send_access_request_decision_email( $request, 'approved' ), 'Approved decision notification failed.' );
	photovault_email_runtime_assert( photovault_send_access_request_decision_email( $request, 'rejected' ), 'Rejected decision notification failed.' );
	photovault_email_runtime_assert( 4 === count( $captured_mail ) && count( array_filter( $captured_alt ) ) >= 4, 'Decision notifications are not complete multipart emails.' );
	photovault_email_runtime_assert( false === photovault_send_access_request_decision_email( $request, 'pending' ), 'An unsupported decision emitted an email.' );

	echo wp_json_encode(
		array(
			'access_acknowledgement' => true,
			'studio_notification'    => true,
			'decision_notifications' => array( 'approved', 'rejected' ),
			'html_layout'            => true,
			'plain_text'             => true,
			'reply_to'               => 'validated',
			'smtp_delivery'          => true,
		)
	);
} finally {
	remove_filter( 'wp_mail', $mail_filter, 20 );
	remove_action( 'phpmailer_init', $alt_filter, 20 );
	if ( $request_id ) {
		$wpdb->delete( photovault_get_access_requests_table(), array( 'id' => $request_id ), array( '%d' ) );
		$audit_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . photovault_get_media_audit_table() . ' WHERE event IN (%s, %s) AND context LIKE %s',
				'access_request_created',
				'access_request_notification_failed',
				'%"request_id":' . $request_id . '%'
			)
		);
		foreach ( $audit_ids as $audit_id ) {
			$wpdb->delete( photovault_get_media_audit_table(), array( 'id' => absint( $audit_id ) ), array( '%d' ) );
		}
	}
	$subject = get_current_user_id() > 0 ? 'u' . get_current_user_id() : 'ip' . wp_hash( $_SERVER['REMOTE_ADDR'] );
	delete_transient( 'pv_rl_access_request_' . md5( $subject ) );
	if ( null === $previous_remote ) {
		unset( $_SERVER['REMOTE_ADDR'] );
	} else {
		$_SERVER['REMOTE_ADDR'] = $previous_remote;
	}
}
