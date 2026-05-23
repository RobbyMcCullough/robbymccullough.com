<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * Annotation Reconstructor
 *
 * Converts a Mustache template + settings back into annotated HTML
 * with data-field attributes and current values filled in.
 *
 * Derives all field metadata from template context — no fieldMap needed.
 * This enables round-trip fidelity: parse -> reconstruct -> parse produces
 * identical output.
 */
class AnnotationReconstructor {

	/**
	 * Reconstructs annotated HTML from a template and settings.
	 *
	 * @param string $template The Mustache template string.
	 * @param array  $settings Current settings values.
	 * @return string|null Annotated HTML string, or null if inputs are invalid.
	 */
	public static function reconstruct( string $template, array $settings ): ?string {
		if ( '' === $template || empty( $settings ) ) {
			return null;
		}

		$html = $template;

		// Expand rating sections before repeaters so the repeater pass doesn't
		// try to iterate a rating's {value, max} associative array as items.
		$html = self::reconstruct_ratings( $html, $settings, true );

		// First, handle repeaters (must be done before simple field replacement
		// because repeater expansion creates new elements that need field annotation)
		$html = self::reconstruct_repeaters( $html, $settings );

		// Link fields use dot-path tokens ({{settings.key.href}}, etc.) which the
		// general token sweep below cannot match. Rewrite them first so the anchor
		// is resolved in one shot.
		$html = self::reconstruct_top_level_links( $html, $settings );

		// CSS-content-field style-attribute tokens (`--settings-KEY: url({{{settings.KEY.url}}})`).
		// Dot-path tokens like link fields, so they need dedicated substitution
		// before the general token sweep. The `var(--settings-KEY)` wrapper in
		// CSS stays as-is and is recognized by the next parse pass (see
		// decision 001). Mirrors the JS reconstructor.
		$html = self::reconstruct_css_background_urls( $html, $settings );

		// Annotate rating parent elements after all repeater iterations have
		// run so markers emitted inside nested repeater items resolve against
		// the fully assembled document.
		$html = self::apply_rating_markers( $html );

		// Then handle top-level fields
		$html = self::reconstruct_top_level_fields( $html, $settings );

		// Defensive guard: any leftover `{{` token at this point indicates a
		// codec drift between parser and reconstructor (e.g., a new compound
		// field type without a matching reconstruct pass). Surface via
		// _doing_it_wrong so it shows up in WP_DEBUG/CI without breaking prod.
		self::warn_on_leftover_mustache( $html );

		return $html;
	}

	/**
	 * Emit a `_doing_it_wrong` warning when the reconstructed HTML still
	 * contains unresolved Mustache tokens. The leftover-`{{` guard is a
	 * regression detector for codec drift (see codec authoring guide in
	 * data/spec/core/codec-authoring-guide.md). Warn only — never throw — so an
	 * unanticipated edge case does not break production rendering.
	 *
	 * @param string $html The fully reconstructed HTML.
	 */
	private static function warn_on_leftover_mustache( string $html ): void {
		$pos = strpos( $html, '{{' );
		if ( false === $pos ) {
			return;
		}

		// Extract ~40 chars of context around the offending token for debuggability.
		$start   = max( 0, $pos - 20 );
		$context = substr( $html, $start, 60 );

		if ( function_exists( '_doing_it_wrong' ) ) {
			_doing_it_wrong(
				self::class . '::reconstruct',
				'Reconstructed HTML contains unresolved Mustache tokens: ' . $context,
				'1.0.0'
			);
		}
	}

	// ─── CSS Content Field Reconstruction ───────────────────────────────

	/**
	 * Replace `{{{settings.KEY.url}}}` tokens inside inline-style
	 * `--settings-*: url(...)` declarations with the current setting value.
	 * Leaves the property name in place so the next parse pass recognizes
	 * the pattern and keeps the CSS-variable binding intact. The match is
	 * keyed by value (the mustache token), not by property name, so legacy
	 * `--bb-*` declarations carrying the same token still resolve — but the
	 * property name itself is opaque and won't round-trip back to a field.
	 *
	 * Mirrors `reconstructCssBackgroundUrls` in the JS reconstructor.
	 *
	 * @param string $html     HTML containing CSS-content-field tokens.
	 * @param array  $settings Top-level settings.
	 * @return string HTML with the URLs resolved.
	 */
	private static function reconstruct_css_background_urls( string $html, array $settings ): string {
		return preg_replace_callback(
			'/url\(\s*\{\{\{\s*settings\.(\w+)\.url\s*\}\}\}\s*\)/',
			function ( $match ) use ( $settings ) {
				$key   = $match[1];
				$value = $settings[ $key ] ?? null;
				$url   = is_array( $value ) ? ( $value['url'] ?? '' ) : '';
				return "url('" . self::escape_attr( (string) $url ) . "')";
			},
			$html
		);
	}

	/**
	 * Bare-token variant used inside repeater-item reconstruction.
	 *
	 * @param string $html          HTML fragment for a repeater item.
	 * @param array  $item_settings Settings for the current repeater item.
	 * @return string HTML with the URLs resolved.
	 */
	private static function reconstruct_css_background_urls_bare( string $html, array $item_settings ): string {
		return preg_replace_callback(
			'/url\(\s*\{\{\{\s*(\w+)\.url\s*\}\}\}\s*\)/',
			function ( $match ) use ( $item_settings ) {
				$key   = $match[1];
				$value = $item_settings[ $key ] ?? null;
				$url   = is_array( $value ) ? ( $value['url'] ?? '' ) : '';
				return "url('" . self::escape_attr( (string) $url ) . "')";
			},
			$html
		);
	}

