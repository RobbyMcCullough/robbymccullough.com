<?php

namespace FL\DesignSystem\Settings;

/**
 * Deep-merges a partial settings patch onto an existing settings array.
 *
 * Same uniform recursive rule at every depth:
 *
 *   - Associative arrays (objects): merge key-by-key. Keys present only
 *     in $existing are preserved.
 *   - Indexed arrays (repeaters): merge position-by-position. `null` at
 *     an index removes that row; remaining rows shift down. Items in
 *     $existing past the end of $patch are preserved (defensive trailing).
 *   - Empty array as patch: no-op identity, returns $existing unchanged.
 *     This makes {} a natural placeholder for "skip this repeater item."
 *   - Scalars or type mismatches: patch wins (replace). `null` at an array
 *     index is the row-removal sentinel; outside an array, `null` replaces.
 *
 * Repeater rows support add (positional extension), remove (`null` at the
 * index), and reorder (positional overwrites) through this merger. Only
 * template-deep changes (different fields, different row markup) require
 * HTML re-emission via `write_block`.
 */
class SettingsMerger {

	/**
	 * Merge $patch onto $existing using the rules above.
	 *
	 * @param array $existing The current settings array.
	 * @param array $patch    A partial patch in the same shape.
	 * @return array The merged result.
	 */
	public static function merge( array $existing, array $patch ): array {
		// Empty patch is identity: leaves $existing untouched. This handles
		// the {} placeholder convention for unchanged repeater items.
		if ( [] === $patch ) {
			return $existing;
		}

		$patch_is_list    = array_is_list( $patch );
		$existing_is_list = array_is_list( $existing );

		// Type mismatch (object vs. list): patch wins.
		if ( $patch_is_list !== $existing_is_list ) {
			return $patch;
		}

		if ( $patch_is_list ) {
			return self::merge_list( $existing, $patch );
		}

		return self::merge_assoc( $existing, $patch );
	}

	/**
	 * Positional merge for repeater arrays. `null` at a patch index removes
	 * that row; remaining rows shift down. Items in $existing past the end
	 * of $patch are preserved (defensive trailing).
	 *
	 * @param array $existing
	 * @param array $patch
	 * @return array
	 */
	private static function merge_list( array $existing, array $patch ): array {
		$result         = [];
		$count          = count( $patch );
		$existing_count = count( $existing );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( null === $patch[ $i ] ) {
				continue;
			}
			$result[] = self::merge_value( $existing[ $i ] ?? null, $patch[ $i ] );
		}

		for ( $i = $count; $i < $existing_count; $i++ ) {
			$result[] = $existing[ $i ];
		}

		return $result;
	}

	/**
	 * Key-by-key merge for objects. Keys present only in $existing are
	 * preserved; new keys in $patch are added.
	 *
	 * @param array $existing
	 * @param array $patch
	 * @return array
	 */
	private static function merge_assoc( array $existing, array $patch ): array {
		$result = $existing;

		foreach ( $patch as $key => $patch_value ) {
			$result[ $key ] = self::merge_value( $existing[ $key ] ?? null, $patch_value );
		}

		return $result;
	}

	/**
	 * Recurse if both sides are arrays; otherwise patch wins (replace).
	 *
	 * @param mixed $existing_value
	 * @param mixed $patch_value
	 * @return mixed
	 */
	private static function merge_value( $existing_value, $patch_value ) {
		if ( is_array( $patch_value ) && is_array( $existing_value ) ) {
			return self::merge( $existing_value, $patch_value );
		}

		return $patch_value;
	}
}
