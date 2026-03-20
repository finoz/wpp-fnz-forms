<?php
/**
 * FNZ Forms – Admin area
 *
 * Loaded only when is_admin() is true (see fnz-forms.php).
 * Adds:
 *   - Persistent admin notice when SMTP is not configured.
 *   - Settings > FNZ Forms with:
 *       · Status banners
 *       · JSON config editor (saved to wp_options)
 *       · SMTP settings form (saved to wp_options, fallback to wp-config.php constants)
 *       · README rendered as HTML
 */

defined( 'ABSPATH' ) || exit;

// ── Admin notice: SMTP not configured ────────────────────────────────────────

add_action( 'admin_notices', static function (): void {

	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( fnz_smtp_is_configured() ) return;
	if ( get_user_meta( get_current_user_id(), 'fnz_smtp_notice_dismissed', true ) ) return;
	// Already on the settings page — status is shown there, no need to duplicate.
	if ( ( $_GET['page'] ?? '' ) === 'fnz-forms' ) return;

	$docs_url    = admin_url( 'options-general.php?page=fnz-forms#fnz-smtp' );
	$dismiss_url = wp_nonce_url( add_query_arg( 'fnz_dismiss_smtp_notice', '1' ), 'fnz_dismiss_smtp' );

	printf(
		'<div class="notice notice-warning"><p>' .
		'<strong>FNZ Forms:</strong> SMTP not configured — emails may not be delivered reliably. ' .
		'<a href="%s"><strong>Configure SMTP →</strong></a> &nbsp; ' .
		'<a href="%s" style="color:#999">Dismiss</a>' .
		'</p></div>',
		esc_url( $docs_url ),
		esc_url( $dismiss_url )
	);
} );

add_action( 'admin_init', static function (): void {
	if ( isset( $_GET['fnz_dismiss_smtp_notice'] ) && check_admin_referer( 'fnz_dismiss_smtp' ) ) {
		update_user_meta( get_current_user_id(), 'fnz_smtp_notice_dismissed', '1' );
		wp_safe_redirect( remove_query_arg( [ 'fnz_dismiss_smtp_notice', '_wpnonce' ] ) );
		exit;
	}
} );

// ── Save: JSON config ─────────────────────────────────────────────────────────

add_action( 'admin_init', static function (): void {

	if (
		! isset( $_POST['fnz_save_config'] ) ||
		! check_admin_referer( 'fnz_save_config', 'fnz_config_nonce' ) ||
		! current_user_can( 'manage_options' )
	) return;

	$raw = wp_unslash( $_POST['fnz_config_json'] ?? '' );

	json_decode( $raw );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		set_transient( 'fnz_config_save_error', json_last_error_msg(), 30 );
		set_transient( 'fnz_config_save_input', $raw, 30 );
		wp_safe_redirect( add_query_arg( 'fnz_saved', '0', wp_get_referer() ) );
		exit;
	}

	$pretty = json_encode(
		json_decode( $raw ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	);
	update_option( 'fnz_forms_config', $pretty, false );
	wp_safe_redirect( add_query_arg( 'fnz_saved', 'config', wp_get_referer() ) );
	exit;
} );

// ── Save: SMTP settings ───────────────────────────────────────────────────────

add_action( 'admin_init', static function (): void {

	if (
		! isset( $_POST['fnz_save_smtp'] ) ||
		! check_admin_referer( 'fnz_save_smtp', 'fnz_smtp_nonce' ) ||
		! current_user_can( 'manage_options' )
	) return;

	$existing = get_option( 'fnz_smtp_config', [] );

	$data = [
		'host'       => sanitize_text_field( $_POST['fnz_smtp_host']       ?? '' ),
		'port'       => absint(              $_POST['fnz_smtp_port']       ?? 587 ),
		'encryption' => sanitize_key(        $_POST['fnz_smtp_encryption'] ?? 'tls' ),
		'username'   => sanitize_text_field( $_POST['fnz_smtp_username']   ?? '' ),
		// Keep the existing password if the field was left blank.
		'password'   => ( '' !== ( $_POST['fnz_smtp_password'] ?? '' ) )
			? sanitize_text_field( wp_unslash( $_POST['fnz_smtp_password'] ) )
			: ( $existing['password'] ?? '' ),
	];

	update_option( 'fnz_smtp_config', $data, false );
	// Reset the per-user dismissal so the notice re-evaluates.
	delete_user_meta( get_current_user_id(), 'fnz_smtp_notice_dismissed' );

	wp_safe_redirect( add_query_arg( 'fnz_saved', 'smtp', wp_get_referer() ) . '#fnz-smtp' );
	exit;
} );

