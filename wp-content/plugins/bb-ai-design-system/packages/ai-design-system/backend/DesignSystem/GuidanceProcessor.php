<?php

namespace FL\DesignSystem\DesignSystem;

/**
 * Post-processing for spec-imported guidance text.
 *
 * Specs landed via create-design-system / update-design-system-guidance often
 * carry CSS that conflicts with the design system itself (`:root` redefining
 * tokens, theme-variant selectors that don't apply on a one-brand site, etc.).
 * The agent is asked to drop these at transcription, but this class is the
 * deterministic backstop, plus a verifier that surfaces unknown `var(--*)`
 * references as warnings.
 */
class GuidanceProcessor {

	/**
	 * Selector prefixes whose top-level rule blocks are stripped from code
	 * fences. Each entry is matched case-insensitively against the trimmed
	 * selector head; the strip kicks in if the head starts with the prefix.
	 */
	private const STRIP_SELECTOR_PREFIXES = [
		':root',
		'[data-brand',
		'[data-theme',
		'[data-mode',
		'[data-color-scheme',
	];

	/**
	 * Strip conflicting CSS rule blocks from fenced code in guidance.
	 *
	 * Walks fenced code blocks (``` … ```), parses each as a sequence of
	 * top-level CSS rules using a brace counter, and drops rules whose
	 * selector head matches one of the strip targets:
	 *
	 *   - `:root` (with or without attribute selector tails)
	 *   - `[data-brand`, `[data-theme`, `[data-mode`, `[data-color-scheme`
	 *   - `@media (prefers-color-scheme: …)`
	 *
	 * If a fenced block becomes empty after stripping, the entire fence
	 * (including its delimiters) is dropped from the output. Prose outside
	 * fences is left byte-identical.
	 */
	public static function strip_conflicting_blocks( string $guidance ): string {
		$lines  = preg_split( "/(\r\n|\n|\r)/", $guidance, -1, PREG_SPLIT_DELIM_CAPTURE );
		$output = '';
		$i      = 0;
		$count  = is_array( $lines ) ? count( $lines ) : 0;

		while ( $i < $count ) {
			$line = $lines[ $i ];

			if ( self::is_fence_open( $line ) ) {
				[ $fence_output, $consumed ] = self::process_fence( $lines, $i, $count );
				$output                     .= $fence_output;
				$i                          += $consumed;
				continue;
			}

			$output .= $line;
			$i++;
		}

		return $output;
	}

	/**
	 * Find `var(--name)` references in guidance whose name is not in the
	 * design system's emitted token map.
	 *
	 * @param  array<int,string> $known_token_names Token names including
	 *                                              the `--` prefix (the
	 *                                              shape of the keys in
	 *                                              get_structured_data()['tokens']).
	 * @return array<int,string> Deduplicated, sorted list of unknown names
	 *                           (each with leading `--`).
	 */
	public static function find_unknown_token_refs( string $guidance, array $known_token_names ): array {
		if ( '' === $guidance ) {
			return [];
		}

		$known = array_fill_keys( $known_token_names, true );

		// Match `var(--name)` and `var(--name, fallback)`. We only care
		// about the leading custom-property name; the fallback is ignored.
		preg_match_all( '/var\(\s*(--[A-Za-z0-9_-]+)/', $guidance, $matches );

		if ( empty( $matches[1] ) ) {
			return [];
		}

		$unknown = [];

		foreach ( $matches[1] as $name ) {
			if ( ! isset( $known[ $name ] ) ) {
				$unknown[ $name ] = true;
			}
		}

		$names = array_keys( $unknown );
		sort( $names );

		return $names;
	}

	/**
	 * Process a single fenced block starting at $start_index.
	 *
	 * @param  array<int,string> $lines       All split lines + delimiters.
	 * @param  int               $start_index Index of the opening fence line.
	 * @param  int               $count       Total entries in $lines.
	 * @return array{0: string, 1: int}       [emitted text, lines consumed]
	 */
	private static function process_fence( array $lines, int $start_index, int $count ): array {
		$open_line   = $lines[ $start_index ];
		$body        = '';
		$j           = $start_index + 1;
		$found_close = false;

		while ( $j < $count ) {
			$line = $lines[ $j ];

			if ( self::is_fence_close( $line ) ) {
				$close_line  = $line;
				$found_close = true;
				$j++;
				// Capture trailing line separator if present so we don't
				// drop a newline when the whole fence is empty.
				$close_sep = ( $j < $count ) ? $lines[ $j ] : '';
				if ( '' !== $close_sep && self::is_line_separator( $close_sep ) ) {
					$j++;
				} else {
					$close_sep = '';
				}
				break;
			}

			$body .= $line;
			$j++;
		}

		// Unterminated fence — leave it untouched.
		if ( ! $found_close ) {
			$raw = '';
			for ( $k = $start_index; $k < $count; $k++ ) {
				$raw .= $lines[ $k ];
			}
			return [ $raw, $count - $start_index ];
		}

		$cleaned = self::strip_rules_from_css( $body );

		// Preserve the opening-line separator if present (the line after the
		// fence-open marker).
		$open_sep = '';
		if ( ( $start_index + 1 ) < $count && self::is_line_separator( $lines[ $start_index + 1 ] ) ) {
			// Already part of $body — no double-emit needed.
		}

		if ( '' === trim( $cleaned ) ) {
			// Whole fence becomes empty after stripping — drop it.
			return [ '', $j - $start_index ];
		}

		$emitted = $open_line . $cleaned . $close_line . $close_sep;

		return [ $emitted, $j - $start_index ];
	}

