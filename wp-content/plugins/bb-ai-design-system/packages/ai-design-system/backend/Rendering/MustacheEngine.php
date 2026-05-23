<?php
/**
 * Minimal Mustache template engine for server-side rendering.
 *
 * Covers the Mustache spec subset used by block templates:
 * - {{variable}}          Escaped output
 * - {{{variable}}}        Unescaped output
 * - {{#section}}...{{/section}}  Boolean/array sections
 * - {{^section}}...{{/section}}  Inverted sections
 * - Dot notation: {{settings.heading}}
 * - Nested context (array iteration)
 *
 * Escaped variable output uses `htmlspecialchars( $str, ENT_QUOTES, 'UTF-8' )`
 * (M-2). The JS mustache npm package escapes seven characters; the PHP side
 * matches the five HTML-spec entities (`& < > " '`). Single-quote escaping
 * was added in this round to close M-2: a value containing `'` was previously
 * emitted verbatim and could break out of attribute contexts that quote with
 * single quotes (`alt='...'`, `value='...'`, etc.). Unescaped output
 * (`{{{...}}}`) is unchanged and is identical across both implementations.
 *
 * @package FL\DesignSystem\Rendering
 */

namespace FL\DesignSystem\Rendering;

/**
 * Mustache template engine.
 */
class MustacheEngine {

	/**
	 * Render a Mustache template with the given data context.
	 *
	 * @param string $template The Mustache template string.
	 * @param array  $data     The data context for rendering.
	 * @return string The rendered output.
	 */
	public function render( string $template, array $data ): string {
		$data = self::expand_rating_values( $data );
		return $this->render_tokens( $this->tokenize( $template ), [ $data ] );
	}

