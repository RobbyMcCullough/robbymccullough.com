<?php

namespace FL\DesignSystem\Services;

/**
 * Path-addressed array mutations for DS-block settings.
 *
 * Sister of the immutable `set_by_path` helper in
 * {@see \FL\DesignSystem\BeaverBuilder\BeaverBuilderRestController}, scoped
 * to the array operations (splice / reorder) used by the REST endpoint when
 * it applies structural edits to `ds_block_data.settings`.
 *
 * Designed as a final-class collection of static helpers. No instance state,
 * no dependencies — the REST controller is already 3x the file-size hard
 * ceiling, so the splice/reorder helpers and their per-op validators live
 * here from day one rather than swelling that file further.
 *
 * Helpers mirror the JS-side `spliceByPath` / `reorderByPath` in
 * `core/canvas-editing/path-splice.js` so the server and client produce
 * identical end states when applying the same op against the same starting
 * settings.
 *
 * @internal
 */
final class SettingsArrayMutator {

	/**
	 * Resolve the array at the given dot-path inside a settings tree.
	 *
	 * Returns null if any segment is missing or the terminal value is not an
	 * array. Used by both the splice/reorder helpers (to walk + replace) and
	 * the per-op validators (to bounds-check indices against the live array).
	 *
	 * @param  array  $settings Source settings tree.
	 * @param  string $path     Dot path (no `settings.` prefix — the caller
	 *                          strips that before reaching this layer).
	 * @return array|null       The array at the path, or null if unresolved.
	 */
	public static function resolve_array_at_path( array $settings, string $path ): ?array {
		if ( '' === $path ) {
			return is_array( $settings ) ? $settings : null;
		}

		$segments = array_values( array_filter( explode( '.', $path ), static fn( $s ) => '' !== $s ) );
		if ( empty( $segments ) ) {
			return null;
		}

		$cur = $settings;
		foreach ( $segments as $segment ) {
			$key = ctype_digit( $segment ) ? (int) $segment : $segment;
			if ( ! is_array( $cur ) || ! array_key_exists( $key, $cur ) ) {
				return null;
			}
			$cur = $cur[ $key ];
		}

		return is_array( $cur ) ? $cur : null;
	}

	/**
	 * Apply a splice to the array at `$path` inside `$settings`. Returns a
	 * new settings tree; the input is treated as immutable.
	 *
	 * When the path doesn't resolve to an array, returns the input unchanged
	 * (validation runs earlier in the request lifecycle — see
	 * `validate_splice_op` — and is expected to have rejected the op before
	 * we reach this layer).
	 *
	 * @param  array  $settings      Source settings tree (immutable).
	 * @param  string $path          Dot path to the target array.
	 * @param  int    $start         Splice start index.
	 * @param  int    $delete_count  Items to remove starting at $start.
	 * @param  array  $insert        Items to insert at $start (after removal).
	 * @return array                 New settings tree with the splice applied.
	 */
	public static function splice_by_path(
		array $settings,
		string $path,
		int $start,
		int $delete_count,
		array $insert = []
	): array {
		$arr = self::resolve_array_at_path( $settings, $path );
		if ( null === $arr ) {
			return $settings;
		}

		$next = $arr;
		array_splice( $next, $start, $delete_count, $insert );

		return self::set_array_at_path( $settings, $path, $next );
	}

	/**
	 * Move an element within the array at `$path` from `$from` to `$to`,
	 * using JS Array semantics: remove from $from, then insert at $to.
	 *
	 * Same defensive shape as splice — out-of-range or unresolved paths
	 * return the input unchanged.
	 *
	 * @param  array  $settings Source settings tree (immutable).
	 * @param  string $path     Dot path to the target array.
	 * @param  int    $from     Source index.
	 * @param  int    $to       Destination index after removal.
	 * @return array            New settings tree with the move applied.
	 */
	public static function reorder_by_path( array $settings, string $path, int $from, int $to ): array {
		$arr = self::resolve_array_at_path( $settings, $path );
		if ( null === $arr ) {
			return $settings;
		}
		$length = count( $arr );
		if ( $from < 0 || $to < 0 || $from >= $length || $to >= $length ) {
			return $settings;
		}
		if ( $from === $to ) {
			return $settings;
		}

		$next  = $arr;
		$moved = array_splice( $next, $from, 1 );
		array_splice( $next, $to, 0, $moved );

		return self::set_array_at_path( $settings, $path, $next );
	}

