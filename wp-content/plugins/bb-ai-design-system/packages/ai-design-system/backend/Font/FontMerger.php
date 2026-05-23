<?php

namespace FL\DesignSystem\Font;

/**
 * Applies a keyed-by-family font override map to the canonical font list.
 *
 * Mirrors the `overrides` semantic on token edits:
 *
 *   - Each override key is a family name.
 *   - A `{ variants: '...' }` value upserts that family.
 *   - A `null` value removes the family. `null` on a family that doesn't
 *     exist is a no-op (matches the token override contract).
 *   - Families not mentioned in the overrides map are preserved verbatim.
 *
 * Validation is strict: an invalid family or variants string returns a
 * `WP_Error` rather than being silently dropped, since this is a write API
 * and the agent needs to know its input was rejected.
 */
class FontMerger {

	/**
	 * Match the variants character set used by `sanitize_font_entries`.
	 * Letters, digits, `_`, `,`, `;`, `.`, `@`, `:`.
	 */
	private const VARIANTS_PATTERN = '/^[A-Za-z0-9_,;.@:]*$/';

	/**
	 * Merge a keyed override map onto the canonical font list.
	 *
	 * @param array $existing  Canonical normalized list `[ { family, variants }, ... ]`.
	 * @param array $overrides Map `{ family => { variants } | null }`.
	 * @return array<int, array{family: string, variants: string}>|\WP_Error
	 */
	public static function merge( array $existing, array $overrides ) {
		// The overrides map is keyed by family name. A sequential array (list)
		// usually means the agent sent an array of objects like
		// [{"family":"Inter","variants":"100..900"}]; in that case foreach yields
		// integer keys and the inner family-name validation produces a misleading
		// "invalid family name" error. Surface the actual problem instead.
		if (
			count( $overrides ) > 0 &&
			array_keys( $overrides ) === range( 0, count( $overrides ) - 1 )
		) {
			return new \WP_Error(
				'invalid_font_overrides_shape',
				'font_overrides must be a map keyed by family name (e.g. {"Inter": {"variants": "100..900"}}). Got a sequential array. To remove a family, set its value to null.',
				[ 'status' => 400 ]
			);
		}

		$keyed = [];
		foreach ( $existing as $entry ) {
			if ( isset( $entry['family'] ) && is_string( $entry['family'] ) ) {
				$keyed[ $entry['family'] ] = [
					'family'   => $entry['family'],
					'variants' => isset( $entry['variants'] ) && is_string( $entry['variants'] ) ? $entry['variants'] : '',
				];
			}
		}

		foreach ( $overrides as $family => $value ) {
			$family = is_string( $family ) ? trim( $family ) : '';

			if ( '' === $family || ! FontFamilyValidator::is_valid( $family ) ) {
				return new \WP_Error(
					'invalid_font_family',
					'font_overrides contains an invalid family name. Use a Google Font family (no CSS generics or system fonts).',
					[
						'status' => 400,
						'family' => is_string( $family ) ? $family : '',
					]
				);
			}

			if ( null === $value ) {
				unset( $keyed[ $family ] );
				continue;
			}

			if ( ! is_array( $value ) ) {
				return new \WP_Error(
					'invalid_font_override',
					'font_overrides values must be an object with a "variants" string, or null to remove.',
					[
						'status' => 400,
						'family' => $family,
					]
				);
			}

			$variants = isset( $value['variants'] ) && is_string( $value['variants'] ) ? $value['variants'] : '';
			if ( ! preg_match( self::VARIANTS_PATTERN, $variants ) ) {
				return new \WP_Error(
					'invalid_font_variants',
					'font_overrides variants must use only letters, digits, and the characters _ , ; . @ :',
					[
						'status'   => 400,
						'family'   => $family,
						'variants' => $variants,
					]
				);
			}

			$keyed[ $family ] = [
				'family'   => $family,
				'variants' => $variants,
			];
		}

		return array_values( $keyed );
	}
}
