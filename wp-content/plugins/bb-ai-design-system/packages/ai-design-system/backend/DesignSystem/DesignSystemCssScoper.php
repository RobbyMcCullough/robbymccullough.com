<?php

namespace FL\DesignSystem\DesignSystem;

/**
 * Scopes design system CSS selectors under `.fl-ds-block` for editor environments.
 *
 * Used for both reset and base CSS. Bare element selectors (body, h1, p, *, etc.)
 * are scoped when injected into BB/Block Editor so they don't bleed into
 * non-DS content. Class selectors become descendant selectors under the scope.
 * In preview iframes and static HTML, CSS is used as-is since those
 * environments are isolated.
 */
class DesignSystemCssScoper {

	const SCOPE = '.fl-ds-block';

	/**
	 * Transform unscoped CSS into `.fl-ds-block`-scoped CSS.
	 *
	 * @param  string $css Raw CSS with bare element or class selectors.
	 * @return string Scoped CSS.
	 */
	public static function scope( string $css ): string {
		if ( empty( trim( $css ) ) ) {
			return '';
		}

		// Strip CSS comments.
		$css = preg_replace( '/\/\*[\s\S]*?\*\//', '', $css );
		if ( empty( trim( $css ) ) ) {
			return '';
		}

		$blocks = self::split_top_level_blocks( $css );
		$output = [];

		foreach ( $blocks as $block ) {
			$output[] = self::transform_block( $block );
		}

		return implode( "\n", $output );
	}

	/**
	 * Split CSS into two buckets: rules whose top-level selector starts with `html`
	 * (or @media wrappers containing only html rules) and the rest.
	 *
	 * Mirrors the `^html\b` test used by scope_selector() so the html-rule path is
	 * exactly what would have passed through the scoper unchanged. Used to route
	 * document-level rules (font-size, scroll-behavior, font-smoothing) to a
	 * cascade-last print element so they can win against the active theme's html
	 * rule. Tokens (`:root`) and everything else stay in the bulk bucket.
	 *
	 * @param  string $css Raw CSS string.
	 * @return array { withoutHtml: string, htmlOnly: string }
	 */
	public static function split_html_rules( string $css ): array {
		if ( '' === trim( $css ) ) {
			return [
				'withoutHtml' => '',
				'htmlOnly'    => '',
			];
		}

		$blocks      = self::split_top_level_blocks( $css );
		$without     = [];
		$html_only   = [];

		foreach ( $blocks as $block ) {
			if ( self::block_is_html_only( $block ) ) {
				$html_only[] = $block;
			} else {
				$without[] = $block;
			}
		}

		return [
			'withoutHtml' => implode( "\n", $without ),
			'htmlOnly'    => implode( "\n", $html_only ),
		];
	}

	/**
	 * Determine whether a top-level CSS block represents only html rules.
	 *
	 * Plain rule: prelude starts with `html` (e.g. `html`, `html:root`).
	 * @media/@supports wrapper: every inner rule is an html rule. Keeps the
	 * wrapper intact so responsive html rules late-print as written.
	 * @keyframes and other at-rules: never html.
	 *
	 * @param  string $block CSS block string.
	 * @return bool
	 */
	private static function block_is_html_only( string $block ): bool {
		$trimmed = ltrim( $block );

		if ( preg_match( '/^@(media|supports)\s/i', $trimmed ) ) {
			$brace_pos = strpos( $trimmed, '{' );
			if ( false === $brace_pos ) {
				return false;
			}
			$inner        = substr( $trimmed, $brace_pos + 1, strrpos( $trimmed, '}' ) - $brace_pos - 1 );
			$inner_blocks = self::split_top_level_blocks( $inner );

			if ( empty( $inner_blocks ) ) {
				return false;
			}

			foreach ( $inner_blocks as $inner_block ) {
				if ( ! self::block_is_html_only( $inner_block ) ) {
					return false;
				}
			}
			return true;
		}

		if ( '@' === substr( $trimmed, 0, 1 ) ) {
			return false;
		}

		return self::prelude_is_html( $trimmed );
	}

