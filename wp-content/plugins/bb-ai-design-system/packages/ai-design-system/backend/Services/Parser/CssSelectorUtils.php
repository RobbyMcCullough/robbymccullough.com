<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * CSS Selector Utilities
 *
 * Shared selector-extraction helpers used by parser services that need to
 * reason about which classes/ids a CSS rule targets.
 */
class CssSelectorUtils {

	/**
	 * Common utility class names excluded from selector-based matching.
	 *
	 * These are too generic to anchor a section identity reliably — a CSS rule
	 * whose only root is one of these names is treated as utility, not section,
	 * scoping. Both PageParser's orphan recovery and CssOverClaimAuditor's
	 * over-claim detection rely on this skip-list.
	 */
	public const UTILITY_CLASSES = [
		'container',
		'wrapper',
		'inner',
		'content',
		'section',
		'row',
		'col',
		'grid',
		'flex',
		'block',
		'hidden',
	];

	/**
	 * Extract root CSS class/id selectors from a CSS string.
	 *
	 * Strips `@keyframes` blocks before extraction (animation names aren't
	 * section identifiers). `:root` and other type/pseudo selectors return no
	 * class-chain root and are filtered implicitly. Utility classes from
	 * UTILITY_CLASSES are also filtered.
	 *
	 * @param string $css Raw CSS content.
	 * @return string[] Lowercased root selector names (without . or # prefix).
	 */
	public static function extract_root_selectors( string $css ): array {
		// Strip @keyframes blocks.
		$without_keyframes = preg_replace( '/@keyframes\s+[\w-]+\s*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/', '', $css );

		$roots = [];
		if ( preg_match_all( '/(?:^|[},])\s*([.#][a-zA-Z][\w-]*)/m', $without_keyframes, $matches ) ) {
			foreach ( $matches[1] as $selector ) {
				$name = strtolower( substr( $selector, 1 ) );
				if ( ! in_array( $name, self::UTILITY_CLASSES, true ) ) {
					$roots[ $name ] = true;
				}
			}
		}
		return array_keys( $roots );
	}
}
