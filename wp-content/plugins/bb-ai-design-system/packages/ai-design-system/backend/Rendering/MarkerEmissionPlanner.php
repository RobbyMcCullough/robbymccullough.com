<?php
/**
 * HTML-aware marker emission planner. PHP twin of
 * `frontend/src/core/template-engine/marker-emission-planner.js`. See the
 * JS sibling for the full algorithm contract; output must be byte-identical
 * for every entry in the shared fixture file.
 *
 * @package FL\DesignSystem\Rendering
 */

namespace FL\DesignSystem\Rendering;

use FL\DesignSystem\Rendering\Helpers\MarkerPathDeriver;
use FL\DesignSystem\Services\Parser\FieldTypeResolver;

class MarkerEmissionPlanner {

	private const VOID_ELEMENTS = [
		'area',
		'base',
		'br',
		'col',
		'embed',
		'hr',
		'img',
		'input',
		'link',
		'meta',
		'param',
		'source',
		'track',
		'wbr',
	];

	private const IMAGE_HOST_TAGS = [ 'img', 'source' ];

	private const PICTURE_TAG = 'picture';

	private const LINK_HOST_TAG = 'a';

	private const LINK_HREF_ATTR = 'href';

	private const LINK_SUB_TOKEN_SUFFIXES = [ '.text', '.href', '.target', '.rel' ];