	/**
	 * Validate a `{ op:'splice', path, start, deleteCount, insert? }` update
	 * shape. Returns null on success, WP_Error on failure.
	 *
	 * Bounds-checks `start` against the resolved array length so the splice
	 * is well-defined against the current settings — protects against
	 * client/server drift.
	 *
	 * @param  array $op       The full update object (already known to be an array).
	 * @param  array $settings The current settings tree (relative to which paths resolve).
	 * @return \WP_Error|null
	 */
	public static function validate_splice_op( array $op, array $settings ) {
		$path = isset( $op['path'] ) && is_string( $op['path'] ) ? $op['path'] : '';
		if ( ! preg_match( '/^settings\.[A-Za-z0-9_.]+$/', $path ) ) {
			return new \WP_Error(
				'invalid_path',
				sprintf( 'Splice path "%s" is not allowed.', $path ),
				[ 'status' => 400 ],
			);
		}

		$start        = $op['start'] ?? null;
		$delete_count = $op['deleteCount'] ?? null;
		if ( ! is_int( $start ) || $start < 0 ) {
			return new \WP_Error(
				'invalid_splice',
				'Splice `start` must be a non-negative integer.',
				[ 'status' => 400 ],
			);
		}
		if ( ! is_int( $delete_count ) || $delete_count < 0 ) {
			return new \WP_Error(
				'invalid_splice',
				'Splice `deleteCount` must be a non-negative integer.',
				[ 'status' => 400 ],
			);
		}

		if ( array_key_exists( 'insert', $op ) && ! is_array( $op['insert'] ) ) {
			return new \WP_Error(
				'invalid_splice',
				'Splice `insert` must be an array when present.',
				[ 'status' => 400 ],
			);
		}

		$relative = substr( $path, strlen( 'settings.' ) );
		$arr      = self::resolve_array_at_path( $settings, $relative );
		if ( null === $arr ) {
			return new \WP_Error(
				'invalid_splice',
				sprintf( 'Splice path "%s" does not resolve to an array in current settings.', $path ),
				[ 'status' => 400 ],
			);
		}

		if ( $start > count( $arr ) ) {
			return new \WP_Error(
				'invalid_splice',
				sprintf( 'Splice `start` (%d) exceeds array length (%d).', $start, count( $arr ) ),
				[ 'status' => 400 ],
			);
		}

		return null;
	}

	/**
	 * Validate a `{ op:'reorder', path, from, to }` update shape. Returns
	 * null on success, WP_Error on failure. Bounds-checks `from` and `to`
	 * against the resolved array length.
	 *
	 * @param  array $op       The full update object (already known to be an array).
	 * @param  array $settings The current settings tree.
	 * @return \WP_Error|null
	 */
	public static function validate_reorder_op( array $op, array $settings ) {
		$path = isset( $op['path'] ) && is_string( $op['path'] ) ? $op['path'] : '';
		if ( ! preg_match( '/^settings\.[A-Za-z0-9_.]+$/', $path ) ) {
			return new \WP_Error(
				'invalid_path',
				sprintf( 'Reorder path "%s" is not allowed.', $path ),
				[ 'status' => 400 ],
			);
		}

		$from = $op['from'] ?? null;
		$to   = $op['to'] ?? null;
		if ( ! is_int( $from ) || $from < 0 || ! is_int( $to ) || $to < 0 ) {
			return new \WP_Error(
				'invalid_reorder',
				'Reorder `from` and `to` must be non-negative integers.',
				[ 'status' => 400 ],
			);
		}

		$relative = substr( $path, strlen( 'settings.' ) );
		$arr      = self::resolve_array_at_path( $settings, $relative );
		if ( null === $arr ) {
			return new \WP_Error(
				'invalid_reorder',
				sprintf( 'Reorder path "%s" does not resolve to an array in current settings.', $path ),
				[ 'status' => 400 ],
			);
		}

		$length = count( $arr );
		if ( $from >= $length || $to >= $length ) {
			return new \WP_Error(
				'invalid_reorder',
				sprintf( 'Reorder indices (from=%d, to=%d) out of range for array length %d.', $from, $to, $length ),
				[ 'status' => 400 ],
			);
		}

		return null;
	}

	/**
	 * Validate `{ op:'set', path:'template', value }`. The template payload
	 * is the full HTML string for the block's Mustache template.
	 *
	 * @param  array $op
	 * @return \WP_Error|null
	 */
	public static function validate_template_set_op( array $op ) {
		$value = $op['value'] ?? null;
		if ( ! is_string( $value ) ) {
			return new \WP_Error(
				'invalid_value',
				'Update value for `template` must be a string.',
				[ 'status' => 400 ],
			);
		}
		return null;
	}

	/**
	 * Immutable setter at an array path. Internal — mirrors the REST
	 * controller's `set_by_path` shape but kept here so this service has no
	 * dependency on the controller's private method.
	 *
	 * @param  array  $source
	 * @param  string $path
	 * @param  mixed  $value
	 * @return array
	 */
	private static function set_array_at_path( array $source, string $path, $value ): array {
		if ( '' === $path ) {
			return is_array( $value ) ? $value : $source;
		}
		$segments = array_values( array_filter( explode( '.', $path ), static fn( $s ) => '' !== $s ) );
		if ( empty( $segments ) ) {
			return $source;
		}

		$root    = $source;
		$ref     =& $root;
		$last_ix = count( $segments ) - 1;

		foreach ( $segments as $i => $segment ) {
			$key = ctype_digit( $segment ) ? (int) $segment : $segment;
			if ( $i === $last_ix ) {
				$ref[ $key ] = $value;
				break;
			}
			if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
				$ref[ $key ] = [];
			}
			$ref =& $ref[ $key ];
		}
		unset( $ref );
		return $root;
	}
}
