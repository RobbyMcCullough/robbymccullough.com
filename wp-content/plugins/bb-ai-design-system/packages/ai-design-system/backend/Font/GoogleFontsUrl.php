<?php

namespace FL\DesignSystem\Font;

/**
 * Builds Google Fonts CSS2 URLs from normalized font entries.
 *
 * Expects input in the canonical {@see FontEntry::normalize()} shape:
 *
 *     [ [ 'family' => 'Fraunces', 'variants' => 'ital,wght@0,400;0,700' ], ... ]
 *
 * Empty `variants` falls back to a pinned default weight set so legacy
 * DSes (pre-variant storage) render byte-identically to the previous
 * builder output.
 */
class GoogleFontsUrl {

	/**
	 * Default variant spec for entries without a stored variant string.
	 *
	 * Pinned to match the legacy FontProvider::build_google_fonts_url
	 * default so existing DSes produce byte-identical URLs.
	 */
	public const DEFAULT_VARIANTS = 'wght@400;500;600;700';

	/**
	 * Build a Google Fonts CSS2 URL from normalized font entries.
	 *
	 * Accepts either the canonical {family, variants} array or a legacy
	 * string[] (normalized internally via FontEntry::normalize()).
	 *
	 * @param  array $entries Normalized font entries or legacy string[].
	 * @return string The Google Fonts URL, or empty string when no valid families.
	 */
	public static function build( array $entries ): string {
		$normalized = FontEntry::normalize( $entries );
		$families   = [];

		foreach ( $normalized as $entry ) {
			$sanitized = self::sanitize_family( $entry['family'] );
			if ( '' === $sanitized ) {
				continue;
			}

			$encoded  = str_replace( ' ', '+', $sanitized );
			$variants = '' !== $entry['variants'] ? $entry['variants'] : self::DEFAULT_VARIANTS;

			$families[] = 'family=' . $encoded . ':' . $variants;
		}

		if ( empty( $families ) ) {
			return '';
		}

		return 'https://fonts.googleapis.com/css2?' . implode( '&', $families ) . '&display=swap';
	}

	/**
	 * Build a Google Fonts stylesheet link tag (with preconnects) from entries.
	 *
	 * @param  array $entries Normalized font entries or legacy string[].
	 * @return string HTML link tags, or empty string.
	 */
	public static function build_link_tag( array $entries ): string {
		$url = self::build( $entries );
		if ( '' === $url ) {
			return '';
		}

		return '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n"
			. '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
			. '<link rel="stylesheet" href="' . $url . '">';
	}

	/**
	 * Sanitize a font family name for inclusion in a Google Fonts URL.
	 *
	 * Matches the historical FontProvider::sanitize_font_name contract:
	 * allows alphanumerics, spaces, and hyphens only.
	 *
	 * @param  string $name Raw family name.
	 * @return string Sanitized family name, or empty string.
	 */
	public static function sanitize_family( string $name ): string {
		$sanitized = preg_replace( '/[^a-zA-Z0-9 -]/', '', $name );
		return trim( $sanitized );
	}

	/**
	 * Normalize every `fonts.googleapis.com` link in an HTML snippet by
	 * stripping the `:<variants>` segment from each `family=` parameter.
	 *
	 * Used by the content-hash routine so hashes stay stable when the
	 * exporter gains variant output. Only modifies Google Fonts URLs —
	 * all other markup is passed through unchanged.
	 *
	 * @param  string $html HTML markup.
	 * @return string HTML with variants stripped from Google Fonts links.
	 */
	public static function strip_variants_from_html( string $html ): string {
		if ( '' === $html || false === strpos( $html, 'fonts.googleapis.com' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/(fonts\.googleapis\.com\/css2\?)([^"\'\s>]+)/i',
			static function ( $match ) {
				$query = $match[2];
				// Strip `:<variants>` segment from each family= parameter.
				$stripped = preg_replace(
					'/(family=[^:&]+):[^&]*/i',
					'$1',
					$query
				);
				return $match[1] . $stripped;
			},
			$html
		);
	}
}
