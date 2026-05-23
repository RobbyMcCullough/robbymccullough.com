<?php

namespace FL\DesignSystem\Font;

/**
 * Gatekeeper for whether a string is a requestable Google Font family name.
 *
 * Rejects CSS generics (serif, sans-serif), common system fonts that appear
 * as fallbacks in font-family stacks, CSS length values (16px, 1.5rem) that
 * slip through naive extractors, var() references, and empty/whitespace.
 *
 * Used at both extraction boundaries (KitParser, KitImporter) and the
 * normalization boundary (FontEntry) so bad data from any source gets
 * filtered before reaching the Google Fonts URL builder.
 */
class FontFamilyValidator {

	/**
	 * System fonts that commonly appear as fallbacks in font-family stacks.
	 * Lowercase for case-insensitive matching.
	 */
	private const SYSTEM_FONTS = [
		'georgia',
		'arial',
		'helvetica',
		'helvetica neue',
		'times',
		'times new roman',
		'verdana',
		'courier',
		'courier new',
		'tahoma',
		'trebuchet ms',
		'impact',
		'system-ui',
		'ui-sans-serif',
		'ui-serif',
		'ui-monospace',
		'ui-rounded',
		'-apple-system',
		'blinkmacsystemfont',
		'segoe ui',
	];

	/**
	 * CSS generic family keywords.
	 */
	private const GENERIC_FAMILIES = [
		'serif',
		'sans-serif',
		'monospace',
		'cursive',
		'fantasy',
	];

	/**
	 * Matches CSS length values that can slip through a naive family extractor
	 * (e.g. "16px" from `--ds-system-root-font-size`).
	 */
	private const LENGTH_PATTERN = '/^-?\d+(?:\.\d+)?(?:px|rem|em|%|vh|vw|vmin|vmax|pt|pc|in|cm|mm|ex|ch|fr)$/i';

	/**
	 * Check whether a string is a valid Google Font family name.
	 *
	 * Strips surrounding whitespace and quote marks before testing.
	 *
	 * @param  string $family Raw family name.
	 * @return bool
	 */
	public static function is_valid( string $family ): bool {
		$trimmed = trim( $family, " \t\n\r\0\x0B\"'" );

		if ( '' === $trimmed ) {
			return false;
		}

		if ( str_starts_with( $trimmed, 'var(' ) ) {
			return false;
		}

		if ( preg_match( self::LENGTH_PATTERN, $trimmed ) ) {
			return false;
		}

		$lower = strtolower( $trimmed );

		if ( in_array( $lower, self::GENERIC_FAMILIES, true ) ) {
			return false;
		}

		if ( in_array( $lower, self::SYSTEM_FONTS, true ) ) {
			return false;
		}

		return true;
	}
}
