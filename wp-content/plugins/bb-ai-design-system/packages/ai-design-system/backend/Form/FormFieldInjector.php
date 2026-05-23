<?php

namespace FL\DesignSystem\Form;

/**
 * Inject per-form hidden spam fields and submission-resolution
 * data attributes into every `<form id="...">` element in a
 * rendered block's HTML.
 *
 * Three hidden inputs are added just inside each form:
 *   - `_fl_hp` — honeypot. Empty by default; visually hidden via
 *     the design system's frontend stylesheet.
 *   - `_fl_ts` — SpamGuard-issued token (HMAC of block_id|form_key|
 *     render_time). Validated server-side at submit.
 *   - `_fl_form_key` — stable submission identifier. Matches the
 *     `key` property on the form's settings entry (a v4 UUID
 *     generated when the form is first configured). When the
 *     settings entry has no key (legacy data), the HTML `id`
 *     attribute is used as the identifier so submissions still
 *     resolve.
 *
 * Two data attributes are added onto the form element:
 *   - `data-fl-block-id` — block identifier (BB node id), used by
 *     the runtime script to build the submit payload without
 *     scraping parent DOM.
 *   - `data-fl-block-post-id` — post id the block was rendered
 *     for, used by the block-settings resolver at submit time to
 *     avoid scanning all layouts.
 *
 * Forms without an `id` attribute are skipped (they can't be
 * routed back to a settings entry). Forms whose settings entry
 * has no configured actions ("None") are also skipped — they
 * render as plain HTML and fall through to native browser submit
 * behavior, so we don't load the runtime for them.
 */
class FormFieldInjector {

	/**
	 * Inject hidden fields + data attrs into every `<form id>` in
	 * the given HTML.
	 *
	 * @param string    $html       Rendered block HTML.
	 * @param string    $block_id   Block/node identifier for the hosting block.
	 * @param int       $post_id    Post being rendered (0 if unknown).
	 * @param SpamGuard $spam_guard SpamGuard for issuing per-form tokens.
	 * @param array     $settings   Resolved block settings map (form_id => entry).
	 *                              Used to look up each form's stable `key`.
	 *                              Empty array falls back to id-based identity.
	 * @return string HTML with forms augmented.
	 */
	public static function inject( string $html, string $block_id, int $post_id, SpamGuard $spam_guard, array $settings = [] ): string {
		if ( '' === $html || false === stripos( $html, '<form' ) ) {
			return $html;
		}

		// Match each <form ...> opening tag. We capture the whole tag
		// so we can extract attributes and rewrite with additions.
		return preg_replace_callback(
			'/<form\b([^>]*)>/i',
			static function ( array $match ) use ( $block_id, $post_id, $spam_guard, $settings ): string {
				$attrs = $match[1];
				$form_id = self::extract_attr_value( $attrs, 'id' );

				if ( null === $form_id || '' === $form_id ) {
					return $match[0];
				}

				// Look up the form's settings entry. Forms without a
				// resolved entry, or whose entry has no configured
				// actions ("None"), are left untouched — no hidden
				// fields, no data attributes, no runtime. They render
				// as plain HTML and use native browser submit.
				$entry   = isset( $settings[ $form_id ] ) && is_array( $settings[ $form_id ] )
					? $settings[ $form_id ]
					: [];
				$actions = isset( $entry['actions'] ) && is_array( $entry['actions'] )
					? $entry['actions']
					: [];
				if ( empty( $actions ) ) {
					return $match[0];
				}

				// A form with an id and at least one configured action
				// survives injection and needs the runtime.
				FormRuntime::mark_needed();

				// Use the settings entry's stable `key` as the submission
				// identifier when present. Falls back to the HTML id for
				// legacy entries that predate stable keys. The same value
				// is signed in the HMAC and echoed to the client so the
				// server can validate and look up consistently.
				$form_key = isset( $entry['key'] ) && is_string( $entry['key'] ) && '' !== $entry['key']
					? $entry['key']
					: $form_id;

				$token = $spam_guard->issue_token( $block_id, $form_key );

				$new_attrs = $attrs;
				$new_attrs = self::ensure_data_attr( $new_attrs, 'data-fl-block-id', $block_id );
				if ( $post_id > 0 ) {
					$new_attrs = self::ensure_data_attr( $new_attrs, 'data-fl-block-post-id', (string) $post_id );
				}

				$hidden = self::build_hidden_fields( $token, $form_key );

				return '<form' . $new_attrs . '>' . $hidden;
			},
			$html
		) ?? $html;
	}

	/**
	 * Build the three hidden inputs injected into every form.
	 *
	 * Honeypot input is marked aria-hidden + tabindex="-1" +
	 * autocomplete="off" so screen readers and keyboard users
	 * skip it. Visual hiding is handled by the frontend CSS
	 * (`.fl-ds-form-hp`).
	 *
	 * @param string $token    SpamGuard-issued timestamp token.
	 * @param string $form_key Stable submission identifier (UUID or id fallback).
	 * @return string HTML snippet of hidden inputs.
	 */
	private static function build_hidden_fields( string $token, string $form_key ): string {
		$hp = '<input type="text" name="_fl_hp" value="" tabindex="-1" autocomplete="off" aria-hidden="true" class="fl-ds-form-hp">';
		$ts = '<input type="hidden" name="_fl_ts" value="' . htmlspecialchars( $token, ENT_QUOTES, 'UTF-8' ) . '">';
		$fk = '<input type="hidden" name="_fl_form_key" value="' . htmlspecialchars( $form_key, ENT_QUOTES, 'UTF-8' ) . '">';
		return $hp . $ts . $fk;
	}

	/**
	 * Pull an attribute value from a raw attribute string.
	 *
	 * Returns null if the attribute is absent. Supports both
	 * double- and single-quoted attribute values.
	 *
	 * @param string $attrs Attribute segment of a tag (everything between the tag name and `>`).
	 * @param string $name  Attribute name to extract.
	 * @return string|null
	 */
	private static function extract_attr_value( string $attrs, string $name ): ?string {
		$pattern = '/\b' . preg_quote( $name, '/' ) . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i';
		if ( preg_match( $pattern, $attrs, $m ) ) {
			return '' !== $m[2] ? $m[2] : ( $m[3] ?? '' );
		}
		return null;
	}

	/**
	 * Add a data attribute if the form does not already carry it.
	 *
	 * Preserves any existing value (integrations may want to
	 * override block_id / post_id).
	 *
	 * @param string $attrs Existing attribute segment.
	 * @param string $name  Attribute name.
	 * @param string $value Attribute value.
	 * @return string Updated attribute segment.
	 */
	private static function ensure_data_attr( string $attrs, string $name, string $value ): string {
		if ( null !== self::extract_attr_value( $attrs, $name ) ) {
			return $attrs;
		}
		$escaped = htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
		return $attrs . ' ' . $name . '="' . $escaped . '"';
	}
}
