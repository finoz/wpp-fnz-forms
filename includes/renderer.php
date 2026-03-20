<?php
/**
 * FNZ Forms – HTML renderer
 *
 * Generates the form markup following the project's HTML conventions:
 *   - <label> after <input> for text-like fields (enables CSS floating-label trick)
 *   - <label> wrapping <input> for boolean fields (checkbox / radio)
 *   - No inline styles – CSS is entirely up to the theme
 */

defined( 'ABSPATH' ) || exit;

// ── Default field set (English) ───────────────────────────────────────────────

const FNZ_DEFAULT_FIELDS = [
	[ 'id' => 'firstname', 'type' => 'text',     'label' => 'First name',  'placeholder' => 'Your first name', 'required' => true ],
	[ 'id' => 'lastname',  'type' => 'text',     'label' => 'Last name',   'placeholder' => 'Your last name',  'required' => true ],
	[ 'id' => 'email',     'type' => 'email',    'label' => 'Email',       'placeholder' => 'your@email.com',  'required' => true ],
	[ 'id' => 'message',   'type' => 'textarea', 'label' => 'Message',     'placeholder' => 'Your message…',   'required' => true ],
];

// ── Main render function ──────────────────────────────────────────────────────

/**
 * Build and return the complete form HTML string.
 *
 * @param string $form_id  The form ID from config.
 * @param array  $form     The form config array.
 */
function fnz_render_form( string $form_id, array $form ): string {

	$fields       = $form['fields']       ?? FNZ_DEFAULT_FIELDS;
	$submit_label = $form['submit_label'] ?? 'Submit';

	$html  = sprintf(
		'<form method="post" class="form fnz-form" id="%1$s_form" action="#" novalidate data-fnz-id="%1$s">' . "\n",
		esc_attr( $form_id )
	);

	// Honeypot (hidden from real users; bots fill it; validated server-side).
	$html .= sprintf(
		'<div class="form-group form-group--hp" aria-hidden="true" style="display:none!important">' .
		'<input type="text" name="%s" value="" tabindex="-1" autocomplete="off"></div>' . "\n",
		esc_attr( '_hp_' . $form_id )
	);

	foreach ( $fields as $field ) {
		$html .= fnz_render_field( $form_id, $field );
	}

	$html .= sprintf(
		'<button class="form-cta" type="submit">%s</button>' . "\n",
		esc_html( $submit_label )
	);

	$html .= "</form>\n";

	return $html;
}

// ── Field dispatcher ──────────────────────────────────────────────────────────

/**
 * Route each field to its specific renderer.
 */
function fnz_render_field( string $form_id, array $f ): string {

	$id          = esc_attr( $form_id . '_' . $f['id'] );
	$name        = $id;
	$label       = esc_html( $f['label']       ?? $f['id'] );
	$placeholder = esc_attr( $f['placeholder'] ?? '' );
	$type        = $f['type']     ?? 'text';
	$options     = $f['options']  ?? [];
	$req_attr    = ! empty( $f['required'] ) ? ' required' : '';

	return match ( $type ) {
		'textarea' => fnz_field_textarea( $id, $name, $label, $placeholder, $req_attr ),
		'select'   => fnz_field_select(   $id, $name, $label, $options, $req_attr ),
		'checkbox' => fnz_field_boolean( 'checkbox', $id, $name, $label, $req_attr ),
		'radio'    => fnz_field_radio(    $id, $name, $label, $options, $req_attr ),
		default    => fnz_field_input( $type, $id, $name, $label, $placeholder, $req_attr ),
	};
}

// ── Individual field renderers ────────────────────────────────────────────────

/** text | email | number | tel | url */
function fnz_field_input( string $type, string $id, string $name, string $label, string $ph, string $req ): string {
	return <<<HTML
<div class="form-group">
	<input type="{$type}" id="{$id}" name="{$name}" value="" placeholder="{$ph}"{$req}>
	<label for="{$id}">{$label}</label>
</div>

HTML;
}

function fnz_field_textarea( string $id, string $name, string $label, string $ph, string $req ): string {
	return <<<HTML
<div class="form-group">
	<textarea id="{$id}" name="{$name}" placeholder="{$ph}"{$req}></textarea>
	<label for="{$id}">{$label}</label>
</div>

HTML;
}

function fnz_field_select( string $id, string $name, string $label, array $options, string $req ): string {

	$opts = '';
	foreach ( $options as $opt ) {
		$val   = esc_attr( $opt['value'] ?? $opt['label'] );
		$lbl   = esc_html( $opt['label'] ?? $opt['value'] );
		$opts .= "\t\t<option value=\"{$val}\">{$lbl}</option>\n";
	}

	return <<<HTML
<div class="form-group">
	<select id="{$id}" name="{$name}"{$req}>
		<option value="">{$label}</option>
{$opts}	</select>
	<label for="{$id}">{$label}</label>
</div>

HTML;
}

/** checkbox or single radio used as boolean (no options array needed). */
function fnz_field_boolean( string $type, string $id, string $name, string $label, string $req ): string {
	return <<<HTML
<div class="form-group form-group--boolean">
	<label for="{$id}">
		<input type="{$type}" id="{$id}" name="{$name}"{$req}>
		<span>{$label}</span>
	</label>
</div>

HTML;
}

/** Radio group – requires an 'options' array in the field config. */
function fnz_field_radio( string $id, string $name, string $label, array $options, string $req ): string {

	$inputs = '';
	foreach ( $options as $i => $opt ) {
		$val    = esc_attr( $opt['value'] ?? $opt['label'] );
		$lbl    = esc_html( $opt['label'] ?? $opt['value'] );
		$opt_id = esc_attr( $id . '_' . $i );

		$inputs .= <<<HTML
	<label for="{$opt_id}">
		<input type="radio" id="{$opt_id}" name="{$name}" value="{$val}"{$req}>
		<span>{$lbl}</span>
	</label>

HTML;
	}

	return <<<HTML
<div class="form-group form-group--radio" role="group" aria-label="{$label}">
	<span class="form-group__legend">{$label}</span>
{$inputs}</div>

HTML;
}
