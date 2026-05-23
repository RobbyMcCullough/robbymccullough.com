<?php

namespace FL\DesignSystem\Font;

/**
 * Font entry normalization.
 *
 * Converts raw font-list values (legacy JSON strings, legacy string arrays,
 * and the new {family, variants} object arrays) into a canonical shape:
 *
 *     [ [ 'family' => string, 'variants' => string ], ... ]
 *
 * The `variants` string is the opaque segment that follows the family name
 * in a Google Fonts URL (e.g. `ital,wght@0,400;0,700;1,400`). An empty
 * `variants` signals "use the URL builder's fallback weight set".
 */
class FontEntry {

	/**
	 * Normalize a raw font-list value.
	 *
	 * Accepts:
	 *   - A JSON string (decoded first).
	 *   - A legacy string[] of font family names.
	 *   - A mixed array of strings and [family, variants] objects.
	 *   - An already-normalized array.
	 *
	 * Invalid entries (empty after trimming, non-string family) are dropped.
	 *
	 * @param  mixed $raw Raw value from storage or input.
	 * @return array<int, array{family: string, variants: string}>
	 */
	public static function normalize( $raw ): array {
		if ( is_string( $raw ) ) {
			if ( '' === trim( $raw ) ) {
				return [];
			}
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				// Bare string — treat as a single family.
				return self::normalize_single( $raw ) ? [ self::normalize_single( $raw ) ] : [];
			}
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$entries = [];
		foreach ( $raw as $item ) {
			$entry = self::normalize_single( $item );
			if ( null !== $entry ) {
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Normalize a single entry (string or array) into the canonical shape.
	 *
	 * @param  mixed $item Single font entry.
	 * @return array{family: string, variants: string}|null
	 */
	private static function normalize_single( $item ): ?array {
		if ( is_string( $item ) ) {
			$family = trim( $item );
			if ( '' === $family || ! FontFamilyValidator::is_valid( $family ) ) {
				return null;
			}
			return [ 'family' => $family, 'variants' => '' ];
		}

		if ( is_array( $item ) ) {
			$family   = isset( $item['family'] ) && is_string( $item['family'] ) ? trim( $item['family'] ) : '';
			$variants = isset( $item['variants'] ) && is_string( $item['variants'] ) ? trim( $item['variants'] ) : '';
			if ( '' === $family || ! FontFamilyValidator::is_valid( $family ) ) {
				return null;
			}
			return [ 'family' => $family, 'variants' => $variants ];
		}

		return null;
	}
}