	/**
	 * Build a marker plan for `$template`.
	 *
	 * @param string                $template       Raw template.
	 * @param FieldTypeResolver|null $resolver      Field-type resolver. When
	 *                                              null, resolves every path
	 *                                              to null (text-default).
	 * @return array<int, array{ start:int, end:int, type:string, path:string }>
	 */
	public function plan_markers( string $template, ?FieldTypeResolver $resolver ): array {
		if ( '' === $template ) {
			return [];
		}

		$elements   = []; // tagName, openStart, openEnd, closeStart, closeEnd, parent, isVoid
		$tokens     = []; // start, end, body, isOpen, isClose, sectionDepth, inAttribute, attributeName, enclosingEl, precededByTagOpen
		$open_stack = [];
		$current    = -1;
		// Tracks the attribute name the parser is currently inside while
		// walking an open tag. Reset on tag close.
		$current_attribute_name  = null;
		$current_attribute_quote = null;
		$in_unquoted_value       = false;
		$section                 = 0;

		$len = strlen( $template );
		$i   = 0;

		while ( $i < $len ) {
			// HTML comment.
			if ( '<' === $template[ $i ] && self::str_starts_with( $template, '<!--', $i ) ) {
				$end = strpos( $template, '-->', $i + 4 );
				$i   = false === $end ? $len : $end + 3;
				continue;
			}

			// Mustache token.
			if ( '{' === $template[ $i ] && isset( $template[ $i + 1 ] ) && '{' === $template[ $i + 1 ] ) {
				$open_braces = ( isset( $template[ $i + 2 ] ) && '{' === $template[ $i + 2 ] ) ? 3 : 2;
				$body_start  = $i + $open_braces;
				$close_idx   = strpos( $template, '}}', $body_start );
				if ( false === $close_idx ) {
					$i++;
					continue;
				}
				$close_len = ( 3 === $open_braces && isset( $template[ $close_idx + 2 ] ) && '}' === $template[ $close_idx + 2 ] ) ? 3 : 2;
				if ( 3 === $open_braces && 3 !== $close_len ) {
					$i++;
					continue;
				}
				$token_end = $close_idx + $close_len;
				$body      = trim( substr( $template, $body_start, $close_idx - $body_start ) );
				$is_open   = '' !== $body && ( '#' === $body[0] || '^' === $body[0] );
				$is_close  = '' !== $body && '/' === $body[0];

				$before_trimmed     = rtrim( substr( $template, 0, $i ) );
				$preceded_tag_open  = self::str_ends_with( $before_trimmed, '<' )
					|| self::str_ends_with( $before_trimmed, '</' );

				$tokens[] = [
					'start'             => $i,
					'end'               => $token_end,
					'body'              => $body,
					'isOpen'            => $is_open,
					'isClose'           => $is_close,
					'sectionDepth'      => $section,
					'inAttribute'       => $current >= 0,
					'attributeName'     => $current >= 0 ? $current_attribute_name : null,
					'enclosingEl'       => $current >= 0 ? $current : ( count( $open_stack ) ? $open_stack[ count( $open_stack ) - 1 ] : -1 ),
					'precededByTagOpen' => $preceded_tag_open,
				];

				if ( $is_open ) {
					$section++;
				} elseif ( $is_close && $section > 0 ) {
					$section--;
				}

				$i = $token_end;
				continue;
			}

			// Inside an open tag, walk attribute-name / value state and
			// watch for `>` (or self-close).
			if ( $current >= 0 ) {
				$ch = $template[ $i ];

				if ( '>' === $ch ) {
					$elements[ $current ]['openEnd'] = $i + 1;
					$is_self_close                   = ( $i > 0 && '/' === $template[ $i - 1 ] );
					$is_void                         = $is_self_close || in_array( $elements[ $current ]['tagName'], self::VOID_ELEMENTS, true );
					$elements[ $current ]['isVoid']  = $is_void;
					if ( $is_void ) {
						$elements[ $current ]['closeStart'] = $elements[ $current ]['openEnd'];
						$elements[ $current ]['closeEnd']   = $elements[ $current ]['openEnd'];
					} else {
						$open_stack[] = $current;
					}
					$current                 = -1;
					$current_attribute_name  = null;
					$current_attribute_quote = null;
					$in_unquoted_value       = false;
					$i++;
					continue;
				}

				// Inside a quoted attribute value: hold the attribute name
				// until the closing quote.
				if ( null !== $current_attribute_quote ) {
					if ( $ch === $current_attribute_quote ) {
						$current_attribute_quote = null;
						$current_attribute_name  = null;
					}
					$i++;
					continue;
				}

				// Inside an unquoted attribute value: end on whitespace.
				if ( $in_unquoted_value ) {
					if ( self::is_whitespace( $ch ) ) {
						$in_unquoted_value      = false;
						$current_attribute_name = null;
					}
					$i++;
					continue;
				}

				// Not in a value. If we have an attribute name pending,
				// look for `=` and whether the value is quoted or not.
				if ( null !== $current_attribute_name ) {
					if ( '=' === $ch ) {
						// Skip whitespace between `=` and the value.
						$j = $i + 1;
						while ( $j < $len && self::is_whitespace( $template[ $j ] ) ) {
							$j++;
						}
						if ( $j >= $len ) {
							$i = $len;
							continue;
						}
						$v = $template[ $j ];
						if ( '"' === $v || "'" === $v ) {
							$current_attribute_quote = $v;
							$i                       = $j + 1;
							continue;
						}
						$in_unquoted_value = true;
						$i                 = $j;
						continue;
					}
					if ( self::is_whitespace( $ch ) ) {
						$current_attribute_name = null;
						$i++;
						continue;
					}
					$i++;
					continue;
				}

				// No attribute name pending. Skip whitespace.
				if ( self::is_whitespace( $ch ) ) {
					$i++;
					continue;
				}

				// Self-close marker — handled by the `>` branch.
				if ( '/' === $ch ) {
					$i++;
					continue;
				}

				// Start of an attribute name.
				if ( preg_match( '/[a-zA-Z_:]/', $ch ) ) {
					$name_start = $i;
					$j          = $i + 1;
					while ( $j < $len && preg_match( '/[a-zA-Z0-9_:.-]/', $template[ $j ] ) ) {
						$j++;
					}
					$current_attribute_name = strtolower( substr( $template, $name_start, $j - $name_start ) );
					$i                      = $j;
					continue;
				}

				$i++;
				continue;
			}

			if ( '<' === $template[ $i ] && $current < 0 ) {
				$next = $template[ $i + 1 ] ?? '';

				// `<{{...}}>` dynamic tag name.
				if ( '{' === $next ) {
					$i++;
					continue;
				}

				// `<!DOCTYPE` etc. (HTML comments are caught above).
				if ( '!' === $next ) {
					$end = strpos( $template, '>', $i );
					$i   = false === $end ? $len : $end + 1;
					continue;
				}

				// Close tag.
				if ( '/' === $next ) {
					$tag_name_start = $i + 2;
					$j              = $tag_name_start;
					while ( $j < $len && self::is_tag_char( $template[ $j ] ) ) {
						$j++;
					}
					$tag_name  = strtolower( substr( $template, $tag_name_start, $j - $tag_name_start ) );
					$close_idx = strpos( $template, '>', $j );
					$close_end = false === $close_idx ? $len : $close_idx + 1;
					self::close_open_stack_to( $elements, $open_stack, $tag_name, $i, $close_end );
					$i = $close_end;
					continue;
				}

				if ( preg_match( '/[a-zA-Z]/', $next ) ) {
					$tag_name_start = $i + 1;
					$j              = $tag_name_start;
					while ( $j < $len && self::is_tag_char( $template[ $j ] ) ) {
						$j++;
					}
					$tag_name   = strtolower( substr( $template, $tag_name_start, $j - $tag_name_start ) );
					$current    = count( $elements );
					$elements[] = [
						'tagName'    => $tag_name,
						'openStart'  => $i,
						'openEnd'    => -1,
						'closeStart' => -1,
						'closeEnd'   => -1,
						'parent'     => count( $open_stack ) ? $open_stack[ count( $open_stack ) - 1 ] : -1,
						'isVoid'     => false,
					];
					$current_attribute_name  = null;
					$current_attribute_quote = null;
					$in_unquoted_value       = false;
					$i = $j;
					continue;
				}
			}

			$i++;
		}

		return self::compute_plan( $tokens, $elements, $resolver );
	}

