<?php

namespace FL\DesignSystem\Settings;

use enshrined\svgSanitize\Sanitizer as EnshrinedSvgSanitizer;
use FL\DesignSystem\Services\Parser\SvgPreserver;

/**
 * Field-type-aware sanitizer for a DS block's `settings` sub-tree.
 *
 * DS blocks store user-editable values under `ds_block_data.settings.*`.
 * Templates routinely emit those values via Mustache triple-stache
 * (`{{{settings.foo}}}`), i.e. raw HTML. A restricted user (no
 * `unfiltered_html`) who can edit settings can therefore land stored XSS
 * on the public front-end unless we sanitize at write time.
 *
 * The sanitizer uses the form schema (the DS block's `form` array, which
 * {@see \FL\DesignSystem\BeaverBuilder\SaveGuard} preserves verbatim from
 * the DB copy) to decide what to do with each leaf:
 *
 *   - `text`, `editor`  → `wp_kses_post` (with inline `style=""` stripped to match
 *     Beaver Builder's existing `verify_settings_kses` posture).
 *   - `svg`             → `enshrined/svg-sanitize` (SVG-aware DOM-level allow-list).
 *   - `link`            → compound walk: `text` via wp_kses_post, `href` via
 *     `esc_url_raw` constrained to a scheme allowlist (rejects `javascript:` /
 *     `data:` / `vbscript:` / etc.), `target` against the safe-target allowlist,
 *     `rel` against a token allowlist (filterable via
 *     `fl_ds_link_rel_allowed_tokens`).
 *   - `image`           → compound walk: `url` via `esc_url_raw` with the
 *     image-context scheme allowlist (`http`, `https`, plus `data:image/...`
 *     for the passive `<img src>` / CSS `background-image` contexts where
 *     SVG scripts do not execute), `alt` via `sanitize_text_field`, `id`
 *     via `absint`, `size` against an allowlist plus registered intermediates.
 *   - Simple-mode types (`url`, `select`, `toggle`, `number`, `textarea`,
 *     `variation-select`, `color`, `rating`, etc.) → pass-through; templates
 *     emit these through Mustache's escaped double-stache.
 *   - Unknown string field types → default-deny via `wp_kses_post` (M-6).
 *     Extensions opt their own custom string types out via the
 *     `fl_ds_sanitizer_raw_field_types` filter.
 *   - Settings keys with no corresponding form field → pass-through; the field
 *     may have been removed, and we can't sanitize a shape we have no type for.
 *
 * The sanitizer is defensive about its input: non-string leaves at positions
 * where a string is expected pass through unchanged, repeater values that
 * aren't indexed arrays pass through, etc. The caller owns whatever runs
 * before / after this (slashing, json encoding, etc.). Only call this when
 * the current user lacks `unfiltered_html` — capable users get raw HTML
 * authoring power via the code tab and nothing here needs to run.
 */
class SettingsSanitizer {

	/**
	 * Field types whose values are rich HTML and get `wp_kses_post`.
	 */
	private const HTML_FIELD_TYPES = [ 'text', 'editor' ];

	/**
	 * Field types whose values are raw `<svg>` markup.
	 */
	private const SVG_FIELD_TYPES = [ 'svg' ];

	/**
	 * Compound `link` field type. Walked sub-fields: text, href, target, rel.
	 */
	private const LINK_FIELD_TYPE = 'link';

	/**
	 * Compound `image` field type. Walked sub-fields: url, alt, id, size.
	 */
	private const IMAGE_FIELD_TYPE = 'image';

	/**
	 * Field types whose values are explicitly known to be simple scalars
	 * that the templates emit via escaped Mustache, so they pass through.
	 * Anything not in this list and not in HTML/SVG/LINK/IMAGE falls into
	 * the default-deny branch in {@see sanitize_leaf} (M-6).
	 *
	 * Note: this is the union of every known v1 field type. Adding a type
	 * here is the in-codebase opt-in path; the
	 * `fl_ds_sanitizer_raw_field_types` filter is the runtime opt-in path
	 * for third-party extensions registering custom types.
	 */
	private const KNOWN_SIMPLE_FIELD_TYPES = [
		'url',
		'select',
		'toggle',
		'number',
		'textarea',
		'variation-select',
		'color',
		'rating',
	];