	/**
	 * Check whether a rule's selector list contains only html-rooted selectors.
	 *
	 * @param  string $block CSS rule block (with selector + braces).
	 * @return bool
	 */
	private static function prelude_is_html( string $block ): bool {
		$brace_pos = strpos( $block, '{' );
		if ( false === $brace_pos ) {
			return false;
		}
		$selector_part = substr( $block, 0, $brace_pos );
		$selectors     = array_filter( array_map( 'trim', explode( ',', $selector_part ) ) );
		if ( empty( $selectors ) ) {
			return false;
		}
		foreach ( $selectors as $sel ) {
			if ( ! preg_match( '/^html\b/', $sel ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Split CSS into top-level blocks using brace counting.
	 *
	 * @param  string $css Raw CSS string.
	 * @return array  Array of top-level CSS block strings.
	 */
	private static function split_top_level_blocks( string $css ): array {
		$blocks = [];
		$depth  = 0;
		$start  = 0;
		$len    = strlen( $css );

		for ( $i = 0; $i < $len; $i++ ) {
			if ( $css[ $i ] === '{' ) {
				$depth++;
			} elseif ( $css[ $i ] === '}' ) {
				--$depth;
				if ( $depth === 0 ) {
					$block = trim( substr( $css, $start, $i - $start + 1 ) );
					if ( $block !== '' ) {
						$blocks[] = $block;
					}
					$start = $i + 1;
				}
			}
		}

		return $blocks;
	}

	/**
	 * Transform a single top-level CSS block.
	 *
	 * @param  string $block CSS block string.
	 * @return string Transformed block.
	 */
	private static function transform_block( string $block ): string {
		// @media / @supports: transform inner selectors, keep wrapper.
		if ( preg_match( '/^@(media|supports)\s/i', $block ) ) {
			$brace_pos    = strpos( $block, '{' );
			$prelude      = substr( $block, 0, $brace_pos );
			$inner        = substr( $block, $brace_pos + 1, strrpos( $block, '}' ) - $brace_pos - 1 );
			$inner_blocks = self::split_top_level_blocks( $inner );
			$scoped_parts = [];

			foreach ( $inner_blocks as $inner_block ) {
				$scoped_parts[] = self::transform_block( $inner_block );
			}

			return $prelude . "{\n" . implode( "\n", $scoped_parts ) . "\n}";
		}

		// @keyframes: pass through unchanged.
		if ( preg_match( '/^@keyframes\s/i', $block ) ) {
			return $block;
		}

		return self::transform_rule( $block );
	}

	/**
	 * Transform a single CSS rule by scoping its selectors.
	 *
	 * @param  string $block CSS rule block string.
	 * @return string Transformed rule.
	 */
	private static function transform_rule( string $block ): string {
		$brace_pos = strpos( $block, '{' );
		if ( $brace_pos === false ) {
			return $block;
		}

		$selector_part = substr( $block, 0, $brace_pos );
		$rest          = substr( $block, $brace_pos );
		$selectors     = explode( ',', $selector_part );
		$transformed   = [];

		foreach ( $selectors as $raw ) {
			$sel = trim( $raw );
			if ( $sel === '' ) {
				continue;
			}
			$scoped = self::scope_selector( $sel );
			foreach ( $scoped as $s ) {
				$transformed[] = $s;
			}
		}

		if ( empty( $transformed ) ) {
			return $block;
		}

		return implode( ', ', $transformed ) . ' ' . $rest;
	}

	/**
	 * Scope a single selector, returning an array of scoped selectors.
	 *
	 * @param  string $sel CSS selector string.
	 * @return array  Array of scoped selector strings.
	 */
	private static function scope_selector( string $sel ): array {
		$scope = self::SCOPE;

		// Already-scoped selectors pass through. Matches `.fl-ds-block` as a class
		// (not as a prefix of `.fl-ds-block-foo`). Makes the scoper idempotent so
		// generator output that pattern-matches the runtime form doesn't double-scope.
		if ( preg_match( '/\.fl-ds-block(?![a-zA-Z0-9_-])/', $sel ) ) {
			return [ $sel ];
		}

		// html / html:root — pass through unchanged (global by nature).
		if ( preg_match( '/^html\b/', $sel ) ) {
			return [ $sel ];
		}

		// body → .fl-ds-block
		if ( preg_match( '/^body\b/', $sel ) ) {
			return [ preg_replace( '/^body/', $scope, $sel ) ];
		}

		// *::pseudo → .fl-ds-block::pseudo, .fl-ds-block *::pseudo
		if ( preg_match( '/^\*::/', $sel ) ) {
			$pseudo = substr( $sel, 1 );
			return [
				$scope . $pseudo,
				$scope . ' *' . $pseudo,
			];
		}

		// * → .fl-ds-block, .fl-ds-block *
		if ( $sel === '*' ) {
			return [ $scope, $scope . ' *' ];
		}

		// Standalone pseudo-element (e.g., ::selection)
		if ( preg_match( '/^::/', $sel ) ) {
			return [
				$scope . $sel,
				$scope . ' ' . $sel,
			];
		}

		// Default: splice SCOPE into the first compound and emit the dual form.
		// First arm makes the rule match when the first token IS the wrapper;
		// second arm preserves the inside-the-wrapper case.
		$split_at = self::find_first_compound_end( $sel );
		$head     = substr( $sel, 0, $split_at );
		$tail     = substr( $sel, $split_at );

		return [
			$head . $scope . $tail,
			$scope . ' ' . $sel,
		];
	}

	/**
	 * Find the index where the first compound selector ends. A compound runs
	 * until the first combinator (whitespace, >, +, ~) outside brackets/parens,
	 * or just before the first pseudo-element (`::`), or end-of-string.
	 *
	 * Bracket and paren depth is tracked so attribute selectors and pseudo-class
	 * arguments (e.g. `:not(.foo)`, `:has(.a > .b)`, `[data-x="y"]`) are treated
	 * as part of the compound, not as combinators.
	 *
	 * @param  string $sel CSS selector string.
	 * @return int    Offset where the first compound ends.
	 */
	private static function find_first_compound_end( string $sel ): int {
		$depth = 0;
		$len   = strlen( $sel );

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $sel[ $i ];

			if ( '(' === $ch || '[' === $ch ) {
				$depth++;
				continue;
			}
			if ( ')' === $ch || ']' === $ch ) {
				--$depth;
				continue;
			}
			if ( $depth > 0 ) {
				continue;
			}
			if ( ' ' === $ch || "\t" === $ch || '>' === $ch || '+' === $ch || '~' === $ch ) {
				return $i;
			}
			if ( ':' === $ch && $i + 1 < $len && ':' === $sel[ $i + 1 ] ) {
				return $i;
			}
		}

		return $len;
	}
}