	private static function compute_plan( array $tokens, array $elements, ?FieldTypeResolver $resolver ): array {
		$plan         = [];
		$image_groups = [];
		$link_groups  = [];

		$resolve_token_path = static function ( string $body, int $section_depth ) {
			return self::resolve_token_path( $body, $section_depth );
		};

		foreach ( $tokens as $t ) {
			if ( $t['isOpen'] || $t['isClose'] ) {
				continue;
			}
			if ( $t['precededByTagOpen'] ) {
				continue;
			}

			$path = self::resolve_token_path( $t['body'], $t['sectionDepth'] );
			if ( null === $path ) {
				continue;
			}

			if ( ! $t['inAttribute'] ) {
				$field_type = null === $resolver ? null : $resolver->field_type_at( $path );
				$plan[]     = [
					'start' => $t['start'],
					'end'   => $t['end'],
					'type'  => $field_type ?: 'text',
					'path'  => $path,
				];
				continue;
			}

			if ( $t['enclosingEl'] < 0 ) {
				continue;
			}
			$host_el = $elements[ $t['enclosingEl'] ];

			if ( in_array( $host_el['tagName'], self::IMAGE_HOST_TAGS, true ) ) {
				$picture_id = self::find_ancestor_by_tag( $elements, $t['enclosingEl'], self::PICTURE_TAG );
				$wrap_id    = $picture_id >= 0 ? $picture_id : $t['enclosingEl'];
				if ( ! isset( $image_groups[ $wrap_id ] ) ) {
					$image_groups[ $wrap_id ] = [ 'tokens' => [] ];
				}
				$image_groups[ $wrap_id ]['tokens'][] = $t;
				continue;
			}

			// Link-host gate: only `<a>` participates, and only when the
			// token is in `href` OR its path ends in a LINK_SUB_TOKENS
			// suffix.
			if ( self::LINK_HOST_TAG === $host_el['tagName'] ) {
				if ( ! self::qualifies_as_link_token( $t, $path ) ) {
					continue;
				}
				$wrap_id = $t['enclosingEl'];
				if ( ! isset( $link_groups[ $wrap_id ] ) ) {
					$link_groups[ $wrap_id ] = [ 'tokens' => [] ];
				}
				$link_groups[ $wrap_id ]['tokens'][] = $t;
			}
		}

		foreach ( $image_groups as $wrap_id => $group ) {
			$wrap = $elements[ $wrap_id ] ?? null;
			if ( null === $wrap || $wrap['openStart'] < 0 || $wrap['closeEnd'] < 0 ) {
				continue;
			}
			$image_path = MarkerPathDeriver::derive_image_path( $group['tokens'], $resolve_token_path );
			if ( '' === $image_path ) {
				continue;
			}
			$plan[] = [
				'start' => $wrap['openStart'],
				'end'   => $wrap['closeEnd'],
				'type'  => 'image',
				'path'  => $image_path,
			];
		}

		foreach ( $link_groups as $wrap_id => $group ) {
			$wrap = $elements[ $wrap_id ] ?? null;
			if ( null === $wrap || $wrap['openStart'] < 0 || $wrap['closeEnd'] < 0 ) {
				continue;
			}
			$link_path = MarkerPathDeriver::derive_link_path( $group['tokens'], $resolve_token_path );
			if ( '' === $link_path ) {
				continue;
			}
			$plan[] = [
				'start' => $wrap['openStart'],
				'end'   => $wrap['closeEnd'],
				'type'  => 'link',
				'path'  => $link_path,
			];
		}

		usort(
			$plan,
			static function ( $a, $b ) {
				if ( $a['start'] !== $b['start'] ) {
					return $a['start'] - $b['start'];
				}
				return $b['end'] - $a['end'];
			}
		);
		return $plan;
	}