	/**
	 * Container node types that are transparent — recurse into their
	 * `children` but don't represent a settings key themselves. Mirrors
	 * {@see \FL\DesignSystem\Settings\SettingsResolver::CONTAINER_TYPES}.
	 */
	private const CONTAINER_TYPES = [ 'tab', 'section' ];

	/**
	 * Anchor `target` allowlist. Anything else is dropped (replaced with '').
	 */
	private const SAFE_LINK_TARGETS = [ '_self', '_blank', '_parent', '_top' ];

	/**
	 * Default `rel` token allowlist. Filterable via
	 * `fl_ds_link_rel_allowed_tokens` — themes and plugins that need a
	 * site-specific token (e.g. a tracking attribute) can extend it.
	 */
	private const DEFAULT_LINK_REL_TOKENS = [
		'noopener',
		'noreferrer',
		'nofollow',
		'external',
		'sponsored',
		'ugc',
		'author',
		'help',
		'license',
		'me',
		'next',
		'prev',
		'search',
		'tag',
	];

	/**
	 * Schemes accepted on a `link.href` value. `data:` is intentionally
	 * absent: anchor navigation is an active context, and `data:image/svg+xml`
	 * SVGs *do* execute scripts when navigated to via `<a href>`.
	 */
	private const LINK_HREF_SCHEMES = [ 'http', 'https', 'mailto', 'tel' ];

	/**
	 * Schemes accepted on an `image.url` value. `data:image/...` is allowed
	 * because `<img src>` and `background-image: url(...)` are passive
	 * rendering contexts where the browser does not execute embedded scripts.
	 * AI-generated kit fixtures rely on inline SVG data URIs for icons.
	 */
	private const IMAGE_URL_IMG_MIMES = [ 'png', 'jpeg', 'jpg', 'gif', 'webp', 'avif', 'svg+xml' ];

	/**
	 * WP intermediate-size allowlist for `image.size`. `full` plus the three
	 * core registered sizes; site-registered intermediates are merged in
	 * via `get_intermediate_image_sizes()` when the function is available.
	 */
	private const DEFAULT_IMAGE_SIZES = [ 'thumbnail', 'medium', 'medium_large', 'large', 'full' ];

	/**
	 * Track key types that hit the default-deny branch so we only emit one
	 * debug log per process per type. Avoids flooding error_log on bulk
	 * imports while still surfacing legitimately unrecognized types.
	 *
	 * @var array<string,bool>
	 */
	private array $logged_unknown_types = [];

	/**
	 * Memoized result of {@see resolve_raw_field_types}. Cleared per-request
	 * (this class is short-lived); avoids re-running the filter on every leaf.
	 *
	 * @var array<string,bool>|null
	 */
	private ?array $raw_field_types_cache = null;

	/**
	 * Sanitize a settings tree against a form schema.
	 *
	 * @param array $settings Settings array (associative). Typically
	 *                        `$merged['settings']` from SaveGuard's merge.
	 * @param array $form     DS form schema (the `form` array from
	 *                        `ds_block_data`). Array of top-level form nodes
	 *                        (tabs / sections / fields / repeaters).
	 * @return SanitizationResult
	 */
	public function sanitize( array $settings, array $form ): SanitizationResult {
		$type_map     = [];
		$repeater_map = [];
		$this->build_schema_maps( $form, $type_map, $repeater_map );

		$altered_count = 0;
		$sanitized     = $this->walk_settings( $settings, $type_map, $repeater_map, $altered_count );

		return new SanitizationResult( $sanitized, $altered_count );
	}

