<?php

namespace FL\DesignSystem\Font;

/**
 * Extracts Google Font family names from a CSS string.
 *
 * Reads `font-family:` declarations and any `--*font*` custom property whose
 * name is not a CSS sub-property (`-size`, `-weight`, `-style`, `-variant`,
 * `-stretch`). Splits comma-separated stacks, strips quotes, and runs each
 * token through FontFamilyValidator.
 *
 * Returns deduped family names in first-seen order. Dedup is case-insensitive
 * but preserves the original casing of the first occurrence.
 */
class FontExtractor {

	/**
	 * Matches `font-family:` or any `--*-font*` custom property whose suffix
	 * isn't one of the CSS sub-properties that carry non-family values.
	 *
	 * The negative lookahead `(?!-(?:size|weight|style|variant|stretch))`
	 * excludes tokens like `--ds-font-size-lg` and `--ds-system-root-font-size`
	 * while keeping `--ds-font-heading`, `--ds-font-body`, etc.
	 */
	private const DECLARATION_PATTERN = '/(?:font-family|--[\w-]*font(?!-(?:size|weight|style|variant|stretch))[\w-]*):\s*([^;]+)/i';

	/**
	 * Extract validated, deduped Google Font family names from CSS.
	 *
	 * @param  string $css Raw CSS string.
	 * @return string[]    Family names.
	 */
	public static function extract_families( string $css ): array {
		if ( '' === $css ) {
			return [];
		}

		if ( ! preg_match_all( self::DECLARATION_PATTERN, $css, $matches ) ) {
			return [];
		}

		$fonts = [];
		$seen  = [];

		foreach ( $matches[1] as $value ) {
			$parts = array_map( 'trim', explode( ',', $value ) );
			foreach ( $parts as $part ) {
				$part = trim( $part, "\"' " );
				if ( ! FontFamilyValidator::is_valid( $part ) ) {
					continue;
				}
				$key = strtolower( $part );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$fonts[]      = $part;
			}
		}

		return $fonts;
	}
}
