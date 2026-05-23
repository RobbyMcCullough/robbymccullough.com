<?php
/**
 * Path-derivation helpers extracted from `MarkerEmissionPlanner.php`.
 *
 * PHP twin of
 * `frontend/src/core/template-engine/marker-emission-planner-paths.js`. Each
 * compound element type (image, link) groups its mustache tokens by element
 * id, then derives a single "field path" for the wrap. Derivation strips a
 * per-type set of sub-token suffixes and takes the longest common
 * dot-prefix of the remainder, falling back to the first stripped path if
 * no common prefix exists.
 *
 * @package FL\DesignSystem\Rendering\Helpers
 */

namespace FL\DesignSystem\Rendering\Helpers;

class MarkerPathDeriver {

	public const IMAGE_SUB_TOKENS = [ 'url', 'alt', 'id', 'size' ];

	// Compound link sub-tokens. The compound link value shape is
	// `{ text, href, target, rel }` (`field-types/normalizers/link.js`).
	public const LINK_SUB_TOKENS = [ 'text', 'href', 'target', 'rel' ];

	/**
	 * Derive the image field path from a token group.
	 *
	 * @param array    $token_list      Tokens with `body` + `sectionDepth`.
	 * @param callable $resolve_token_path Resolver returning a string path or null.
	 * @return string Derived path, or '' when no path can be derived.
	 */
	public static function derive_image_path( array $token_list, callable $resolve_token_path ): string {
		return self::derive_path( $token_list, $resolve_token_path, self::IMAGE_SUB_TOKENS );
	}

	/**
	 * Derive the link field path from a token group.
	 *
	 * @param array    $token_list      Tokens with `body` + `sectionDepth`.
	 * @param callable $resolve_token_path Resolver returning a string path or null.
	 * @return string Derived path, or '' when no path can be derived.
	 */
	public static function derive_link_path( array $token_list, callable $resolve_token_path ): string {
		return self::derive_path( $token_list, $resolve_token_path, self::LINK_SUB_TOKENS );
	}

	private static function derive_path( array $token_list, callable $resolve_token_path, array $sub_tokens ): string {
		if ( empty( $token_list ) ) {
			return '';
		}
		$stripped = [];
		foreach ( $token_list as $t ) {
			$path = $resolve_token_path( $t['body'], $t['sectionDepth'] );
			if ( null === $path ) {
				continue;
			}
			$stripped[] = self::strip_sub_token( $path, $sub_tokens );
		}
		if ( empty( $stripped ) ) {
			return '';
		}
		$common = explode( '.', $stripped[0] );
		$count  = count( $stripped );
		for ( $i = 1; $i < $count; $i++ ) {
			$next  = explode( '.', $stripped[ $i ] );
			$limit = min( count( $common ), count( $next ) );
			$j     = 0;
			while ( $j < $limit && $common[ $j ] === $next[ $j ] ) {
				$j++;
			}
			$common = array_slice( $common, 0, $j );
			if ( empty( $common ) ) {
				break;
			}
		}
		return ! empty( $common ) ? implode( '.', $common ) : $stripped[0];
	}

	private static function strip_sub_token( string $path, array $sub_tokens ): string {
		foreach ( $sub_tokens as $sub ) {
			$suffix = '.' . $sub;
			$nl     = strlen( $suffix );
			if ( $nl > 0 && substr( $path, -$nl ) === $suffix ) {
				return substr( $path, 0, strlen( $path ) - $nl );
			}
		}
		return $path;
	}
}