	// ─── Link Field Reconstruction ──────────────────────────────────────

	/**
	 * Finds `<a>` elements with link-field tokens at top-level scope and
	 * rewrites them with the resolved compound value and a data-field
	 * attribute. Top-level anchors use `settings.` prefix in their tokens.
	 *
	 * The anchor body may contain sibling tokens around the `.text` token
	 * (e.g. `{{{settings.{key}_icon}}}` from an extracted SVG icon — see
	 * `AnnotationParser::extractLinkIconFields`). Those siblings are
	 * preserved verbatim and resolved by the general-purpose field pass.
	 *
	 * @param string $html     HTML containing link-field templates.
	 * @param array  $settings Top-level settings.
	 * @return string HTML with anchors resolved.
	 */
	private static function reconstruct_top_level_links( string $html, array $settings ): string {
		$pattern = '/<a\b([^>]*)>([\s\S]*?)<\/a>/';
		return preg_replace_callback(
			$pattern,
			function ( $match ) use ( $settings ) {
				$attrs_section = $match[1];
				$body          = $match[2];
				if ( ! preg_match( '/\bhref="\{\{settings\.(\w+)\.href\}\}"/', $attrs_section, $href_match ) ) {
					return $match[0];
				}
				$key        = $href_match[1];
				$text_token = '{{{settings.' . $key . '.text}}}';
				if ( false === strpos( $body, $text_token ) ) {
					return $match[0];
				}
				$raw_value = $settings[ $key ] ?? null;
				if ( ! is_array( $raw_value ) ) {
					return $match[0];
				}
				return self::build_anchor( $attrs_section, $key, self::normalize_link_value( $raw_value ), 'settings.', $body );
			},
			$html
		);
	}

	/**
	 * Bare-token variant used inside repeater items. See
	 * `reconstruct_top_level_links` for body-template semantics.
	 *
	 * @param string $html          HTML fragment for a repeater item.
	 * @param array  $item_settings Settings for the current repeater item.
	 * @return string HTML with bare-anchor tokens resolved.
	 */
	private static function reconstruct_bare_links( string $html, array $item_settings ): string {
		$pattern = '/<a\b([^>]*)>([\s\S]*?)<\/a>/';
		return preg_replace_callback(
			$pattern,
			function ( $match ) use ( $item_settings ) {
				$attrs_section = $match[1];
				$body          = $match[2];
				if ( ! preg_match( '/\bhref="\{\{(\w+)\.href\}\}"/', $attrs_section, $href_match ) ) {
					return $match[0];
				}
				$key        = $href_match[1];
				$text_token = '{{{' . $key . '.text}}}';
				if ( false === strpos( $body, $text_token ) ) {
					return $match[0];
				}
				$raw_value = $item_settings[ $key ] ?? null;
				if ( ! is_array( $raw_value ) ) {
					return $match[0];
				}
				return self::build_anchor( $attrs_section, $key, self::normalize_link_value( $raw_value ), '', $body );
			},
			$html
		);
	}

	/**
	 * Assemble a data-field annotated anchor tag. Strips the link-field token
	 * attributes from the incoming attribute section and preserves any
	 * others (class, id, aria-*, etc.). Attribute emission order matches the
	 * JS implementation so the shared HTML normalizer produces identical
	 * strings on both sides.
	 *
	 * @param string $attrs_section The attributes between `<a` and `>` in the template.
	 * @param string $key           The field key.
	 * @param array  $value         The normalized link value (text, href, target, rel).
	 * @param string $prefix        The token prefix (`settings.` or empty).
	 * @return string The reconstructed anchor.
	 */
	private static function build_anchor( string $attrs_section, string $key, array $value, string $prefix, string $body_template ): string {
		$escaped_prefix = preg_quote( $prefix, '/' );
		$href_token     = '/\s*href="\{\{' . $escaped_prefix . preg_quote( $key, '/' ) . '\.href\}\}"/';
		$target_token   = '/\s*target="\{\{' . $escaped_prefix . preg_quote( $key, '/' ) . '\.target\}\}"/';
		$rel_token      = '/\s*rel="\{\{' . $escaped_prefix . preg_quote( $key, '/' ) . '\.rel\}\}"/';

		$preserved_attrs = preg_replace( $href_token, '', $attrs_section );
		$preserved_attrs = preg_replace( $target_token, '', $preserved_attrs );
		$preserved_attrs = preg_replace( $rel_token, '', $preserved_attrs );

		$href = self::sanitize_anchor_href( (string) ( $value['href'] ?? '' ) );

		$result = '<a' . $preserved_attrs . ' data-field="' . $key . '" href="' . self::escape_attr( $href ) . '"';
		if ( ! empty( $value['target'] ) ) {
			$result .= ' target="' . self::escape_attr( $value['target'] ) . '"';
		}
		if ( ! empty( $value['rel'] ) ) {
			$result .= ' rel="' . self::escape_attr( $value['rel'] ) . '"';
		}
		// Replace the text token with the resolved text. Any sibling tokens
		// in the body (e.g. extracted-icon tokens) are left for the
		// general-purpose top-level field pass to resolve.
		$text_token   = '{{{' . $prefix . $key . '.text}}}';
		$body_content = str_replace( $text_token, $value['text'], $body_template );
		$result      .= '>' . $body_content . '</a>';
		return $result;
	}