	/**
	 * Strip targeted rule blocks from a CSS body using a brace counter.
	 *
	 * Walks the input character by character. At brace depth 0, accumulates
	 * a selector head; when it sees `{`, captures the brace-balanced body;
	 * if the selector head matches a strip target, the rule is dropped
	 * (along with one trailing newline if present). Otherwise the rule is
	 * emitted verbatim.
	 */
	private static function strip_rules_from_css( string $css ): string {
		$output = '';
		$head   = '';
		$len    = strlen( $css );
		$i      = 0;

		while ( $i < $len ) {
			$ch = $css[ $i ];

			// At brace-depth 0, a `;` ends an at-rule statement (`@import`,
			// `@charset`, `@namespace`) or a bare custom-property declaration
			// (`--brand: #FCB040;`). Custom-property declarations sitting
			// outside any selector are token redefinitions that conflict with
			// the design system's own tokens — drop them, same as a `:root`
			// block. Everything else flushes through.
			if ( ';' === $ch ) {
				if ( self::is_bare_custom_property_declaration( $head ) ) {
					// Drop the declaration. Step past `;` and one trailing
					// newline so the surrounding fence doesn't accumulate
					// blank lines (mirrors the `:root` strip path).
					$consumed = 1;
					$next     = $i + $consumed;
					if ( $next < $len && ( "\n" === $css[ $next ] || "\r" === $css[ $next ] ) ) {
						if ( "\r" === $css[ $next ] && ( $next + 1 ) < $len && "\n" === $css[ $next + 1 ] ) {
							$consumed += 2;
						} else {
							$consumed += 1;
						}
					}
					$i   += $consumed;
					$head = '';
					continue;
				}
				$output .= $head . ';';
				$head    = '';
				$i++;
				continue;
			}

			if ( '{' === $ch ) {
				$selector            = trim( $head );
				[ $body, $consumed ] = self::read_balanced_braces( $css, $i );

				if ( self::should_strip_selector( $selector ) ) {
					// Drop the rule. Also drop one trailing newline so the
					// surrounding fence doesn't accumulate blank lines.
					$next = $i + $consumed;
					if ( $next < $len && ( "\n" === $css[ $next ] || "\r" === $css[ $next ] ) ) {
						if ( "\r" === $css[ $next ] && ( $next + 1 ) < $len && "\n" === $css[ $next + 1 ] ) {
							$consumed += 2;
						} else {
							$consumed += 1;
						}
					}
					$i   += $consumed;
					$head = '';
					continue;
				}

				$output .= $head . $body;
				$head    = '';
				$i      += $consumed;
				continue;
			}

			$head .= $ch;
			$i++;
		}

		// Trailing content with no opening brace (e.g., a partial selector
		// at EOF) flows through unchanged.
		$output .= $head;

		return $output;
	}

	/**
	 * Read a brace-balanced `{ ... }` block starting at $start (must point
	 * at `{`). Returns [body including outer braces, characters consumed].
	 *
	 * @return array{0: string, 1: int}
	 */
	private static function read_balanced_braces( string $css, int $start ): array {
		$depth = 0;
		$i     = $start;
		$len   = strlen( $css );

		while ( $i < $len ) {
			$ch = $css[ $i ];
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					$i++;
					return [ substr( $css, $start, $i - $start ), $i - $start ];
				}
			}
			$i++;
		}

		// Unbalanced — return whatever we have. The fallback to leave-as-is
		// is handled one level up by checking is-it-stripped logic.
		return [ substr( $css, $start ), $len - $start ];
	}

	/**
	 * Is the accumulated head a bare custom-property declaration like
	 * `--brand: #FCB040`?
	 *
	 * Matches `--name:` after trimming. The terminating `;` is the caller's
	 * concern. Returns false for at-rules (`@import …`), regular property
	 * declarations (`font-size: 16px` — wouldn't appear at depth 0 in valid
	 * CSS but the anchor on `^--` makes the check safe regardless), and
	 * any head containing intervening structure.
	 */
	private static function is_bare_custom_property_declaration( string $head ): bool {
		return 1 === preg_match( '/^\s*--[A-Za-z0-9_-]+\s*:/', $head );
	}

	/**
	 * Does this selector head match a strip target?
	 *
	 * Matches `:root`, the allowlisted `[data-*]` prefixes, and
	 * `@media (prefers-color-scheme: …)` (case-insensitive,
	 * whitespace-tolerant). Anything else is preserved.
	 */
	private static function should_strip_selector( string $selector ): bool {
		if ( '' === $selector ) {
			return false;
		}

		$normalized = strtolower( $selector );

		foreach ( self::STRIP_SELECTOR_PREFIXES as $prefix ) {
			if ( str_starts_with( $normalized, $prefix ) ) {
				return true;
			}
		}

		// `@media (prefers-color-scheme: ...)` — tolerate any whitespace
		// and any value inside the parens.
		if ( preg_match( '/^@media\s*\(\s*prefers-color-scheme\s*:/i', $selector ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Does this line open a fenced code block (``` or ```lang)?
	 */
	private static function is_fence_open( string $line ): bool {
		return 1 === preg_match( '/^[ \t]{0,3}```+[ \t]*[A-Za-z0-9_+\-]*[ \t]*$/', $line );
	}

	/**
	 * Does this line close a fenced code block? Same shape as the opener
	 * but with no language tag.
	 */
	private static function is_fence_close( string $line ): bool {
		return 1 === preg_match( '/^[ \t]{0,3}```+[ \t]*$/', $line );
	}

	/**
	 * Is this a bare line separator (the kind PREG_SPLIT_DELIM_CAPTURE
	 * returns alongside the content lines)?
	 */
	private static function is_line_separator( string $s ): bool {
		return "\n" === $s || "\r" === $s || "\r\n" === $s;
	}
}
