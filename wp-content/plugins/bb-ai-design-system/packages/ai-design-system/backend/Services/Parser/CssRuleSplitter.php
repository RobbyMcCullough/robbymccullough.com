<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * CSS Rule Splitter
 *
 * Splits a CSS string into top-level rules with brace-depth tracking.
 * Handles nested at-rules (`@media { .x { ... } }`) by returning the entire
 * at-block as a single "rule" — callers decide whether to descend.
 *
 * Rules are emitted including their trailing `}` and any whitespace up to the
 * start of the next rule, so concatenating preserves the original CSS shape
 * minus any rules the caller drops.
 *
 * Deliberately simple: not a full CSS parser. Native CSS nesting and other
 * shapes the simple parser can't represent are returned as-is to the caller.
 */
class CssRuleSplitter {

	/**
	 * Split a CSS string into top-level rules.
	 *
	 * @param string $css
	 * @return string[]
	 */
	public static function split( string $css ): array {
		$rules        = [];
		$len          = strlen( $css );
		$start        = 0;
		$depth        = 0;
		$in_string    = '';
		$rule_started = false;

		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $css[ $i ];

			if ( '' !== $in_string ) {
				if ( $ch === $in_string && '\\' !== ( $css[ $i - 1 ] ?? '' ) ) {
					$in_string = '';
				}
				continue;
			}

			if ( '"' === $ch || "'" === $ch ) {
				$in_string = $ch;
				continue;
			}

			if ( ! $rule_started && ! ctype_space( $ch ) ) {
				$rule_started = true;
			}

			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					// Include trailing whitespace/newline so concatenation preserves
					// inter-rule separators.
					$end = $i + 1;
					while ( $end < $len && ( "\n" === $css[ $end ] || "\r" === $css[ $end ] ) ) {
						$end++;
					}
					$rules[]      = substr( $css, $start, $end - $start );
					$start        = $end;
					$i            = $end - 1;
					$rule_started = false;
				}
			}
		}

		// Trailing content with no closing brace (malformed) — keep it intact.
		if ( $start < $len ) {
			$rules[] = substr( $css, $start );
		}

		return $rules;
	}
}