	/**
	 * M-3: constrain an anchor `href` to a scheme allowlist before it lands
	 * in attribute output. Reject `javascript:`, `data:`, `vbscript:`,
	 * and any other unrecognized scheme. Relative URLs and same-document
	 * fragments (`#section`) pass through.
	 *
	 * Defense-in-depth: {@see \FL\DesignSystem\Settings\SettingsSanitizer}
	 * already drops these schemes at the leaf write boundary for `link`
	 * fields. This guard catches the case where reconstruction reads an
	 * already-stored payload (e.g. data imported pre-fix or via a non-DS
	 * write path).
	 *
	 * @param string $href
	 * @return string
	 */
	private static function sanitize_anchor_href( string $href ): string {
		$trimmed = trim( $href );
		if ( '' === $trimmed ) {
			return '';
		}
		if ( ! preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $trimmed ) ) {
			// Relative or fragment-only URL.
			return $trimmed;
		}
		$allowed_schemes = [ 'http', 'https', 'mailto', 'tel' ];
		$scheme          = strtolower( (string) parse_url( $trimmed, PHP_URL_SCHEME ) );
		return in_array( $scheme, $allowed_schemes, true ) ? $trimmed : '';
	}

	/**
	 * Normalize a raw settings value into the canonical link shape.
	 * Mirrors `frontend/src/core/field-types/normalizers/link.js normalizeLinkValue`.
	 *
	 * @param mixed $value Raw value from settings.
	 * @return array{text: string, href: string, target: string, rel: string}
	 */
	private static function normalize_link_value( $value ): array {
		if ( ! $value ) {
			return [ 'text' => '', 'href' => '', 'target' => '', 'rel' => '' ];
		}
		if ( is_string( $value ) ) {
			return [ 'text' => '', 'href' => $value, 'target' => '', 'rel' => '' ];
		}
		if ( ! is_array( $value ) ) {
			return [ 'text' => '', 'href' => '', 'target' => '', 'rel' => '' ];
		}
		return [
			'text'   => $value['text'] ?? '',
			'href'   => $value['href'] ?? '',
			'target' => $value['target'] ?? '',
			'rel'    => $value['rel'] ?? '',
		];
	}

	// ─── Repeater Reconstruction ────────────────────────────────────────

	/**
	 * Finds and expands top-level Mustache repeater sections into annotated HTML.
	 * Top-level repeaters use the settings. prefix: {{#settings.key}}...{{/settings.key}}
	 *
	 * @param string $html     HTML with Mustache repeater sections.
	 * @param array  $settings Settings values.
	 * @return string HTML with repeaters expanded.
	 */
	private static function reconstruct_repeaters( string $html, array $settings ): string {
		$regex = '/\{\{#settings\.(\w+)\}\}([\s\S]*?)\{\{\/settings\.\1\}\}/';

		$html = preg_replace_callback(
			$regex,
			function ( $match ) use ( $settings ) {
				$repeater_key   = $match[1];
				$inner_template = $match[2];
				$items          = $settings[ $repeater_key ] ?? null;

				if ( ! self::is_list_of_arrays( $items ) ) {
					return $match[0];
				}

				$expanded_items = array_map(
					function ( $item ) use ( $inner_template ) {
						return self::reconstruct_repeater_item( $inner_template, $item );
					},
					$items
				);

				return '<!--__bb_repeater_' . $repeater_key . '__-->'
				. implode( '', $expanded_items )
				. '<!--/__bb_repeater_' . $repeater_key . '__-->';
			},
			$html
		);

		// Add data-repeater to parent elements using marker technique
		$html = self::apply_repeater_markers( $html );

		return $html;
	}

	/**
	 * Finds and expands nested Mustache repeater sections (bare keys, no settings. prefix).
	 *
	 * @param string $html          HTML with nested repeater sections.
	 * @param array  $item_settings Settings for the current repeater item.
	 * @return string HTML with nested repeaters expanded.
	 */
	private static function reconstruct_nested_repeaters( string $html, array $item_settings ): string {
		$regex = '/\{\{#(\w+)\}\}([\s\S]*?)\{\{\/\1\}\}/';

		return preg_replace_callback(
			$regex,
			function ( $match ) use ( $item_settings ) {
				$nested_key     = $match[1];
				$inner_template = $match[2];
				$nested_items   = $item_settings[ $nested_key ] ?? null;

				if ( ! self::is_list_of_arrays( $nested_items ) ) {
					return $match[0];
				}

				$expanded_items = array_map(
					function ( $nested_item ) use ( $inner_template ) {
						return self::reconstruct_repeater_item( $inner_template, $nested_item );
					},
					$nested_items
				);

				return '<!--__bb_repeater_' . $nested_key . '__-->'
				. implode( '', $expanded_items )
				. '<!--/__bb_repeater_' . $nested_key . '__-->';
			},
			$html
		);
	}

	/**
	 * Reconstructs a single repeater item from the inner template.
	 *
	 * @param string $inner_template The template for a single repeater item.
	 * @param array  $item_settings  Settings for this item.
	 * @return string Annotated HTML for the item.
	 */
	private static function reconstruct_repeater_item( string $inner_template, array $item_settings ): string {
		$html = $inner_template;

		// Substitute the {{{_variation}}} class token with the per-item
		// variation value before any other rewriting. Mirrors
		// `reconstructVariation` in the JS reconstructor.
		$html = self::reconstruct_variation( $html, $item_settings );

		// Expand rating sections before nested repeaters so rating {value, max}
		// objects aren't mistaken for repeater item lists.
		$html = self::reconstruct_ratings( $html, $item_settings, false );

		// Expand nested repeaters first
		$html = self::reconstruct_nested_repeaters( $html, $item_settings );

		// Link fields (dot-path bare tokens) — rewrite before the general sweep.
		$html = self::reconstruct_bare_links( $html, $item_settings );

		// CSS-content-field inline-style tokens (bare form inside repeater items).
		$html = self::reconstruct_css_background_urls_bare( $html, $item_settings );

		// Replace bare-key field tokens with values and add annotations
		$html = self::replace_bare_tokens( $html, $item_settings );

		// Add data-repeater-item to the first element in this item
		$html = preg_replace( '/^(\s*<\w+)/', '$1 data-repeater-item', $html );

		return $html;
	}

	/**
	 * Substitute the {{{_variation}}} class token in a repeater item with the
	 * per-item variation value. Mirrors `reconstructVariation` in JS at
	 * frontend/src/core/services/annotation-reconstructor.js.
	 *
	 * When the variation is empty, also consume the preceding space so the
	 * class attribute doesn't end up with a trailing space (the parser emits
	 * `class="shared {{{_variation}}}"` for every item, even items without
	 * a unique class).
	 *
	 * @param string $html          The repeater-item HTML fragment.
	 * @param array  $item_settings Settings for the current item.
	 * @return string HTML with the variation token resolved.
	 */
	private static function reconstruct_variation( string $html, array $item_settings ): string {
		if ( ! array_key_exists( '_variation', $item_settings ) ) {
			return $html;
		}
		$variation = $item_settings['_variation'];
		if ( '' === $variation ) {
			return preg_replace( '/ ?\{\{\{_variation\}\}\}/', '', $html, 1 );
		}
		return preg_replace( '/\{\{\{_variation\}\}\}/', (string) $variation, $html, 1 );
	}

	// ─── Marker Processing ──────────────────────────────────────────────

	/**
	 * Scans for repeater markers and adds data-repeater to their parent elements,
	 * then removes the markers.
	 *
	 * @param string $html HTML with repeater markers.
	 * @return string HTML with data-repeater attributes added and markers removed.
	 */
	private static function apply_repeater_markers( string $html ): string {
		$marker_regex = '/<!--__bb_repeater_(\w+)__-->/';
		$marker_keys  = array();

		if ( preg_match_all( $marker_regex, $html, $matches ) ) {
			$marker_keys = array_unique( $matches[1] );
		}

		foreach ( $marker_keys as $key ) {
			$open_marker  = '<!--__bb_repeater_' . $key . '__-->';
			$close_marker = '<!--/__bb_repeater_' . $key . '__-->';

			while ( false !== strpos( $html, $open_marker ) ) {
				$html = self::add_data_repeater_to_parent( $html, $key, $open_marker, $close_marker );
			}
		}

		return $html;
	}

	/**
	 * Adds data-repeater attribute to the parent element of a repeater marker,
	 * then removes the markers.
	 *
	 * @param string $html         Full HTML string.
	 * @param string $repeater_key The repeater key.
	 * @param string $open_marker  The opening marker comment.
	 * @param string $close_marker The closing marker comment.
	 * @return string HTML with data-repeater added and markers removed.
	 */
	private static function add_data_repeater_to_parent( string $html, string $repeater_key, string $open_marker, string $close_marker ): string {
		$marker_index = strpos( $html, $open_marker );
		if ( false === $marker_index ) {
			return $html;
		}

		// Walk backward from the marker to find the opening tag of the parent element
		$before_marker = substr( $html, 0, $marker_index );
		$last_tag_open = strrpos( $before_marker, '<' );

		if ( false === $last_tag_open ) {
			return $html;
		}

		$tag_end = strpos( $html, '>', $last_tag_open );
		if ( false === $tag_end ) {
			return $html;
		}

		$parent_tag = substr( $html, $last_tag_open, $tag_end - $last_tag_open + 1 );

		// Add data-repeater to the parent tag
		$annotated_tag = preg_replace( '/^(<\w+)/', '$1 data-repeater="' . $repeater_key . '"', $parent_tag );

		$html = substr( $html, 0, $last_tag_open ) . $annotated_tag . substr( $html, $tag_end + 1 );

		// Remove markers (positions shift after tag insertion, so re-find)
		$html = self::str_replace_first( $open_marker, '', $html );
		$html = self::str_replace_first( $close_marker, '', $html );

		return $html;
	}

	// ─── Rating Reconstruction ──────────────────────────────────────────

	/**
	 * Expands rating sections in an HTML fragment into repeated active/inactive
	 * markup, and emits markers so the parent element can be re-annotated.
	 *
	 * Rating sections come from the parser as `{{#key}}...{{#active}}...{{/active}}
	 * ...{{^active}}...{{/active}}...{{/key}}` with settings shaped
	 * `['value' => N, 'max' => M]`. Without this pass, `reconstruct_repeaters` /
	 * `reconstruct_nested_repeaters` would try to iterate the associative
	 * settings array as a list of items and fail.
	 *
	 * @param string $html         HTML fragment that may contain rating sections.
	 * @param array  $settings     Settings at the current context level.
	 * @param bool   $is_top_level True for the document root (uses `settings.` prefix),
	 *                             false when processing a repeater item.
	 * @return string HTML with rating sections expanded and markers inserted.
	 */
	private static function reconstruct_ratings( string $html, array $settings, bool $is_top_level ): string {
		foreach ( $settings as $key => $value ) {
			if ( ! self::is_rating_value( $value ) ) {
				continue;
			}

			$rating_value = (int) $value['value'];
			$rating_max   = (int) $value['max'];
			$token_name   = $is_top_level ? 'settings.' . $key : $key;
			$escaped      = preg_quote( $token_name, '/' );
			$section_re   = '/\{\{#' . $escaped . '\}\}([\s\S]*?)\{\{\/' . $escaped . '\}\}/';

			$html = preg_replace_callback(
				$section_re,
				function ( $match ) use ( $key, $rating_value, $rating_max ) {
					$inner           = $match[1];
					$active_markup   = '';
					$inactive_markup = '';

					if ( preg_match( '/\{\{#active\}\}([\s\S]*?)\{\{\/active\}\}/', $inner, $active_match ) ) {
						$active_markup = $active_match[1];
					}
					if ( preg_match( '/\{\{\^active\}\}([\s\S]*?)\{\{\/active\}\}/', $inner, $inactive_match ) ) {
						$inactive_markup = $inactive_match[1];
					}

					// Captured markup carries indentation text nodes before and
					// after the star element. Trim both ends: leading whitespace
					// would produce blank lines between repeated stars; trailing
					// whitespace on the last star produces a blank line before </div>.
					$active_markup   = trim( $active_markup, "\n\t " );
					$inactive_markup = trim( $inactive_markup, "\n\t " );

					$expanded = '';
					for ( $i = 0; $i < $rating_max; $i++ ) {
						$expanded .= ( $i < $rating_value ) ? $active_markup : $inactive_markup;
					}

					return '<!--__bb_rating:' . $key . ':' . $rating_max . '__-->'
						. $expanded
						. '<!--/__bb_rating:' . $key . ':' . $rating_max . '__-->';
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Scans for rating markers and adds data-field / data-field-type / data-field-max
	 * to their parent elements, then removes the markers.
	 *
	 * @param string $html HTML with rating markers.
	 * @return string HTML with rating annotations applied and markers removed.
	 */
	private static function apply_rating_markers( string $html ): string {
		$marker_regex = '/<!--__bb_rating:(\w+):(\d+)__-->/';

		if ( ! preg_match_all( $marker_regex, $html, $matches, PREG_SET_ORDER ) ) {
			return $html;
		}

		$seen = array();
		foreach ( $matches as $m ) {
			$seen[ $m[1] . ':' . $m[2] ] = array( $m[1], $m[2] );
		}

		foreach ( $seen as $pair ) {
			list( $key, $max ) = $pair;
			$open_marker       = '<!--__bb_rating:' . $key . ':' . $max . '__-->';
			$close_marker      = '<!--/__bb_rating:' . $key . ':' . $max . '__-->';

			while ( false !== strpos( $html, $open_marker ) ) {
				$html = self::add_rating_attrs_to_parent( $html, $key, $max, $open_marker, $close_marker );
			}
		}

		return $html;
	}

	/**
	 * Adds rating field annotations to the parent element of a rating marker,
	 * then removes the markers.
	 *
	 * @param string $html         Full HTML string.
	 * @param string $key          The rating field key.
	 * @param string $max          The rating maximum value.
	 * @param string $open_marker  The opening marker comment.
	 * @param string $close_marker The closing marker comment.
	 * @return string HTML with rating annotations added and markers removed.
	 */
	private static function add_rating_attrs_to_parent( string $html, string $key, string $max, string $open_marker, string $close_marker ): string {
		$marker_index = strpos( $html, $open_marker );
		if ( false === $marker_index ) {
			return $html;
		}

		$before_marker = substr( $html, 0, $marker_index );
		$last_tag_open = strrpos( $before_marker, '<' );
		if ( false === $last_tag_open ) {
			return $html;
		}

		$tag_end = strpos( $html, '>', $last_tag_open );
		if ( false === $tag_end ) {
			return $html;
		}

		$parent_tag = substr( $html, $last_tag_open, $tag_end - $last_tag_open + 1 );

		$annotated_tag = preg_replace(
			'/^(<\w+)/',
			'$1 data-field="' . $key . '" data-field-type="rating" data-field-max="' . $max . '"',
			$parent_tag
		);

		$html = substr( $html, 0, $last_tag_open ) . $annotated_tag . substr( $html, $tag_end + 1 );

		$html = self::str_replace_first( $open_marker, '', $html );
		$html = self::str_replace_first( $close_marker, '', $html );

		return $html;
	}

	// ─── Top-Level Field Reconstruction ─────────────────────────────────

	/**
	 * Replaces top-level Mustache tokens (settings. prefix) with annotated HTML.
	 *
	 * @param string $html     HTML with Mustache tokens.
	 * @param array  $settings Settings values.
	 * @return string HTML with tokens replaced and annotations added.
	 */
	private static function reconstruct_top_level_fields( string $html, array $settings ): string {
		// Find all settings tokens — both raw ({{}}) and URL-encoded (%7B%7B%7D%7D)
		// DOMDocument URL-encodes curly braces inside src/href attributes.
		$token_regex = '/(?:\{\{\{?|%7B%7B%7B?)settings\.(\w+)(?:\}\}\}?|%7D%7D%7D?)/';
		$tokens      = array();

		if ( preg_match_all( $token_regex, $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$is_triple = str_starts_with( $match[0], '{{{' ) || str_starts_with( $match[0], '%7B%7B%7B' );
				$tokens[]  = array(
					'key'             => $match[1],
					'full_token'      => $match[0],
					'is_triple_curly' => $is_triple,
				);
			}
		}

		// Deduplicate by key
		$seen = array();
		foreach ( $tokens as $token ) {
			if ( isset( $seen[ $token['key'] ] ) ) {
				continue;
			}
			$seen[ $token['key'] ] = true;

			$value = $settings[ $token['key'] ] ?? '';

			$html = self::replace_top_level_token( $html, $token, $value );
		}

		return $html;
	}

	/**
	 * Replaces a single top-level token based on its context in the HTML.
	 *
	 * @param string       $html  Full HTML string.
	 * @param array        $token Token info (key, full_token, is_triple_curly).
	 * @param string|array $value The setting value (string or compound image array).
	 * @return string HTML with the token replaced.
	 */
	private static function replace_top_level_token( string $html, array $token, $value ): string {
		$key             = $token['key'];
		$full_token      = $token['full_token'];
		$is_triple_curly = $token['is_triple_curly'];
		$escaped_token   = preg_quote( $full_token, '/' );

		// Build a pattern that matches both raw and URL-encoded forms of the token.
		$encoded_token = preg_quote( urlencode( $full_token ), '/' );
		$either_token  = '(?:' . $escaped_token . '|' . $encoded_token . ')';

		// Check if token is in a src attribute (image field)
		$src_pattern = '/(<[a-zA-Z][a-zA-Z0-9]*\b[^>]*?)src="' . $either_token . '"([^>]*?)(\s*\/?>)/';
		if ( preg_match( $src_pattern, $html ) ) {
			$url = is_array( $value ) ? ( $value['url'] ?? '' ) : (string) $value;
			$alt = is_array( $value ) ? ( $value['alt'] ?? '' ) : '';
			return preg_replace_callback(
				$src_pattern,
				function ( $match ) use ( $key, $url, $alt ) {
					$attrs_after = $match[2];
					$close       = $match[3];
					// The parser always emits an alt token; always resolve it
					// (even to "") so the round-trip never leaves a Mustache
					// leftover. Only add a fresh alt attribute when one isn't
					// already in the template AND the value is non-empty.
					if ( preg_match( '/\balt="[^"]*"/', $attrs_after ) ) {
						$attrs_after = preg_replace( '/\balt="[^"]*"/', 'alt="' . self::escape_attr( $alt ) . '"', $attrs_after );
					} elseif ( '' !== $alt ) {
						$attrs_after .= ' alt="' . self::escape_attr( $alt ) . '"';
					}
					return $match[1] . 'data-field="' . $key . '" src="' . self::escape_attr( $url ) . '"' . $attrs_after . $close;
				},
				$html
			);
		}

		// Ensure value is a string for subsequent processing
		$value = is_array( $value ) ? ( $value['url'] ?? '' ) : (string) $value;

		// Check if token is in an href attribute (URL field)
		$href_pattern = '/href="' . $either_token . '"/';
		if ( preg_match( $href_pattern, $html ) ) {
			return preg_replace_callback(
				$href_pattern,
				function () use ( $key, $value ) {
					return 'data-field-href="' . $key . '" href="' . self::escape_attr( $value ) . '"';
				},
				$html
			);
		}

		// Content token — check if it's a standalone SVG replacement. Skip
		// when the token is wrapped in an element with data-field-type="svg"
		// (the wrapper owns the field; the inner SVG is just the value).
		if ( $is_triple_curly && self::is_svg_value( $value ) && ! self::is_wrapped_in_svg_type_attr( $html, $escaped_token ) ) {
			// In mixed SVG+text wrappers the surrounding element already
			// carries data-field for the text portion; the inner SVG is a
			// sub-extraction without its own annotation.
			$wrapper_owns_field = self::is_wrapped_in_data_field_attr( $html, $escaped_token );
			$standalone_pattern = '/' . $escaped_token . '/';
			return preg_replace_callback(
				$standalone_pattern,
				function () use ( $key, $value, $wrapper_owns_field ) {
					return $wrapper_owns_field ? $value : self::add_data_field_to_svg( $value, $key );
				},
				$html
			);
		}

		// Content token inside an element (triple curly)
		if ( $is_triple_curly ) {
			$pattern = '/(<([a-zA-Z][a-zA-Z0-9]*)(\b[^>]*?)>)' . $escaped_token . '(<\/)/';
			if ( preg_match( $pattern, $html ) ) {
				return preg_replace_callback(
					$pattern,
					function ( $match ) use ( $key, $value ) {
						// Skip inferred data-field-type when the wrapper already carries one.
						$existing_attrs  = $match[3];
						$data_field_type = preg_match( '/\bdata-field-type=/', $existing_attrs )
							? ''
							: self::infer_data_field_type_from_value( $value );
						return '<' . $match[2] . $existing_attrs . ' data-field="' . $key . '"' . $data_field_type . '>' . $value . $match[4];
					},
					$html
				);
			}
			// Fallback: token sits among sibling content (e.g. mixed SVG+text);
			// the wrapper already carries data-field, so just emit the value.
			return preg_replace( '/' . $escaped_token . '/', self::escape_text( $value ), $html );
		}

		// Double curly: token is in text content
		$pattern = '/(<([a-zA-Z][a-zA-Z0-9]*)(\b[^>]*?)>)([^<]*?)' . $escaped_token . '([^<]*?)(<\/)/';
		return preg_replace_callback(
			$pattern,
			function ( $match ) use ( $key, $value ) {
				return '<' . $match[2] . $match[3] . ' data-field="' . $key . '">' . $match[4] . self::escape_text( $value ) . $match[5] . $match[6];
			},
			$html
		);
	}

	/**
	 * Detect whether a triple-curly token sits inside a wrapper element
	 * carrying data-field-type="svg" — the wrapper claims the field, so the
	 * standalone-SVG path must not run.
	 *
	 * @param string $html          Template HTML.
	 * @param string $escaped_token preg_quote'd token.
	 */
	private static function is_wrapped_in_svg_type_attr( string $html, string $escaped_token ): bool {
		return (bool) preg_match(
			'/<[a-zA-Z][^>]*\bdata-field-type="svg"[^>]*>\s*' . $escaped_token . '\s*<\//',
			$html
		);
	}

	/**
	 * Detect whether a token sits inside an element that already carries a
	 * data-field attribute (mixed SVG+text wrappers, where the wrapper owns
	 * the annotation). Matches a data-field opener anywhere before the
	 * token without an intervening `</` to ensure the wrapper still encloses it.
	 *
	 * @param string $html          HTML being reconstructed.
	 * @param string $escaped_token preg_quote'd token.
	 */
	private static function is_wrapped_in_data_field_attr( string $html, string $escaped_token ): bool {
		return (bool) preg_match(
			'/<[a-zA-Z][^>]*\bdata-field="[^"]+"[^>]*>(?:(?!<\/).)*?' . $escaped_token . '/s',
			$html
		);
	}

	// ─── Bare Token Replacement (Repeater Items) ────────────────────────

	/**
	 * Replaces bare-key Mustache tokens within a repeater item template.
	 *
	 * @param string $html          HTML with bare Mustache tokens.
	 * @param array  $item_settings Settings for the repeater item.
	 * @return string HTML with tokens replaced.
	 */
	private static function replace_bare_tokens( string $html, array $item_settings ): string {
		// Find all bare tokens
		$token_regex = '/\{\{\{?(\w+)\}\}\}?/';
		$tokens      = array();

		if ( preg_match_all( $token_regex, $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				// Skip section tokens (they start with # or /)
				if ( preg_match( '/\{\{[#\/]/', $match[0] ) ) {
					continue;
				}
				$tokens[] = array(
					'key'             => $match[1],
					'full_token'      => $match[0],
					'is_triple_curly' => str_starts_with( $match[0], '{{{' ),
				);
			}
		}

		$seen = array();
		foreach ( $tokens as $token ) {
			if ( isset( $seen[ $token['key'] ] ) ) {
				continue;
			}
			$seen[ $token['key'] ] = true;

			$value = $item_settings[ $token['key'] ] ?? '';

			$html = self::replace_bare_token( $html, $token, $value );
		}

		return $html;
	}

	/**
	 * Replaces a single bare-key token based on its context.
	 *
	 * @param string       $html  HTML string.
	 * @param array        $token Token info (key, full_token, is_triple_curly).
	 * @param string|array $value The setting value (string or compound image array).
	 * @return string HTML with the token replaced.
	 */
	private static function replace_bare_token( string $html, array $token, $value ): string {
		$key             = $token['key'];
		$full_token      = $token['full_token'];
		$is_triple_curly = $token['is_triple_curly'];
		$escaped_token   = preg_quote( $full_token, '/' );

		// Build a pattern that matches both raw and URL-encoded forms of the token.
		$encoded_token = preg_quote( urlencode( $full_token ), '/' );
		$either_token  = '(?:' . $escaped_token . '|' . $encoded_token . ')';

		// Check if token is in a src attribute
		$src_pattern = '/(<[a-zA-Z][a-zA-Z0-9]*\b[^>]*?)src="' . $either_token . '"([^>]*?)(\s*\/?>)/';
		if ( preg_match( $src_pattern, $html ) ) {
			$url = is_array( $value ) ? ( $value['url'] ?? '' ) : (string) $value;
			$alt = is_array( $value ) ? ( $value['alt'] ?? '' ) : '';
			return preg_replace_callback(
				$src_pattern,
				function ( $match ) use ( $key, $url, $alt ) {
					$attrs_after = $match[2];
					$close       = $match[3];
					// The parser always emits an alt token; always resolve it
					// (even to "") so the round-trip never leaves a Mustache
					// leftover. Only add a fresh alt attribute when one isn't
					// already in the template AND the value is non-empty.
					if ( preg_match( '/\balt="[^"]*"/', $attrs_after ) ) {
						$attrs_after = preg_replace( '/\balt="[^"]*"/', 'alt="' . self::escape_attr( $alt ) . '"', $attrs_after );
					} elseif ( '' !== $alt ) {
						$attrs_after .= ' alt="' . self::escape_attr( $alt ) . '"';
					}
					return $match[1] . 'data-field="' . $key . '" src="' . self::escape_attr( $url ) . '"' . $attrs_after . $close;
				},
				$html
			);
		}

		// Ensure value is a string for subsequent processing
		$value = is_array( $value ) ? ( $value['url'] ?? '' ) : (string) $value;

		// Check if token is in an href attribute
		$href_pattern = '/href="' . $either_token . '"/';
		if ( preg_match( $href_pattern, $html ) ) {
			return preg_replace_callback(
				$href_pattern,
				function () use ( $key, $value ) {
					return 'data-field-href="' . $key . '" href="' . self::escape_attr( $value ) . '"';
				},
				$html
			);
		}

		// SVG replacement
		if ( $is_triple_curly && self::is_svg_value( $value ) ) {
			$standalone_pattern = '/' . $escaped_token . '/';
			return preg_replace_callback(
				$standalone_pattern,
				function () use ( $key, $value ) {
					return self::add_data_field_to_svg( $value, $key );
				},
				$html
			);
		}

		// Content token inside an element (triple curly)
		if ( $is_triple_curly ) {
			$pattern         = '/(<([a-zA-Z][a-zA-Z0-9]*)(\b[^>]*?)>)' . $escaped_token . '(<\/)/';
			$data_field_type = self::infer_data_field_type_from_value( $value );
			return preg_replace_callback(
				$pattern,
				function ( $match ) use ( $key, $value, $data_field_type ) {
					return '<' . $match[2] . $match[3] . ' data-field="' . $key . '"' . $data_field_type . '>' . $value . $match[4];
				},
				$html
			);
		}

		// Double curly: token is in text content
		$pattern = '/(<([a-zA-Z][a-zA-Z0-9]*)(\b[^>]*?)>)([^<]*?)' . $escaped_token . '([^<]*?)(<\/)/';
		return preg_replace_callback(
			$pattern,
			function ( $match ) use ( $key, $value ) {
				return '<' . $match[2] . $match[3] . ' data-field="' . $key . '">' . $match[4] . self::escape_text( $value ) . $match[5] . $match[6];
			},
			$html
		);
	}

	// ─── SVG Helpers ────────────────────────────────────────────────────

	/**
	 * Adds data-field attribute to an SVG string.
	 *
	 * @param string $svg_content The SVG markup.
	 * @param string $field_key   The field key.
	 * @return string SVG with data-field attribute added.
	 */
	private static function add_data_field_to_svg( string $svg_content, string $field_key ): string {
		if ( '' === $svg_content || ! str_starts_with( $svg_content, '<svg' ) ) {
			return '<svg data-field="' . $field_key . '">' . $svg_content . '</svg>';
		}
		// Idempotent: the parser captures SVG via outerHTML before stripping
		// annotation attrs, so the stored value already carries data-field.
		// Don't add a second one when round-tripping.
		if ( preg_match( '/^<svg\s[^>]*?\bdata-field=/', $svg_content ) ) {
			return $svg_content;
		}
		return preg_replace( '/^<svg/', '<svg data-field="' . $field_key . '"', $svg_content );
	}

	/**
	 * Checks if a value looks like SVG content.
	 *
	 * @param string $value The value to check.
	 * @return bool True if the value starts with <svg.
	 */
	private static function is_svg_value( string $value ): bool {
		return str_starts_with( ltrim( $value ), '<svg' );
	}

	// ─── Type Inference ─────────────────────────────────────────────────

	/**
	 * Infers the data-field-type attribute from the field value content.
	 *
	 * @param string $value The field value.
	 * @return string A string like ' data-field-type="editor"' or empty string.
	 */
	private static function infer_data_field_type_from_value( string $value ): string {
		// SVG content inside an element
		if ( str_starts_with( ltrim( $value ), '<svg' ) ) {
			return ' data-field-type="svg"';
		}

		// Rich HTML content (editor)
		if ( preg_match( '/<(strong|em|a |a>|br|ul|ol|li|b>|b |i>|i |p>|p )/i', $value ) ) {
			return ' data-field-type="editor"';
		}

		return '';
	}

	// ─── Shape Checks ───────────────────────────────────────────────────

	/**
	 * Checks whether a value matches the rating field shape ({value, max}).
	 *
	 * Mirrors the frontend `isRatingValue` and server-side
	 * `MustacheEngine::is_rating_value`. The `url` key is excluded so compound
	 * image values (`{url, alt, id, size}`) aren't misidentified.
	 *
	 * @param mixed $value The value to check.
	 * @return bool True if the value is a rating object.
	 */
	private static function is_rating_value( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		return array_key_exists( 'value', $value )
			&& array_key_exists( 'max', $value )
			&& is_numeric( $value['value'] )
			&& ! array_key_exists( 'url', $value );
	}

	/**
	 * Checks whether a value is a non-empty sequential list whose every element
	 * is itself an array. This is the shape the repeater reconstruction code
	 * expects for iteration.
	 *
	 * @param mixed $value The value to check.
	 * @return bool True if the value is a list of arrays.
	 */
	private static function is_list_of_arrays( $value ): bool {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return false;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) ) {
				return false;
			}
		}
		return true;
	}

	// ─── Utility Helpers ────────────────────────────────────────────────

	/**
	 * Escapes text content for safe insertion into HTML text nodes.
	 *
	 * @param string $str The string to escape.
	 * @return string Escaped string.
	 */
	private static function escape_text( string $str ): string {
		return htmlspecialchars( $str, ENT_NOQUOTES, 'UTF-8', false );
	}

	/**
	 * Escapes a value for safe insertion into an HTML attribute.
	 *
	 * @param string $str The string to escape.
	 * @return string Escaped string.
	 */
	private static function escape_attr( string $str ): string {
		return htmlspecialchars( $str, ENT_QUOTES, 'UTF-8', false );
	}

	/**
	 * Replaces only the first occurrence of a string.
	 *
	 * @param string $search  The string to search for.
	 * @param string $replace The replacement string.
	 * @param string $subject The string to search in.
	 * @return string The string with the first occurrence replaced.
	 */
	private static function str_replace_first( string $search, string $replace, string $subject ): string {
		$pos = strpos( $subject, $search );
		if ( false === $pos ) {
			return $subject;
		}
		return substr_replace( $subject, $replace, $pos, strlen( $search ) );
	}
}
