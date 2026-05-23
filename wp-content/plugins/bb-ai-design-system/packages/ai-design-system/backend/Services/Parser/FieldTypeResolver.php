<?php
/**
 * Path -> field-type resolver for marker emission. Settings-shape SVG
 * fallback. PHP twin of
 * `frontend/src/core/template-engine/field-type-resolver.js`.
 *
 * Image detection is template-driven (see MarkerEmissionPlanner) — any
 * `<img>` carrying mustache becomes editable regardless of value shape.
 * So image detection does not consult this resolver.
 *
 * SVG is different: the body-context pattern `<div>{{{settings.icon}}}</div>`
 * has no `<svg>` in the template. The `<svg>` only appears once Mustache
 * renders the value. To upgrade those body-context tokens from `bb:text:`
 * to `bb:svg:`, the resolver inspects the **value at the queried path**
 * and returns 'svg' if it's a string starting with `<svg`. Anything else
 * returns null and the planner falls back to its 'text' default.
 *
 * @package FL\DesignSystem\Services\Parser
 */

namespace FL\DesignSystem\Services\Parser;

class FieldTypeResolver {

	private const BB_PATH_PREFIX = '{{__bb_path}}.';

	/**
	 * Resolved settings tree (after defaults merge).
	 *
	 * @var array
	 */
	private array $settings;

	public function __construct( array $settings = [] ) {
		$this->settings = $settings;
	}

	/**
	 * Resolve the field type at `$path`, or null if the value at `$path`
	 * doesn't classify as svg.
	 *
	 * @param string $path Settings path.
	 * @return string|null `'svg'` or null.
	 */
	public function field_type_at( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		if ( 0 === strpos( $path, self::BB_PATH_PREFIX ) ) {
			$remainder = substr( $path, strlen( self::BB_PATH_PREFIX ) );
			if ( '' === $remainder ) {
				return null;
			}
			return self::find_in_repeaters( $this->settings, $remainder );
		}

		return self::classify_at( $this->settings, $path );
	}

	private static function classify_at( array $root, string $path ): ?string {
		$segments = explode( '.', $path );
		$value    = self::get_by_path( $root, $segments );
		return self::classify( $value );
	}

	/**
	 * For `{{__bb_path}}.X` paths: scan every plain-object item under any
	 * top-level packed-list (repeater) and check whether `X` resolves
	 * there. First match wins.
	 */
	private static function find_in_repeaters( array $root, string $remainder ): ?string {
		foreach ( $root as $value ) {
			$found = self::scan_value( $value, $remainder );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	private static function scan_value( $value, string $remainder ): ?string {
		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( self::is_packed_list( $value ) ) {
			foreach ( $value as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$direct = self::classify_at( $item, $remainder );
				if ( null !== $direct ) {
					return $direct;
				}
				$nested = self::scan_value( $item, $remainder );
				if ( null !== $nested ) {
					return $nested;
				}
			}
			return null;
		}

		foreach ( $value as $sub ) {
			$found = self::scan_value( $sub, $remainder );
			if ( null !== $found ) {
				return $found;
			}
		}
		return null;
	}

	private static function classify( $value ): ?string {
		if ( is_string( $value ) && 1 === preg_match( '/^\s*<svg\b/i', $value ) ) {
			return 'svg';
		}
		return null;
	}

	private static function get_by_path( array $root, array $segments ) {
		$cur = $root;
		foreach ( $segments as $seg ) {
			if ( ! is_array( $cur ) ) {
				return null;
			}
			if ( ! array_key_exists( $seg, $cur ) ) {
				return null;
			}
			$cur = $cur[ $seg ];
		}
		return $cur;
	}

	private static function is_packed_list( array $arr ): bool {
		if ( empty( $arr ) ) {
			return false;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}
}