	/**
	 * Sanitize a single value as if it were a leaf of the given field type.
	 *
	 * Exposed so callers walking partial trees outside the form-schema
	 * pathway (e.g. {@see \FL\DesignSystem\BeaverBuilder\SaveGuard}'s bindings
	 * walk) can reuse the same per-type logic without re-parsing the schema.
	 *
	 * @param mixed  $value
	 * @param string $type
	 * @param int    $altered_count By-reference counter.
	 * @return mixed
	 */
	public function sanitize_value( $value, string $type, int &$altered_count ) {
		return $this->sanitize_leaf( $value, $type, $altered_count );
	}

	/**
	 * Walk the form schema and build two flat maps keyed by field `key`:
	 *   - $type_map[ key ]    => 'text' | 'editor' | 'svg' | ... (field type)
	 *   - $repeater_map[ key ] => [ child_type_map, child_repeater_map ]
	 *
	 * Tabs/sections are transparent (recurse into `children`). Repeaters
	 * store their own nested schema so per-item walks can resolve types.
	 *
	 * @param array $nodes
	 * @param array $type_map     Output: key => field type.
	 * @param array $repeater_map Output: key => [ type_map, repeater_map ] for items.
	 */
	public function build_schema_maps( array $nodes, array &$type_map, array &$repeater_map ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = $node['type'] ?? '';
			$key  = $node['key'] ?? '';

			if ( in_array( $type, self::CONTAINER_TYPES, true ) ) {
				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					$this->build_schema_maps( $node['children'], $type_map, $repeater_map );
				}
				continue;
			}

			if ( '' === $key ) {
				continue;
			}