	private static function qualifies_as_link_token( array $t, string $path ): bool {
		if ( ( $t['attributeName'] ?? null ) === self::LINK_HREF_ATTR ) {
			return true;
		}
		foreach ( self::LINK_SUB_TOKEN_SUFFIXES as $suffix ) {
			if ( self::str_ends_with( $path, $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	private static function resolve_token_path( string $body, int $section_depth ): ?string {
		if ( '' === $body ) {
			return null;
		}
		$first = $body[0];
		if ( '#' === $first || '^' === $first || '/' === $first ) {
			return null;
		}
		if ( 0 === strpos( $body, 'settings.' ) ) {
			return substr( $body, strlen( 'settings.' ) );
		}
		if ( $section_depth > 0 ) {
			return '{{__bb_path}}.' . $body;
		}
		return null;
	}

	private static function find_ancestor_by_tag( array $elements, int $start_id, string $tag_name ): int {
		$cur = $elements[ $start_id ]['parent'] ?? -1;
		while ( $cur >= 0 ) {
			if ( ( $elements[ $cur ]['tagName'] ?? '' ) === $tag_name ) {
				return $cur;
			}
			$cur = $elements[ $cur ]['parent'] ?? -1;
		}
		return -1;
	}

	private static function close_open_stack_to( array &$elements, array &$open_stack, string $tag_name, int $close_start, int $close_end ): void {
		for ( $k = count( $open_stack ) - 1; $k >= 0; $k-- ) {
			$id = $open_stack[ $k ];
			if ( $elements[ $id ]['tagName'] === $tag_name ) {
				for ( $m = count( $open_stack ) - 1; $m > $k; $m-- ) {
					$inner_id                            = $open_stack[ $m ];
					$elements[ $inner_id ]['closeStart'] = $close_start;
					$elements[ $inner_id ]['closeEnd']   = $close_end;
				}
				$elements[ $id ]['closeStart'] = $close_start;
				$elements[ $id ]['closeEnd']   = $close_end;
				$open_stack                    = array_slice( $open_stack, 0, $k );
				return;
			}
		}
	}

	private static function is_tag_char( string $ch ): bool {
		return 1 === preg_match( '/[a-zA-Z0-9-]/', $ch );
	}

	private static function is_whitespace( string $ch ): bool {
		return ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch || "\f" === $ch;
	}

	private static function str_starts_with( string $haystack, string $needle, int $offset = 0 ): bool {
		return substr( $haystack, $offset, strlen( $needle ) ) === $needle;
	}

	private static function str_ends_with( string $haystack, string $needle ): bool {
		$nl = strlen( $needle );
		if ( 0 === $nl ) {
			return true;
		}
		return substr( $haystack, -$nl ) === $needle;
	}
}
