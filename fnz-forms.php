<?php
/**
 * Plugin Name:  FNZ Forms
 * Description:  Lightweight JSON-configured contact forms with email notification.
 * Version:      1.1.0
 * Requires PHP: 8.0
 * Requires at least: 6.0
 * Author:       Finoz
 * License:      MIT
 * Text Domain:  fnz-forms
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────

define( 'FNZ_FORMS_VERSION', '1.1.0' );
define( 'FNZ_FORMS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'FNZ_FORMS_URL',     plugin_dir_url( __FILE__ ) );

/**
 * GitHub repo for auto-updates (owner/repo).
 * Set this once before publishing. Can be overridden per-site via
 * wp-config.php constant or the 'fnz_forms_github_repo' filter.
 */
define( 'FNZ_FORMS_GITHUB_REPO', 'finoz/wpp-fnz-forms' );

/**
 * Default config path: wp-content/fnz-forms-config.json
 * Stored OUTSIDE the plugin folder so it survives updates.
 * Override with the filter 'fnz_forms_config_path'.
 */
define( 'FNZ_FORMS_CONFIG', WP_CONTENT_DIR . '/fnz-forms-config.json' );

// ── Frontend styles ───────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', static function (): void {
	wp_enqueue_style(
		'fnz-forms',
		FNZ_FORMS_URL . 'assets/fnz-forms.css',
		[],
		FNZ_FORMS_VERSION
	);
} );

// ── Includes ──────────────────────────────────────────────────────────────────

require_once FNZ_FORMS_DIR . 'includes/renderer.php';
require_once FNZ_FORMS_DIR . 'includes/mailer.php';
require_once FNZ_FORMS_DIR . 'includes/updater.php';

if ( is_admin() ) {
	require_once FNZ_FORMS_DIR . 'includes/admin.php';
}

// ── Config loader ─────────────────────────────────────────────────────────────

/**
 * Load and return the full config array (static-cached).
 *
 * Priority: 1) wp_options (edited via admin UI)
 *           2) file at FNZ_FORMS_CONFIG (or filter override)
 *           3) bundled config-example.json
 */
function fnz_forms_config(): array {
	static $cfg = null;

	if ( null !== $cfg ) {
		return $cfg;
	}

	// 1. Admin UI / wp_options takes priority.
	$from_db = get_option( 'fnz_forms_config' );
	if ( $from_db ) {
		$decoded = json_decode( $from_db, true );
		if ( is_array( $decoded ) ) {
			$cfg = $decoded;
			return $cfg;
		}
	}

	// 2. File on disk (default or filter override).
	$path = apply_filters( 'fnz_forms_config_path', FNZ_FORMS_CONFIG );
	if ( ! is_readable( $path ) ) {
		// 3. Bundled example as last resort.
		$path = FNZ_FORMS_DIR . 'config-example.json';
	}

	$decoded = json_decode( file_get_contents( $path ), true ); // phpcs:ignore
	$cfg     = is_array( $decoded ) ? $decoded : [];

	return $cfg;
}


/**
 * Return a single form config by ID, or null if not found.
 */
function fnz_forms_get_form( string $id ): ?array {
	return fnz_forms_config()['forms'][ $id ] ?? null;
}

// ── Shortcode: [fnz_form id="my_form"] ───────────────────────────────────────

add_shortcode( 'fnz_form', static function ( array $atts ): string {

	$atts = shortcode_atts( [ 'id' => '' ], $atts, 'fnz_form' );

	if ( '' === $atts['id'] ) {
		return '<!-- [fnz_form] missing id attribute -->';
	}

	$form = fnz_forms_get_form( $atts['id'] );

	if ( ! $form ) {
		return sprintf(
			'<!-- [fnz_form] form "%s" not found in config -->',
			esc_html( $atts['id'] )
		);
	}

	// Print the JS handler once, in the footer.
	add_action( 'wp_footer', 'fnz_forms_print_script', 20 );

	return fnz_render_form( $atts['id'], $form );
} );

// ── REST API endpoint ─────────────────────────────────────────────────────────

add_action( 'rest_api_init', static function (): void {

	register_rest_route( 'fnz-forms/v1', '/submit', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'fnz_forms_handle_submit',
		'permission_callback' => '__return_true', // public endpoint – security via nonce
	] );

	// Lightweight endpoint that returns a fresh nonce (cache-busting).
	register_rest_route( 'fnz-forms/v1', '/nonce', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => static fn() => new WP_REST_Response(
			[ 'nonce' => wp_create_nonce( 'fnz_submit' ) ]
		),
		'permission_callback' => '__return_true',
	] );
} );

/**
 * Handle a form submission via the REST API.
 */