			if ( 'repeater' === $type ) {
				$child_type_map     = [];
				$child_repeater_map = [];
				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					$this->build_schema_maps( $node['children'], $child_type_map, $child_repeater_map );
				}
				$repeater_map[ $key ] = [ $child_type_map, $child_repeater_map ];
				continue;
			}

			$type_map[ $key ] = $type;
		}
	}

	/**
	 * Walk a settings associative array, sanitizing leaves per the type map.
	 *
	 * @param array $settings
	 * @param array $type_map      key => field type.
	 * @param array $repeater_map  key => [ child_type_map, child_repeater_map ].
	 * @param int   $altered_count By-reference counter of altered string leaves.
	 * @return array Sanitized settings.
	 */
	private function walk_settings( array $settings, array $type_map, array $repeater_map, int &$altered_count ): array {
		foreach ( $settings as $key => $value ) {
			if ( isset( $repeater_map[ $key ] ) ) {
				[ $child_type_map, $child_repeater_map ] = $repeater_map[ $key ];
				$settings[ $key ]                        = $this->sanitize_repeater_value( $value, $child_type_map, $child_repeater_map, $altered_count );
				continue;
			}

			if ( ! isset( $type_map[ $key ] ) ) {
				// No corresponding form field — pass through. The field may have
				// been removed or this is data we don't own.
				continue;
			}

			$settings[ $key ] = $this->sanitize_leaf( $value, $type_map[ $key ], $altered_count );
		}

		return $settings;
	}

	/**
	 * Sanitize a repeater's stored value — expected to be a list of items,
	 * each item an associative array keyed by child field key.
	 *
	 * @param mixed $value
	 * @param array $child_type_map
	 * @param array $child_repeater_map
	 * @param int   $altered_count
	 * @return mixed
	 */
	private function sanitize_repeater_value( $value, array $child_type_map, array $child_repeater_map, int &$altered_count ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$value[ $index ] = $this->walk_settings( $item, $child_type_map, $child_repeater_map, $altered_count );
		}

		return $value;
	}

	/**
	 * Sanitize one leaf value per its declared field type.
	 *
	 * @param mixed  $value
	 * @param string $type  The field type from the schema.
	 * @param int    $altered_count
	 * @return mixed
	 */
	private function sanitize_leaf( $value, string $type, int &$altered_count ) {
		// Compound types arrive as arrays. Walk them before the
		// non-string short-circuit so the sub-fields get sanitized.
		if ( self::LINK_FIELD_TYPE === $type ) {
			return $this->sanitize_link_value( $value, $altered_count );
		}
		if ( self::IMAGE_FIELD_TYPE === $type ) {
			return $this->sanitize_image_value( $value, $altered_count );
		}

		if ( ! is_string( $value ) ) {
			// Non-string leaves at string-typed positions pass through.
			// Compound types are handled above; arrays/objects at other
			// positions are intentionally not walked.
			return $value;
		}

		if ( in_array( $type, self::HTML_FIELD_TYPES, true ) ) {
			$after = $this->apply_wp_kses_post( $value );
			if ( $after !== $value ) {
				$altered_count++;
			}
			return $after;
		}

		if ( in_array( $type, self::SVG_FIELD_TYPES, true ) ) {
			$after = $this->sanitize_svg_value( $value );
			if ( $after !== $value ) {
				$altered_count++;
			}
			return $after;
		}

		if ( in_array( $type, self::KNOWN_SIMPLE_FIELD_TYPES, true ) ) {
			// Templates emit these via escaped Mustache double-stache.
			return $value;
		}

		if ( $this->is_raw_field_type( $type ) ) {
			// Extension opted this type out of default-deny by registering
			// it via `fl_ds_sanitizer_raw_field_types`. Pass through.
			return $value;
		}

		// M-6: Unknown string field type. Default-deny via wp_kses_post so a
		// new field type added without a sanitizer entry can never become a
		// stored-XSS hole. Log the type once per process so operators can
		// promote legitimate types to KNOWN_SIMPLE_FIELD_TYPES (or to the
		// raw-types filter) once they understand the field's value shape.
		$this->log_unknown_type( $type, $value );
		$after = $this->apply_wp_kses_post( $value );
		if ( $after !== $value ) {
			$altered_count++;
		}
		return $after;
	}

	/**
	 * M-1: Walk a `link` compound. Templates render link sub-fields via
	 * dot-path tokens (`{{{settings.cta.href}}}`, `.text`, etc.), so each
	 * sub-field needs its own context-appropriate sanitizer.
	 *
	 * @param mixed $value
	 * @param int   $altered_count
	 * @return mixed
	 */
	private function sanitize_link_value( $value, int &$altered_count ) {
		if ( ! is_array( $value ) ) {
			// Legacy string-shaped link values pass through; the resolver
			// promotes them to the canonical shape at render time.
			return $value;
		}

		$after = $value;

		if ( array_key_exists( 'text', $value ) && is_string( $value['text'] ) ) {
			$after['text'] = $this->apply_wp_kses_post( $value['text'] );
		}

		if ( array_key_exists( 'href', $value ) && is_string( $value['href'] ) ) {
			$after['href'] = $this->sanitize_link_href( $value['href'] );
		}

		if ( array_key_exists( 'target', $value ) && is_string( $value['target'] ) ) {
			$after['target'] = in_array( $value['target'], self::SAFE_LINK_TARGETS, true ) ? $value['target'] : '';
		}

		if ( array_key_exists( 'rel', $value ) && is_string( $value['rel'] ) ) {
			$after['rel'] = $this->sanitize_link_rel( $value['rel'] );
		}

		if ( $after !== $value ) {
			$altered_count++;
		}
		return $after;
	}

	/**
	 * Constrain a link `href` to the scheme allowlist. Relative URLs (no
	 * scheme) pass through unchanged. Anything that resolves to an unknown
	 * or denylisted scheme returns ''.
	 *
	 * @param string $href
	 * @return string
	 */
	private function sanitize_link_href( string $href ): string {
		$trimmed = trim( $href );
		if ( '' === $trimmed ) {
			return '';
		}

		// Relative URLs (no scheme, including site-rooted "/foo" and
		// fragment-only "#anchor") are safe by construction.
		if ( ! preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $trimmed ) ) {
			return esc_url_raw( $trimmed );
		}

		$scheme = strtolower( (string) parse_url( $trimmed, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, self::LINK_HREF_SCHEMES, true ) ) {
			return '';
		}
		return esc_url_raw( $trimmed, self::LINK_HREF_SCHEMES );
	}

	/**
	 * Tokenize a `rel` value, drop any token outside the (filtered) allowlist,
	 * and collapse whitespace. Returns the cleaned space-joined string.
	 *
	 * @param string $rel
	 * @return string
	 */
	private function sanitize_link_rel( string $rel ): string {
		$allowed = apply_filters( 'fl_ds_link_rel_allowed_tokens', self::DEFAULT_LINK_REL_TOKENS );
		if ( ! is_array( $allowed ) ) {
			$allowed = self::DEFAULT_LINK_REL_TOKENS;
		}
		$allowed_lower = array_map( 'strtolower', $allowed );

		$tokens = preg_split( '/\s+/', strtolower( trim( $rel ) ) );
		if ( false === $tokens ) {
			return '';
		}

		$kept = [];
		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}
			if ( in_array( $token, $allowed_lower, true ) ) {
				$kept[ $token ] = true;
			}
		}
		return implode( ' ', array_keys( $kept ) );
	}

	/**
	 * M-1: Walk an `image` compound. Sub-fields emitted via Mustache:
	 * `url` -> `<img src>` / `background-image`, `alt` -> alt attribute,
	 * `id` -> attachment id, `size` -> registered intermediate size.
	 *
	 * @param mixed $value
	 * @param int   $altered_count
	 * @return mixed
	 */
	private function sanitize_image_value( $value, int &$altered_count ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$after = $value;

		if ( array_key_exists( 'url', $value ) && is_string( $value['url'] ) ) {
			$after['url'] = $this->sanitize_image_url( $value['url'] );
		}

		if ( array_key_exists( 'alt', $value ) && is_string( $value['alt'] ) ) {
			$after['alt'] = sanitize_text_field( $value['alt'] );
		}

		if ( array_key_exists( 'id', $value ) ) {
			$after['id'] = absint( $value['id'] );
		}

		if ( array_key_exists( 'size', $value ) && is_string( $value['size'] ) ) {
			$after['size'] = $this->sanitize_image_size( $value['size'] );
		}

		if ( $after !== $value ) {
			$altered_count++;
		}
		return $after;
	}

	/**
	 * Constrain an image URL to http(s) or `data:image/<allowed-mime>`. The
	 * passive image rendering context is safe for SVG data URIs because the
	 * browser does not execute embedded scripts in `<img src>` or
	 * `background-image: url(...)` contexts.
	 *
	 * @param string $url
	 * @return string
	 */
	private function sanitize_image_url( string $url ): string {
		$trimmed = trim( $url );
		if ( '' === $trimmed ) {
			return '';
		}

		if ( ! preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $trimmed ) ) {
			// Relative URLs pass through.
			return esc_url_raw( $trimmed );
		}

		$scheme = strtolower( (string) parse_url( $trimmed, PHP_URL_SCHEME ) );
		if ( 'http' === $scheme || 'https' === $scheme ) {
			return esc_url_raw( $trimmed, [ 'http', 'https' ] );
		}

		if ( 'data' === $scheme && $this->is_allowed_image_data_uri( $trimmed ) ) {
			// esc_url_raw with a data scheme produces an empty string in some
			// WordPress versions. Validate the shape ourselves and return the
			// trimmed, schema-checked value.
			return $trimmed;
		}

		return '';
	}

	/**
	 * Validate a `data:image/<mime>` URI against the image-MIME allowlist.
	 *
	 * @param string $uri
	 * @return bool
	 */
	private function is_allowed_image_data_uri( string $uri ): bool {
		if ( ! preg_match( '#^data:image/([a-zA-Z0-9.+\-]+)\s*[;,]#i', $uri, $m ) ) {
			return false;
		}
		$mime = strtolower( $m[1] );
		return in_array( $mime, self::IMAGE_URL_IMG_MIMES, true );
	}

	/**
	 * Constrain an image size to the registered allowlist.
	 *
	 * @param string $size
	 * @return string
	 */
	private function sanitize_image_size( string $size ): string {
		$allowed = self::DEFAULT_IMAGE_SIZES;
		if ( function_exists( 'get_intermediate_image_sizes' ) ) {
			$registered = get_intermediate_image_sizes();
			if ( is_array( $registered ) ) {
				$allowed = array_unique( array_merge( $allowed, $registered ) );
			}
		}
		return in_array( $size, $allowed, true ) ? $size : '';
	}

	/**
	 * H-2: Apply the DOM-aware SVG sanitizer to an SVG-typed field value.
	 *
	 * `enshrined/svg-sanitize` parses the SVG, runs an allow-list of elements
	 * and attributes, and re-serializes. This catches attribute-name vectors
	 * that the regex denylist misses (e.g. `xlink:href="javascript:..."`,
	 * `<animate attributeName="href">`).
	 *
	 * Scoped to this leaf-write boundary only. {@see SvgPreserver::restore}
	 * keeps the legacy regex denylist because the parse path runs against
	 * privileged-author SVGs on every render and a stricter pass would
	 * silently degrade legitimate AI-generated kits.
	 *
	 * @param string $svg
	 * @return string
	 */
	private function sanitize_svg_value( string $svg ): string {
		if ( '' === trim( $svg ) ) {
			return $svg;
		}

		// First pass: cheap regex denylist, identical to the parse-path
		// preserver. Catches the obvious vectors and produces a more
		// stable diff when the DOM sanitizer also has nothing to remove.
		$denylisted = SvgPreserver::sanitize_svg( $svg );

		try {
			$sanitizer = new EnshrinedSvgSanitizer();
			$sanitizer->minify( false );
			$sanitized = $sanitizer->sanitize( $denylisted );
			if ( false === $sanitized ) {
				// Library refuses to parse — drop to empty rather than
				// trust raw input. Restricted users see the field clear
				// and can re-enter, which surfaces the corruption to them.
				return '';
			}
			return $sanitized;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[fl-ds-settings-sanitizer] SVG sanitize threw: ' . $e->getMessage() );
			return '';
		}
	}

	/**
	 * Run `wp_kses_post` with inline `style=""` stripped, mirroring BB's
	 * `verify_settings_kses` behavior so a restricted user's inline styles
	 * are handled identically whether they come through SaveGuard or BB's
	 * own per-field kses.
	 */
	private function apply_wp_kses_post( string $value ): string {
		add_filter( 'safe_style_css', '__return_empty_array' );
		$sanitized = wp_kses_post( $value );
		remove_filter( 'safe_style_css', '__return_empty_array' );
		return $sanitized;
	}

	/**
	 * Resolve the `fl_ds_sanitizer_raw_field_types` filter once per request.
	 *
	 * Filter contract: extensions return an array of field type strings whose
	 * string values should bypass the M-6 default-deny. Adding a type here
	 * means the extension takes responsibility for sanitization. Misuse can
	 * introduce stored XSS — only register types whose values are not
	 * rendered as HTML, or whose render path applies its own escape.
	 *
	 * @return array<string,bool>
	 */
	private function resolve_raw_field_types(): array {
		if ( null !== $this->raw_field_types_cache ) {
			return $this->raw_field_types_cache;
		}
		$types = apply_filters( 'fl_ds_sanitizer_raw_field_types', [] );
		if ( ! is_array( $types ) ) {
			$types = [];
		}
		$this->raw_field_types_cache = array_fill_keys( array_filter( $types, 'is_string' ), true );
		return $this->raw_field_types_cache;
	}

	/**
	 * Whether the given field type was opted out of default-deny.
	 *
	 * @param string $type
	 * @return bool
	 */
	private function is_raw_field_type( string $type ): bool {
		return isset( $this->resolve_raw_field_types()[ $type ] );
	}

	/**
	 * Log a default-deny hit at WP_DEBUG level. One line per type per
	 * process, with a truncated sample so operators can identify the field
	 * without the full payload landing in the log.
	 *
	 * @param string $type
	 * @param string $value
	 */
	private function log_unknown_type( string $type, string $value ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( isset( $this->logged_unknown_types[ $type ] ) ) {
			return;
		}
		$this->logged_unknown_types[ $type ] = true;

		$sample = substr( $value, 0, 80 );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'[fl-ds-settings-sanitizer] Unknown field type "%s" hit default-deny. Sample: %s',
			$type,
			$sample
		) );
	}
}
