<?php
/**
 * FNZ Forms – Mailer
 *
 * Handles email sending via wp_mail() and optional SMTP configuration.
 *
 * SMTP priority (first match wins):
 *   1. wp-config.php constants  (FNZ_SMTP_HOST, FNZ_SMTP_PORT, …)
 *   2. Admin UI settings        (stored in wp_options as 'fnz_smtp_config')
 *   3. No SMTP → wp_mail() uses PHP mail() as-is
 */

defined( 'ABSPATH' ) || exit;

// ── SMTP configuration via constants or Admin UI ──────────────────────────────

add_action( 'phpmailer_init', static function ( PHPMailer\PHPMailer\PHPMailer $mailer ): void {

	// 1. wp-config.php constants take priority.
	if ( defined( 'FNZ_SMTP_HOST' ) ) {
		$host = FNZ_SMTP_HOST;
		$port = defined( 'FNZ_SMTP_PORT' )       ? (int) FNZ_SMTP_PORT       : 587;
		$enc  = defined( 'FNZ_SMTP_ENCRYPTION' ) ? FNZ_SMTP_ENCRYPTION       : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
		$user = defined( 'FNZ_SMTP_USERNAME' )   ? FNZ_SMTP_USERNAME         : '';
		$pass = defined( 'FNZ_SMTP_PASSWORD' )   ? FNZ_SMTP_PASSWORD         : '';
	} else {
		// 2. Admin UI (wp_options).
		$opt  = get_option( 'fnz_smtp_config', [] );
		$host = $opt['host']     ?? '';
		if ( empty( $host ) ) return; // Nothing configured.
		$port = isset( $opt['port'] )     ? (int) $opt['port']     : 587;
		$enc  = $opt['encryption']        ?? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
		$user = $opt['username']          ?? '';
		$pass = $opt['password']          ?? '';
	}

	$mailer->isSMTP();
	$mailer->Host       = $host;
	$mailer->Port       = $port;
	$mailer->SMTPSecure = $enc;
	$mailer->SMTPAuth   = true;
	$mailer->Username   = $user;
	$mailer->Password   = $pass;
} );

// ── Main send function ────────────────────────────────────────────────────────

/**
 * Build and dispatch the notification email.
 *
 * @param array  $form    Form config array.
 * @param string $form_id Form ID (used in fallback subject).
 * @param array  $data    Sanitized field data:
 *                        [ 'field_id' => [ 'label' => '...', 'value' => '...' ] ]
 *
 * @return bool  True on success, false on failure.
 */
function fnz_send_mail( array $form, string $form_id, array $data ): bool {

	$to = sanitize_email( $form['to'] ?? '' );

	if ( empty( $to ) ) {
		// Misconfigured form – log and bail.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "[FNZ Forms] form '{$form_id}' has no 'to' address configured." ); // phpcs:ignore
		}
		return false;
	}

	$from_email = sanitize_email( $form['from_email'] ?? get_bloginfo( 'admin_email' ) );
	$from_name  = sanitize_text_field( $form['from_name'] ?? get_bloginfo( 'name' ) );
	$subject    = fnz_interpolate( $form['subject'] ?? "New form submission: {$form_id}", $data );
	$body       = fnz_build_body( $data );

	$headers = [
		'Content-Type: text/plain; charset=UTF-8',
		sprintf( 'From: %s <%s>', $from_name, $from_email ),
	];

	// Set Reply-To to the submitter's email if an 'email' field exists.
	$reply_to = fnz_find_email_value( $data );
	if ( $reply_to ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}

	return wp_mail( $to, $subject, $body, $headers );
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Replace {field_id} tokens in a string with their submitted values.
 * Example subject: "Contact from {firstname} – {email}"
 */
function fnz_interpolate( string $tpl, array $data ): string {
	foreach ( $data as $id => $field ) {
		$tpl = str_replace( '{' . $id . '}', $field['value'], $tpl );
	}
	return $tpl;
}

/**
 * Build a plain-text email body from the submitted fields.
 */
function fnz_build_body( array $data ): string {
	$lines = [];
	foreach ( $data as $field ) {
		$value   = ( '1' === $field['value'] ) ? 'Yes' : $field['value'];
		$lines[] = $field['label'] . ': ' . $value;
	}
	$lines[] = '';
	$lines[] = '---';
	$lines[] = 'Sent via FNZ Forms (' . home_url() . ')';
	return implode( "\n", $lines );
}

/**
 * Return the first field value that looks like an email address, or empty string.
 */
function fnz_find_email_value( array $data ): string {
	if ( isset( $data['email']['value'] ) && is_email( $data['email']['value'] ) ) {
		return $data['email']['value'];
	}
	// Fallback: scan all values.
	foreach ( $data as $field ) {
		if ( is_email( $field['value'] ) ) {
			return $field['value'];
		}
	}
	return '';
}