function fnz_forms_handle_submit( WP_REST_Request $req ): WP_REST_Response {

	// ── 1. Nonce verification ─────────────────────────────────────────────────
	if ( ! wp_verify_nonce( $req->get_header( 'X-FNZ-Nonce' ), 'fnz_submit' ) ) {
		return new WP_REST_Response(
			[ 'success' => false, 'message' => 'Security check failed.' ],
			403
		);
	}

	// ── 2. Resolve form ───────────────────────────────────────────────────────
	$params  = $req->get_json_params() ?? [];
	$form_id = sanitize_key( $params['form_id'] ?? '' );
	$form    = fnz_forms_get_form( $form_id );

	if ( ! $form ) {
		return new WP_REST_Response(
			[ 'success' => false, 'message' => 'Form not found.' ],
			404
		);
	}

	// ── 3. Honeypot check (silent pass to not reveal detection) ───────────────
	if ( ! empty( $params[ '_hp_' . $form_id ] ) ) {
		return new WP_REST_Response(
			[ 'success' => true, 'message' => $form['success_message'] ?? 'Thank you!' ]
		);
	}

	// ── 4. Validate & sanitize fields ─────────────────────────────────────────
	$data   = [];
	$errors = [];

	foreach ( ( $form['fields'] ?? FNZ_DEFAULT_FIELDS ) as $field ) {
		$key   = $form_id . '_' . $field['id'];
		$raw   = $params[ $key ] ?? '';
		$type  = $field['type'] ?? 'text';
		$label = $field['label'] ?? $field['id'];

		$value = match ( $type ) {
			'email'    => sanitize_email( $raw ),
			'number'   => is_numeric( $raw ) ? $raw : '',
			'textarea' => sanitize_textarea_field( $raw ),
			'checkbox' => ! empty( $raw ) ? '1' : '0',
			default    => sanitize_text_field( $raw ),
		};

		$is_empty = ( 'checkbox' === $type ) ? ( '1' !== $value ) : ( '' === $value );

		if ( ! empty( $field['required'] ) && $is_empty ) {
			$errors[] = $label;
		}

		$data[ $field['id'] ] = [ 'label' => $label, 'value' => $value ];
	}

	if ( $errors ) {
		return new WP_REST_Response( [
			'success' => false,
			'message' => sprintf(
				'Required fields missing: %s',
				implode( ', ', $errors )
			),
		], 422 );
	}

	// ── 5. Send mail ──────────────────────────────────────────────────────────
	$sent    = fnz_send_mail( $form, $form_id, $data );
	$message = $sent
		? ( $form['success_message'] ?? 'Thank you! Your message has been sent.' )
		: ( $form['error_message']   ?? 'Something went wrong. Please try again.' );

	return new WP_REST_Response( [ 'success' => $sent, 'message' => $message ], $sent ? 200 : 500 );
}

// ── Footer JS (printed only when a form shortcode is on the page) ─────────────

function fnz_forms_print_script(): void {

	$nonce_endpoint = esc_js( rest_url( 'fnz-forms/v1/nonce' ) );
	$submit_endpoint = esc_js( rest_url( 'fnz-forms/v1/submit' ) );
	$initial_nonce   = esc_js( wp_create_nonce( 'fnz_submit' ) );

	?>
	<script>
	(function () {
		'use strict';

		const SUBMIT_URL    = '<?php echo $submit_endpoint; ?>';
		const NONCE_URL     = '<?php echo $nonce_endpoint; ?>';
		let   currentNonce  = '<?php echo $initial_nonce; ?>';

		/** Refresh nonce from server (handles full-page-cache scenarios). */
		async function freshNonce() {
			try {
				const r = await fetch( NONCE_URL, { credentials: 'same-origin' } );
				const j = await r.json();
				if ( j.nonce ) currentNonce = j.nonce;
			} catch (_) { /* keep existing nonce */ }
		}

		/** Display feedback message below the form. */
		function showMessage( form, text, success ) {
			let el = form.querySelector( '.fnz-message' );
			if ( ! el ) {
				el = document.createElement( 'p' );
				el.className = 'fnz-message';
				form.appendChild( el );
			}
			el.textContent          = text;
			el.dataset.fnzSuccess   = success ? '1' : '0';
		}

		document.querySelectorAll( '.fnz-form' ).forEach( function ( form ) {

			form.addEventListener( 'submit', async function ( e ) {
				e.preventDefault();

				const btn = form.querySelector( '[type="submit"]' );
				if ( btn ) btn.disabled = true;

				// Refresh nonce before each submit (safe with caching).
				await freshNonce();

				// Build payload from FormData.
				const fd   = new FormData( form );
				const body = { form_id: form.dataset.fnzId };
				fd.forEach( function ( v, k ) { body[ k ] = v; } );

				// Unchecked checkboxes are absent from FormData – mark them empty.
				form.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( cb ) {
					if ( ! cb.checked ) body[ cb.name ] = '';
				} );

				try {
					const res  = await fetch( SUBMIT_URL, {
						method:  'POST',
						headers: {
							'Content-Type': 'application/json',
							'X-FNZ-Nonce':  currentNonce,
						},
						body: JSON.stringify( body ),
					} );

					const json = await res.json();
					showMessage( form, json.message, json.success );

					if ( json.success ) {
						form.reset();
					}
				} catch ( err ) {
					showMessage( form, 'Network error. Please try again.', false );
				} finally {
					if ( btn ) btn.disabled = false;
				}
			} );
		} );
	})();
	</script>
	<?php
}