// ── Helper: is SMTP configured? (DB or constants) ────────────────────────────

function fnz_smtp_is_configured(): bool {
	if ( defined( 'FNZ_SMTP_HOST' ) ) return true;
	$opt = get_option( 'fnz_smtp_config', [] );
	return ! empty( $opt['host'] );
}

// ── Admin menu page ───────────────────────────────────────────────────────────

add_action( 'admin_menu', static function (): void {
	add_options_page( 'FNZ Forms', 'FNZ Forms', 'manage_options', 'fnz-forms', 'fnz_forms_admin_page' );
} );

function fnz_forms_admin_page(): void {

	$readme = FNZ_FORMS_DIR . 'README.md';
	$md     = is_readable( $readme ) ? file_get_contents( $readme ) : ''; // phpcs:ignore

	echo '<div class="wrap"><h1>FNZ Forms</h1>';

	// ── Save feedback ─────────────────────────────────────────────────────────
	if ( isset( $_GET['fnz_saved'] ) ) {
		match ( $_GET['fnz_saved'] ) {
			'config' => print( '<div class="notice notice-success is-dismissible"><p>✓ Configuration saved.</p></div>' ),
			'smtp'   => print( '<div class="notice notice-success is-dismissible"><p>✓ SMTP settings saved.</p></div>' ),
			default  => printf(
				'<div class="notice notice-error is-dismissible"><p>✗ Invalid JSON — %s</p></div>',
				esc_html( get_transient( 'fnz_config_save_error' ) ?: 'unknown error' )
			),
		};
	}

	// ── Status row ────────────────────────────────────────────────────────────
	echo '<div style="display:flex;gap:1em;flex-wrap:wrap;margin:1.5em 0">';

	// SMTP
	if ( fnz_smtp_is_configured() ) {
		$source = defined( 'FNZ_SMTP_HOST' ) ? 'wp-config.php' : 'Admin UI';
		$host   = defined( 'FNZ_SMTP_HOST' ) ? FNZ_SMTP_HOST : ( get_option( 'fnz_smtp_config', [] )['host'] ?? '' );
		printf(
			'<div class="notice notice-success inline" style="margin:0;flex:1"><p>✓ SMTP — <code>%s</code> <em style="color:#888">(%s)</em></p></div>',
			esc_html( $host ), esc_html( $source )
		);
	} else {
		echo '<div class="notice notice-warning inline" style="margin:0;flex:1"><p>⚠ SMTP not configured — <a href="#fnz-smtp">configure below</a></p></div>';
	}

	// Config
	$forms  = fnz_forms_config()['forms'] ?? [];
	$source = get_option( 'fnz_forms_config' ) ? 'Admin UI' : 'File / example';
	if ( $forms ) {
		$ids = implode( ' ', array_map(
			static fn( $id ) => '<code>[fnz_form id=&quot;' . esc_attr( $id ) . '&quot;]</code>',
			array_keys( $forms )
		) );
		printf(
			'<div class="notice notice-success inline" style="margin:0;flex:1"><p>✓ %d form(s) — %s <em style="color:#888">(%s)</em></p></div>',
			count( $forms ), $ids, esc_html( $source )
		);
	} else {
		echo '<div class="notice notice-info inline" style="margin:0;flex:1"><p>ℹ No forms configured yet — edit the JSON below.</p></div>';
	}

	echo '</div>'; // end status row

	// ═══════════════════════════════════════════════════════════════════════
	// SECTION 1 – JSON Config editor
	// ═══════════════════════════════════════════════════════════════════════
	echo '<hr style="margin:2em 0"><h2>Form configuration</h2>';
	echo '<p style="color:#555;max-width:700px">Define your forms in JSON. '
		. 'Saved here, it takes priority over any file on disk. '
		. 'See the <a href="#fnz-docs">documentation</a> for the full field reference.</p>';

	// Determine textarea content.
	$db_value     = get_option( 'fnz_forms_config', '' );
	$failed_input = get_transient( 'fnz_config_save_input' );

	if ( false !== $failed_input ) {
		$textarea_value = $failed_input;
		delete_transient( 'fnz_config_save_input' );
	} elseif ( $db_value ) {
		$textarea_value = $db_value;
	} else {
		$path    = apply_filters( 'fnz_forms_config_path', FNZ_FORMS_CONFIG );
		$path    = is_readable( $path ) ? $path : FNZ_FORMS_DIR . 'config-example.json';
		$decoded = json_decode( file_get_contents( $path ), true ); // phpcs:ignore
		unset( $decoded['_comment'] );
		$textarea_value = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	$page_url = esc_url( admin_url( 'options-general.php?page=fnz-forms' ) );

	?>
	<form method="post" action="<?php echo $page_url; ?>" style="max-width:860px">
		<?php wp_nonce_field( 'fnz_save_config', 'fnz_config_nonce' ); ?>
		<textarea
			name="fnz_config_json"
			id="fnz-config-json"
			rows="28"
			spellcheck="false"
			style="width:100%;font-family:monospace;font-size:13px;line-height:1.6;background:#1e1e1e;color:#d4d4d4;padding:1em;border-radius:4px;border:1px solid #555;resize:vertical"
		><?php echo esc_textarea( $textarea_value ); ?></textarea>
		<p style="display:flex;align-items:center;gap:1em;margin-top:.5em">
			<button type="submit" name="fnz_save_config" class="button button-primary">Save configuration</button>
			<span id="fnz-json-status" style="font-size:13px"></span>
		</p>
	</form>
	<script>
	(function () {
		const ta = document.getElementById('fnz-config-json');
		const ms = document.getElementById('fnz-json-status');
		if (!ta || !ms) return;
		ta.addEventListener('input', function () {
			try   { JSON.parse(ta.value); ms.textContent = '✓ Valid JSON'; ms.style.color = '#46b450'; }
			catch (e) { ms.textContent = '✗ ' + e.message; ms.style.color = '#dc3232'; }
		});
	})();
	</script>

	<?php

	// ═══════════════════════════════════════════════════════════════════════
	// SECTION 2 – SMTP settings
	// ═══════════════════════════════════════════════════════════════════════
	echo '<hr style="margin:2em 0"><h2 id="fnz-smtp">SMTP settings</h2>';

	$constants_active = defined( 'FNZ_SMTP_HOST' );
	$db_smtp          = get_option( 'fnz_smtp_config', [] );

	if ( $constants_active ) {
		echo '<div class="notice notice-info inline" style="margin:0 0 1.5em"><p>'
			. '⚙ SMTP is currently driven by constants in <code>wp-config.php</code>. '
			. 'Those take priority over the fields below. '
			. 'Remove the <code>FNZ_SMTP_*</code> constants to switch to Admin UI management.'
			. '</p></div>';
	} else {
		echo '<p style="color:#555;max-width:700px">Credentials are stored in the database. '
			. 'If you prefer to keep them in <code>wp-config.php</code> (slightly more secure), '
			. 'leave these fields empty and add the <code>FNZ_SMTP_*</code> constants instead — '
			. 'see the <a href="#fnz-docs">documentation</a>. '
			. 'The two approaches can coexist: constants always win.</p>';
	}

	// Display values: prefer DB, show constants as read-only placeholder if active.
	$v_host = $constants_active ? FNZ_SMTP_HOST                        : ( $db_smtp['host']       ?? '' );
	$v_port = $constants_active ? ( defined('FNZ_SMTP_PORT') ? FNZ_SMTP_PORT : 587 ) : ( $db_smtp['port'] ?? 587 );
	$v_enc  = $constants_active ? ( defined('FNZ_SMTP_ENCRYPTION') ? FNZ_SMTP_ENCRYPTION : 'tls' ) : ( $db_smtp['encryption'] ?? 'tls' );
	$v_user = $constants_active ? ( defined('FNZ_SMTP_USERNAME') ? FNZ_SMTP_USERNAME : '' ) : ( $db_smtp['username'] ?? '' );
	$ro     = $constants_active ? ' disabled' : '';

	?>
	<form method="post" action="<?php echo $page_url; ?>#fnz-smtp" style="max-width:560px">
		<?php wp_nonce_field( 'fnz_save_smtp', 'fnz_smtp_nonce' ); ?>
		<table class="form-table" style="margin-top:0">
			<tr>
				<th scope="row"><label for="fnz_smtp_host">Host</label></th>
				<td><input type="text" id="fnz_smtp_host" name="fnz_smtp_host" class="regular-text"
					value="<?php echo esc_attr( $v_host ); ?>"
					placeholder="smtp.brevo.com"<?php echo $ro; ?>></td>
			</tr>
			<tr>
				<th scope="row"><label for="fnz_smtp_port">Port</label></th>
				<td><input type="number" id="fnz_smtp_port" name="fnz_smtp_port" class="small-text"
					value="<?php echo esc_attr( $v_port ); ?>"
					placeholder="587"<?php echo $ro; ?>></td>
			</tr>
			<tr>
				<th scope="row"><label for="fnz_smtp_encryption">Encryption</label></th>
				<td>
					<select id="fnz_smtp_encryption" name="fnz_smtp_encryption"<?php echo $ro; ?>>
						<option value="tls" <?php selected( $v_enc, 'tls' ); ?>>TLS (port 587)</option>
						<option value="ssl" <?php selected( $v_enc, 'ssl' ); ?>>SSL (port 465)</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fnz_smtp_username">Username</label></th>
				<td><input type="text" id="fnz_smtp_username" name="fnz_smtp_username" class="regular-text"
					value="<?php echo esc_attr( $v_user ); ?>"
					placeholder="your@email.com"<?php echo $ro; ?>>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="fnz_smtp_password">Password</label></th>
				<td>
					<input type="password" id="fnz_smtp_password" name="fnz_smtp_password" class="regular-text"
						value=""
						autocomplete="new-password"
						placeholder="<?php echo $constants_active ? '(set via wp-config.php)' : ( isset( $db_smtp['password'] ) && $db_smtp['password'] ? '(saved — leave blank to keep)' : 'SMTP password' ); ?>"
						<?php echo $ro; ?>>
					<?php if ( ! $constants_active && ! empty( $db_smtp['password'] ) ) : ?>
						<p class="description">Password is saved. Leave blank to keep the current one.</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php if ( ! $constants_active ) : ?>
		<p><button type="submit" name="fnz_save_smtp" class="button button-primary">Save SMTP settings</button></p>
		<?php else : ?>
		<p class="description">Fields are read-only while <code>FNZ_SMTP_*</code> constants are defined.</p>
		<?php endif; ?>
	</form>

	<?php

	// ═══════════════════════════════════════════════════════════════════════
	// SECTION 3 – Documentation
	// ═══════════════════════════════════════════════════════════════════════
	echo '<hr style="margin:2em 0" id="fnz-docs"><h2>Documentation</h2>';
	echo '<div style="max-width:860px">';
	echo $md ? fnz_md_to_html( $md ) : '<p>README.md not found.</p>'; // phpcs:ignore
	echo '</div></div>';
}

// ── Markdown → HTML renderer ──────────────────────────────────────────────────
//
// Strategy: extract code blocks and tables into a placeholder map FIRST,
// run all inline transformations on the remainder, then restore.
// This prevents regexes like bold/links from mangling code content.

function fnz_md_to_html( string $md ): string {

	$slots = []; // placeholder_token => html_string
	$idx   = 0;

	$slot = static function ( string $html ) use ( &$slots, &$idx ): string {
		$token          = "\x02SLOT{$idx}\x03";
		$slots[ $token ] = $html;
		$idx++;
		return $token;
	};

	// ── 1. Fenced code blocks  ```lang\n…\n```  ───────────────────────────────
	$md = preg_replace_callback(
		'/^```(\w*)\n([\s\S]*?)^```[ \t]*$/m',
		static function ( array $m ) use ( $slot ): string {
			$lang = $m[1] ? ' class="language-' . esc_attr( $m[1] ) . '"' : '';
			return $slot(
				'<pre style="background:#f6f8fa;border:1px solid #e1e4e8;padding:1em;border-radius:4px;overflow:auto;font-size:13px">'
				. '<code' . $lang . '>' . esc_html( $m[2] ) . '</code></pre>'
			);
		},
		$md
	);

	// ── 2. Inline code  `…`  ──────────────────────────────────────────────────
	$md = preg_replace_callback(
		'/`([^`\n]+)`/',
		static fn( array $m ) => $slot( '<code style="background:#f6f8fa;padding:.2em .4em;border-radius:3px;font-size:.9em">' . esc_html( $m[1] ) . '</code>' ),
		$md
	);

	// ── 3. Tables  | … | \n | --- | \n | … |  ────────────────────────────────
	$md = preg_replace_callback(
		'/^(\|.+\|[ \t]*\n)\|[-| :\t]+\|[ \t]*\n((?:\|.+\|[ \t]*\n?)+)/m',
		static function ( array $m ) use ( $slot ): string {

			$parse_row = static function ( string $row ): array {
				// Strip leading/trailing pipes, split on |, trim each cell.
				return array_map( 'trim', explode( '|', trim( $row, " \t|\n" ) ) );
			};

			$th_cells = $parse_row( $m[1] );
			$thead    = '<thead><tr>';
			foreach ( $th_cells as $cell ) {
				$thead .= '<th style="text-align:left;padding:.45em .9em;border-bottom:2px solid #ddd;white-space:nowrap">' . $cell . '</th>';
			}
			$thead .= '</tr></thead>';

			$tbody = '<tbody>';
			foreach ( array_filter( explode( "\n", trim( $m[2] ) ) ) as $row ) {
				$tbody .= '<tr>';
				foreach ( $parse_row( $row ) as $cell ) {
					$tbody .= '<td style="padding:.4em .9em;border-bottom:1px solid #eee">' . $cell . '</td>';
				}
				$tbody .= '</tr>';
			}
			$tbody .= '</tbody>';

			return $slot(
				'<table style="border-collapse:collapse;width:100%;margin:1em 0">'
				. $thead . $tbody . '</table>'
			);
		},
		$md
	);

	// ── 4. Headers  ───────────────────────────────────────────────────────────
	$md = preg_replace( '/^### (.+)$/m', '<h3>$1</h3>', $md );
	$md = preg_replace( '/^## (.+)$/m',  '<h2 style="border-bottom:1px solid #ddd;padding-bottom:.3em;margin-top:1.8em">$1</h2>', $md );
	$md = preg_replace( '/^# (.+)$/m',   '<h1>$1</h1>', $md );

	// ── 5. Inline styles  ─────────────────────────────────────────────────────
	$md = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md );
	$md = preg_replace( '/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $md );
	$md = preg_replace( '/^---$/m', '<hr>', $md );

	// ── 6. Paragraphs  ────────────────────────────────────────────────────────
	$blocks = preg_split( '/\n{2,}/', trim( $md ) );
	$html   = '';
	foreach ( $blocks as $block ) {
		$block = trim( $block );
		if ( '' === $block ) continue;
		// Already-HTML blocks (tags or slot tokens) pass through as-is.
		if ( preg_match( '/^(<|\x02SLOT)/', $block ) ) {
			$html .= $block . "\n\n";
		} else {
			$html .= '<p style="line-height:1.65">' . nl2br( $block ) . "</p>\n\n";
		}
	}

	// ── 7. Restore placeholders  ──────────────────────────────────────────────
	return str_replace( array_keys( $slots ), array_values( $slots ), $html );
}