	/**
	 * Expand rating values in a data array for Mustache rendering.
	 *
	 * Converts associative arrays like ['value' => 4, 'max' => 5] into
	 * sequential arrays of ['active' => true/false] so standard Mustache
	 * {{#active}} / {{^active}} sections render correctly.
	 *
	 * @param array $data The data array to process.
	 * @return array A copy with rating values expanded to boolean arrays.
	 */
	public static function expand_rating_values( array $data ): array {
		$result = [];

		foreach ( $data as $key => $value ) {
			if ( self::is_rating_value( $value ) ) {
				$result[ $key ] = self::expand_rating( $value );
			} elseif ( is_array( $value ) && ! empty( $value ) ) {
				// Check if this is a sequential array (repeater items).
				if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
					$result[ $key ] = array_map( function ( $item ) {
						if ( is_array( $item ) ) {
							return self::expand_rating_values( $item );
						}
						return $item;
					}, $value );
				} else {
					// Associative array — recurse into it.
					$result[ $key ] = self::expand_rating_values( $value );
				}
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Check whether a value is a rating object ({ value, max }).
	 *
	 * @param mixed $value The value to check.
	 * @return bool
	 */
	private static function is_rating_value( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}
		// Must have 'value' and 'max' keys with numeric 'value'.
		// Must NOT have 'url' key (that's an image object).
		return array_key_exists( 'value', $value )
			&& array_key_exists( 'max', $value )
			&& is_numeric( $value['value'] )
			&& ! array_key_exists( 'url', $value );
	}

	/**
	 * Expand a rating object into an array of active/inactive booleans.
	 *
	 * @param array $rating The rating array with 'value' and 'max' keys.
	 * @return array Sequential array of ['active' => bool] items.
	 */
	private static function expand_rating( array $rating ): array {
		$result = [];
		$value  = (int) $rating['value'];
		$max    = (int) $rating['max'];

		for ( $i = 0; $i < $max; $i++ ) {
			$result[] = [ 'active' => $i < $value ];
		}

		return $result;
	}

	/**
	 * Tokenize a Mustache template into an AST.
	 *
	 * Returns a flat/nested array of tokens. Each token is one of:
	 * - ['type' => 'text', 'value' => '...']
	 * - ['type' => 'variable', 'key' => '...', 'escaped' => true|false]
	 * - ['type' => 'section', 'key' => '...', 'inverted' => false, 'children' => [...]]
	 * - ['type' => 'section', 'key' => '...', 'inverted' => true, 'children' => [...]]
	 *
	 * @param string $template The template string.
	 * @return array Array of tokens.
	 */
	private function tokenize( string $template ): array {
		$tokens = [];
		$length = strlen( $template );
		$pos    = 0;

		while ( $pos < $length ) {
			$tag_start = strpos( $template, '{{', $pos );

			if ( false === $tag_start ) {
				// No more tags; rest is plain text.
				$tokens[] = [
					'type'  => 'text',
					'value' => substr( $template, $pos ),
				];
				break;
			}

			// Capture text before the tag.
			if ( $tag_start > $pos ) {
				$tokens[] = [
					'type'  => 'text',
					'value' => substr( $template, $pos, $tag_start - $pos ),
				];
			}

			// Check for triple-stache {{{...}}}
			$unescaped = ( $tag_start + 2 < $length && $template[ $tag_start + 2 ] === '{' );
			$content_start = $tag_start + ( $unescaped ? 3 : 2 );
			$close_tag     = $unescaped ? '}}}' : '}}';
			$tag_end       = strpos( $template, $close_tag, $content_start );

			if ( false === $tag_end ) {
				// Unclosed tag; treat rest as text.
				$tokens[] = [
					'type'  => 'text',
					'value' => substr( $template, $tag_start ),
				];
				break;
			}

			$key = trim( substr( $template, $content_start, $tag_end - $content_start ) );
			$pos = $tag_end + strlen( $close_tag );

			if ( '' === $key ) {
				continue;
			}

			$sigil = $key[0];

			if ( '#' === $sigil || '^' === $sigil ) {
				$section_key = trim( substr( $key, 1 ) );
				$inverted    = '^' === $sigil;

				// Find the matching closing tag.
				$close_marker = '{{/' . $section_key . '}}';
				$close_pos    = $this->find_closing_tag( $template, $close_marker, $pos );

				if ( false === $close_pos ) {
					// No closing tag; skip this section tag.
					continue;
				}

				$inner    = substr( $template, $pos, $close_pos - $pos );
				$children = $this->tokenize( $inner );

				$tokens[] = [
					'type'     => 'section',
					'key'      => $section_key,
					'inverted' => $inverted,
					'children' => $children,
				];

				$pos = $close_pos + strlen( $close_marker );
			} elseif ( '/' === $sigil ) {
				// Closing tag encountered at top-level; should not happen in well-formed
				// templates. Ignore it.
				continue;
			} else {
				$tokens[] = [
					'type'    => 'variable',
					'key'     => $key,
					'escaped' => ! $unescaped,
				];
			}
		}

		return $tokens;
	}

	/**
	 * Find the position of the matching closing tag, respecting nested sections
	 * with the same key.
	 *
	 * @param string $template     The full template string.
	 * @param string $close_marker The closing tag to find (e.g., "{{/items}}").
	 * @param int    $start        Position to start searching from.
	 * @return int|false Position of the closing tag, or false.
	 */
	private function find_closing_tag( string $template, string $close_marker, int $start ) {
		$section_key = substr( $close_marker, 3, -2 ); // Extract key from {{/key}}
		$depth       = 1;
		$pos         = $start;
		$length      = strlen( $template );

		while ( $pos < $length && $depth > 0 ) {
			$next_open  = strpos( $template, '{{#' . $section_key . '}}', $pos );
			$next_close = strpos( $template, $close_marker, $pos );

			if ( false === $next_close ) {
				return false;
			}

			if ( false !== $next_open && $next_open < $next_close ) {
				$depth++;
				$pos = $next_open + strlen( '{{#' . $section_key . '}}' );
			} else {
				--$depth;
				if ( 0 === $depth ) {
					return $next_close;
				}
				$pos = $next_close + strlen( $close_marker );
			}
		}

		return false;
	}

	/**
	 * Render a list of tokens against a context stack.
	 *
	 * The context stack is an array of data contexts, with the most recent
	 * (innermost) at the end. Variable lookups walk from innermost to outermost.
	 *
	 * @param array $tokens        The token list.
	 * @param array $context_stack The context stack (array of arrays).
	 * @return string The rendered output.
	 */
	private function render_tokens( array $tokens, array $context_stack ): string {
		$output = '';

		foreach ( $tokens as $token ) {
			switch ( $token['type'] ) {
				case 'text':
					$output .= $token['value'];
					break;

				case 'variable':
					$value = $this->lookup( $token['key'], $context_stack );
					// Compound image values ({url, alt, id, size}) — render the URL.
					// Empty URLs get a transparent pixel so <img src=""> doesn't show
					// the browser's broken image icon.
					if ( is_array( $value ) && array_key_exists( 'url', $value ) ) {
						$value = ! empty( $value['url'] )
							? $value['url']
							: 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
					}
					if ( is_array( $value ) || is_object( $value ) ) {
						break;
					}
					if ( is_bool( $value ) ) {
						$str = $value ? 'true' : 'false';
					} else {
						$str = (string) $value;
					}
					$output .= $token['escaped'] ? htmlspecialchars( $str, ENT_QUOTES, 'UTF-8' ) : $str;
					break;

				case 'section':
					$value = $this->lookup( $token['key'], $context_stack );

					if ( $token['inverted'] ) {
						if ( $this->is_falsy( $value ) ) {
							$output .= $this->render_tokens( $token['children'], $context_stack );
						}
					} else {
						if ( $this->is_sequential_array( $value ) ) {
							foreach ( $value as $item ) {
								$item_context = is_array( $item ) ? $item : [ '.' => $item ];
								$output      .= $this->render_tokens(
									$token['children'],
									array_merge( $context_stack, [ $item_context ] )
								);
							}
						} elseif ( is_array( $value ) && ! empty( $value ) ) {
							$output .= $this->render_tokens(
								$token['children'],
								array_merge( $context_stack, [ $value ] )
							);
						} elseif ( ! $this->is_falsy( $value ) ) {
							$output .= $this->render_tokens( $token['children'], $context_stack );
						}
					}
					break;
			}
		}

		return $output;
	}

	/**
	 * Look up a dotted key path in the context stack.
	 *
	 * Walks from the innermost context outward. Supports dot notation
	 * for nested lookups (e.g., "settings.heading").
	 *
	 * @param string $key           The dotted key path.
	 * @param array  $context_stack The context stack.
	 * @return mixed The resolved value, or empty string if not found.
	 */
	private function lookup( string $key, array $context_stack ) {
		if ( '.' === $key ) {
			$top = end( $context_stack );
			return $top['.'] ?? $top;
		}

		$parts = explode( '.', $key );

		// Walk the context stack from innermost to outermost.
		for ( $i = count( $context_stack ) - 1; $i >= 0; $i-- ) {
			$context = $context_stack[ $i ];
			$value   = $this->resolve_path( $context, $parts );

			if ( null !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Resolve a path of keys against a data array.
	 *
	 * Returns null if any segment is missing, so the caller can
	 * try the next context in the stack.
	 *
	 * @param array $data  The data array to traverse.
	 * @param array $parts Array of key segments.
	 * @return mixed|null The resolved value, or null if path not found.
	 */
	private function resolve_path( array $data, array $parts ) {
		$current = $data;

		foreach ( $parts as $part ) {
			if ( is_array( $current ) && array_key_exists( $part, $current ) ) {
				$current = $current[ $part ];
			} else {
				return null;
			}
		}

		return $current;
	}

	/**
	 * Check whether a value is "falsy" in the Mustache sense.
	 *
	 * Falsy values: null, false, empty string, empty array, 0 is NOT falsy
	 * in Mustache (it renders sections).
	 *
	 * @param mixed $value The value to check.
	 * @return bool
	 */
	private function is_falsy( $value ): bool {
		if ( null === $value || false === $value || '' === $value ) {
			return true;
		}
		if ( is_array( $value ) && empty( $value ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Check if an array is a sequential (numerically indexed) array.
	 *
	 * @param mixed $value The value to check.
	 * @return bool
	 */
	private function is_sequential_array( $value ): bool {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return false;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
