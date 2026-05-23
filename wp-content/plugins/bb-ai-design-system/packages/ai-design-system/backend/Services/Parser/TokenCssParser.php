<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * Token CSS Parser
 *
 * Extracts custom-property declarations from a `:root { ... }` block and
 * reports any non-`:root` blocks the agent placed in the tokens section.
 *
 * Convention: tokens live in exactly one `:root` block. Anything else
 * (`[data-X]` selectors, media queries, alternate selectors) is dropped
 * with a warning so the caller can relay the loss to the agent.
 *
 * Note: the brace-depth scan does not handle literal `}` characters
 * inside CSS values (e.g. `content: "}"`). The tokens-section convention
 * is `name: value;` only; values containing literal braces are not
 * supported.
 */
class TokenCssParser {

	private const PROP_RE    = '/(--[\w-]+)\s*:\s*([^;]+)/';
	private const COMMENT_RE = '/\/\*[\s\S]*?\*\//';

	/**
	 * Parse a tokens-section CSS string.
	 *
	 * @param string $css Raw CSS captured between `/* @tokens *​/` and the next marker.
	 * @return array{tokens: array<string, string>, warnings: array<int, string>}
	 */
	public static function parse( string $css ): array {
		$result = [
			'tokens'   => [],
			'warnings' => [],
		];

		if ( '' === $css ) {
			return $result;
		}

		// Strip block comments first so commented-out declarations don't get extracted.
		$stripped = preg_replace( self::COMMENT_RE, '', $css );
		if ( null === $stripped ) {
			return $result;
		}

		$root = self::extract_root_block( $stripped );

		if ( null !== $root ) {
			$result['tokens'] = self::extract_declarations( $root['body'] );
			$remainder        = substr( $stripped, 0, $root['start'] ) . substr( $stripped, $root['end'] );
		} else {
			$remainder = $stripped;
		}

		$result['warnings'] = self::collect_warnings( $remainder );

		return $result;
	}

	/**
	 * Find the first `:root { ... }` block via a brace-depth scan.
	 *
	 * Returns the block body and the byte offsets of the entire matched block
	 * (from the start of `:root` through its matching closing brace) so the
	 * caller can excise it from the input before scanning the remainder.
	 *
	 * @param string $css CSS string with comments already stripped.
	 * @return array{body: string, start: int, end: int}|null
	 */
	private static function extract_root_block( string $css ): ?array {
		if ( ! preg_match( '/:root\s*\{/', $css, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$selector_start = $match[0][1];
		$body_start     = $selector_start + strlen( $match[0][0] );
		$length         = strlen( $css );
		$depth          = 1;

		for ( $i = $body_start; $i < $length; $i++ ) {
			$ch = $css[ $i ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					return [
						'body'  => substr( $css, $body_start, $i - $body_start ),
						'start' => $selector_start,
						'end'   => $i + 1,
					];
				}
			}
		}

		// Unbalanced — treat what remains as the body and report nothing else.
		return [
			'body'  => substr( $css, $body_start ),
			'start' => $selector_start,
			'end'   => $length,
		];
	}

	/**
	 * Pull `--name: value;` pairs from a CSS body string.
	 *
	 * @param string $body
	 * @return array<string, string>
	 */
	private static function extract_declarations( string $body ): array {
		$tokens = [];

		if ( preg_match_all( self::PROP_RE, $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$tokens[ trim( $m[1] ) ] = trim( $m[2] );
			}
		}

		return $tokens;
	}

	/**
	 * Walk what remains after the `:root` block and emit one warning per
	 * selector or at-rule block that contains custom-property declarations.
	 *
	 * Each warning enumerates the dropped declarations inline so the agent
	 * can relay a coherent narrative to the user instead of N individual
	 * lines.
	 *
	 * @param string $css
	 * @return array<int, string>
	 */
	private static function collect_warnings( string $css ): array {
		$warnings = [];
		$length   = strlen( $css );
		$i        = 0;

		while ( $i < $length ) {
			$brace = strpos( $css, '{', $i );
			if ( false === $brace ) {
				break;
			}

			$selector = trim( substr( $css, $i, $brace - $i ) );
			// Find the matching closing brace via depth scan.
			$depth = 1;
			$j     = $brace + 1;
			while ( $j < $length && $depth > 0 ) {
				$ch = $css[ $j ];
				if ( '{' === $ch ) {
					$depth++;
				} elseif ( '}' === $ch ) {
					--$depth;
				}
				$j++;
			}

			$body         = substr( $css, $brace + 1, $j - $brace - 2 );
			$declarations = self::extract_declarations( $body );

			if ( '' !== $selector && ! empty( $declarations ) ) {
				$warnings[] = self::format_warning( $selector, $declarations );
			}

			$i = $j;
		}

		return $warnings;
	}

	/**
	 * Format a warning for a dropped block.
	 *
	 * @param string                $selector Raw selector or at-rule prelude.
	 * @param array<string, string> $declarations
	 * @return string
	 */
	private static function format_warning( string $selector, array $declarations ): string {
		// Collapse whitespace inside the selector so multi-line selectors fit on one line.
		$clean_selector = trim( preg_replace( '/\s+/', ' ', $selector ) ?? $selector );

		$pairs = [];
		foreach ( $declarations as $name => $value ) {
			$pairs[] = $name . ' (' . $value . ')';
		}

		return sprintf(
			"Block '%s' was not stored. Token overrides must live in :root. Dropped declarations: %s.",
			$clean_selector,
			implode( ', ', $pairs )
		);
	}
}
