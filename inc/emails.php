<?php
/**
 * Reusable multipart transactional emails for PhotoVault business flows.
 *
 * @package PhotoVault
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Sanitize one scalar email value without accepting nested structures. */
function photovault_sanitize_transactional_email_text( $value, $max_length = 500 ) {
	$value = is_scalar( $value ) ? (string) $value : '';

	return substr( sanitize_text_field( $value ), 0, max( 1, absint( $max_length ) ) );
}

/** Normalize semantic email content for the standalone Core fallback. */
function photovault_prepare_transactional_email_content( $content ) {
	$content = is_array( $content ) ? $content : array();
	$details = isset( $content['details'] ) && is_array( $content['details'] ) ? array_slice( $content['details'], 0, 12 ) : array();
	$details = array_map( 'photovault_sanitize_transactional_email_text', $details );

	return array(
		'preheader'    => photovault_sanitize_transactional_email_text( $content['preheader'] ?? '', 255 ),
		'eyebrow'      => photovault_sanitize_transactional_email_text( $content['eyebrow'] ?? __( 'PhotoVault', 'photovault' ), 80 ),
		'title'        => photovault_sanitize_transactional_email_text( $content['title'] ?? '', 190 ),
		'greeting'     => photovault_sanitize_transactional_email_text( $content['greeting'] ?? '', 190 ),
		'intro'        => photovault_sanitize_transactional_email_text( $content['intro'] ?? '', 1000 ),
		'details'      => array_values( array_filter( $details ) ),
		'action_url'   => esc_url_raw( is_scalar( $content['action_url'] ?? null ) ? (string) $content['action_url'] : '' ),
		'action_label' => photovault_sanitize_transactional_email_text( $content['action_label'] ?? '', 120 ),
		'notice'       => photovault_sanitize_transactional_email_text( $content['notice'] ?? '', 500 ),
	);
}

/** Render the plain-text alternative used by the Core fallback. */
function photovault_render_transactional_email_text( $content ) {
	$content = photovault_prepare_transactional_email_content( $content );
	$lines   = array_filter( array( $content['title'], $content['greeting'], $content['intro'] ), 'strlen' );
	$lines   = array_merge( $lines, $content['details'] );
	if ( $content['action_url'] ) {
		$lines[] = ( $content['action_label'] ? $content['action_label'] . ':' : __( 'Ouvrir :', 'photovault' ) ) . "\n" . $content['action_url'];
	}
	if ( $content['notice'] ) {
		$lines[] = $content['notice'];
	}

	return implode( "\n\n", $lines );
}

/** Render a conservative, email-client-compatible HTML layout. */
function photovault_render_transactional_email_html( $content ) {
	$content = photovault_prepare_transactional_email_content( $content );
	$brand   = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
	ob_start();
	?><!doctype html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head><meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $content['title'] ); ?></title></head>
<body style="margin:0;padding:0;background:#f3f1ec;color:#20231f;font-family:Arial,sans-serif;">
	<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;"><?php echo esc_html( $content['preheader'] ); ?></div>
	<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f1ec;padding:24px 12px;"><tr><td align="center">
		<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:620px;background:#ffffff;border:1px solid #dedbd4;">
			<tr><td style="padding:24px 32px;border-bottom:1px solid #e9e6df;font-family:Georgia,serif;font-size:22px;color:#171a17;"><?php echo esc_html( $brand ); ?></td></tr>
			<tr><td style="padding:40px 32px;">
				<p style="margin:0 0 12px;color:#1f6f54;font-size:12px;font-weight:bold;text-transform:uppercase;"><?php echo esc_html( $content['eyebrow'] ); ?></p>
				<h1 style="margin:0 0 24px;font-family:Georgia,serif;font-size:32px;line-height:1.2;font-weight:normal;color:#171a17;"><?php echo esc_html( $content['title'] ); ?></h1>
				<?php if ( $content['greeting'] ) : ?><p style="margin:0 0 16px;font-size:16px;line-height:1.7;"><?php echo esc_html( $content['greeting'] ); ?></p><?php endif; ?>
				<?php if ( $content['intro'] ) : ?><p style="margin:0 0 16px;font-size:16px;line-height:1.7;"><?php echo esc_html( $content['intro'] ); ?></p><?php endif; ?>
				<?php foreach ( $content['details'] as $detail ) : ?><p style="margin:0 0 12px;font-size:15px;line-height:1.7;color:#4a4f49;"><?php echo esc_html( $detail ); ?></p><?php endforeach; ?>
				<?php if ( $content['action_url'] && $content['action_label'] ) : ?><table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px 0;"><tr><td style="background:#1f6f54;"><a href="<?php echo esc_url( $content['action_url'] ); ?>" style="display:inline-block;padding:14px 22px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;"><?php echo esc_html( $content['action_label'] ); ?></a></td></tr></table><?php endif; ?>
				<?php if ( $content['notice'] ) : ?><div style="margin-top:28px;padding:16px;border-left:3px solid #1f6f54;background:#f7f7f5;font-size:13px;line-height:1.6;color:#4a4f49;"><?php echo esc_html( $content['notice'] ); ?></div><?php endif; ?>
			</td></tr>
			<tr><td style="padding:20px 32px;border-top:1px solid #e9e6df;font-size:12px;line-height:1.6;color:#686d68;"><?php echo esc_html( sprintf( __( 'Message automatique de %s.', 'photovault' ), $brand ) ); ?></td></tr>
		</table>
	</td></tr></table>
</body></html><?php

	return (string) ob_get_clean();
}

/** Send a multipart business email, delegating rendering to Identity Kit when available. */
function photovault_send_transactional_email( $to, $subject, $content, $reply_to = '' ) {
	$to       = sanitize_email( $to );
	$subject  = sanitize_text_field( $subject );
	$reply_to = sanitize_email( $reply_to );
	if ( ! is_email( $to ) || '' === $subject ) {
		return false;
	}
	$identity_supports_reply_to = defined( 'IDENTITY_SECURITY_KIT_VERSION' ) && version_compare( IDENTITY_SECURITY_KIT_VERSION, '0.9.1', '>=' );
	if ( function_exists( 'identity_security_kit_send_transactional_email' ) ) {
		if ( $identity_supports_reply_to ) {
			return identity_security_kit_send_transactional_email( $to, $subject, $content, $reply_to );
		}
		if ( '' === $reply_to ) {
			return identity_security_kit_send_transactional_email( $to, $subject, $content );
		}
	}

	$html     = photovault_render_transactional_email_html( $content );
	$alt_body = photovault_render_transactional_email_text( $content );
	$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
	if ( is_email( $reply_to ) ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}
	$set_alt_body = static function ( $phpmailer ) use ( $alt_body ) {
		$phpmailer->AltBody = $alt_body;
	};
	add_action( 'phpmailer_init', $set_alt_body );
	try {
		return wp_mail( $to, $subject, $html, $headers );
	} finally {
		remove_action( 'phpmailer_init', $set_alt_body );
	}
}
