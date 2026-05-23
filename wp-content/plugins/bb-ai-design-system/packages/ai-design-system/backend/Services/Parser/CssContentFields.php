<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * CSS Content Fields
 *
 * PHP port of the JS rewriter at
 * frontend/src/core/services/css-content-fields/rewriter.js.
 *
 * Promotes external-URL `background-image` declarations into editable image
 * fields by rewriting the CSS rule to read from a custom property and
 * tagging the matching HTML element with a Mustache-tokenized inline style.
 *
 * See decision 001: css-background-images-as-content-fields.
 */
class CssContentFields {

	/**
	 * Rewrite CSS and mutate DOM for content-field background images.
	 *
	 * @param string      $css              CSS source for the block/section.
	 * @param \DOMElement $dom              Template DOM root (mutated in place).
	 * @param array       $existing_settings Optional, to preserve values across re-parse.
	 * @return array{
	 *     css: string,
	 *     settings: array,
	 *     fields: array<array{key:string,label:string,mediaQuery:?string,defaultUrl:string,insideRepeater:bool,repeaterKey:?string}>,
	 *     changed: bool,
	 * }
	 */
	public static function rewrite( string $css, \DOMElement $dom, array $existing_settings = [] ): array {
		if ( '' === $css ) {
			return [
				'css'      => $css,
				'settings' => [],
				'fields'   => [],
				'changed'  => false,
			];
		}

		$candidates = self::walk_candidates( $css );
		if ( empty( $candidates ) ) {
			return [
				'css'      => $css,
				'settings' => [],
				'fields'   => [],
				'changed'  => false,
			];
		}

		$result           = [
			'css'      => $css,
			'settings' => [],
			'fields'   => [],
			'changed'  => false,
		];
		$keys_by_selector = [];
		$pending_styles   = []; // Element hash => list of {property, value}.
		$element_map      = []; // hash => DOMElement.
		$edits            = [];
		$variation_sets   = self::build_variation_class_sets( $dom );

		// Pre-register already-rewritten keys.
		foreach ( $candidates as $c ) {
			if ( 'already-rewritten' === ( $c['skipReason'] ?? null ) && ! empty( $c['existingCustomProperty'] ) ) {
				$key = self::custom_property_to_key( $c['existingCustomProperty'] );
				if ( $key ) {
					self::register_key( $keys_by_selector, $c['selector'], $key );
				}
			}
		}

		foreach ( $candidates as $c ) {
			$skip_reason = $c['skipReason'] ?? null;

			if ( 'already-rewritten' === $skip_reason ) {
				self::handle_already_rewritten(
					$c,
					$dom,
					$variation_sets,
					$existing_settings,
					$result,
					$pending_styles,
					$element_map
				);
				continue;
			}
			if ( null !== $skip_reason ) {
				continue;
			}

			$element = self::find_element_for_selector( $c['selector'], $dom, $variation_sets );
			if ( ! $element ) {
				continue;
			}
			if ( self::element_is_aria_hidden( $element ) ) {
				continue;
			}

			$content_values = array_values( array_filter(
				$c['values'],
				static fn( $v ) => 'content' === $v['classification']
			) );
			if ( empty( $content_values ) ) {
				continue;
			}

			$repeater_key            = self::find_repeater_key( $element );
			$effective_inside_repeat = (bool) $repeater_key;
			$is_multi_value_content  = count( $content_values ) > 1;

			$new_value_segments = [];
			$cursor             = $c['valueStart'];
			$new_fields         = [];

			foreach ( $c['values'] as $v ) {
				$new_value_segments[] = substr( $css, $cursor, $v['valueStart'] - $cursor );

				if ( 'content' === $v['classification'] ) {
					$key             = self::allocate_key( $keys_by_selector, $c['selector'], $existing_settings, $result['settings'] );
					$custom_property = self::key_to_custom_property( $key );

					$new_value_segments[] = 'var(' . $custom_property . ')';

					$token_value               = self::build_inline_style_token_value( $key, $effective_inside_repeat );
					$hash                      = spl_object_hash( $element );
					$element_map[ $hash ]      = $element;
					$pending_styles[ $hash ][] = [
						'customProperty' => $custom_property,
						'tokenValue'     => $token_value,
					];

					$new_fields[] = [
						'key'            => $key,
						'label'          => self::build_label( $key, $c['mediaQuery'] ?? null, $is_multi_value_content ),
						'mediaQuery'     => $c['mediaQuery'] ?? null,
						'defaultUrl'     => $v['url'],
						'insideRepeater' => $effective_inside_repeat,
						'repeaterKey'    => $repeater_key,
					];
				} else {
					$new_value_segments[] = substr( $css, $v['valueStart'], $v['valueEnd'] - $v['valueStart'] );
				}
				$cursor = $v['valueEnd'];
			}
			$new_value_segments[] = substr( $css, $cursor, $c['valueEnd'] - $cursor );

			$edits[] = [
				'start'       => $c['valueStart'],
				'end'         => $c['valueEnd'],
				'replacement' => implode( '', $new_value_segments ),
			];

			foreach ( $new_fields as $field ) {
				$result['fields'][] = $field;
				if ( ! $field['insideRepeater'] ) {
					$existing                            = $existing_settings[ $field['key'] ] ?? null;
					$result['settings'][ $field['key'] ] = $existing ?? [
						'url'  => $field['defaultUrl'],
						'alt'  => '',
						'id'   => null,
						'size' => null,
					];
				}
			}
		}

		// Apply CSS edits from end to start.
		if ( ! empty( $edits ) ) {
			usort( $edits, static fn( $a, $b ) => $b['start'] - $a['start'] );
			$new_css = $css;
			foreach ( $edits as $edit ) {
				$new_css = substr( $new_css, 0, $edit['start'] ) . $edit['replacement'] . substr( $new_css, $edit['end'] );
			}
			$result['css']     = $new_css;
			$result['changed'] = true;
		}

		// Apply pending inline-style tokens.
		foreach ( $pending_styles as $hash => $tokens ) {
			$element = $element_map[ $hash ];
			foreach ( $tokens as $t ) {
				self::append_custom_property_to_style( $element, $t['customProperty'], $t['tokenValue'] );
			}
			$result['changed'] = true;
		}

		return $result;
	}

	// ─── CSS walker ────────────────────────────────────────────────────

	private static function walk_candidates( string $css ): array {
		$candidates = [];
		self::walk_blocks( $css, 0, strlen( $css ), null, $candidates );
		return $candidates;
	}

	private static function walk_blocks( string $css, int $start, int $end, ?string $media_query, array &$candidates ): void {
		$supported_at_rules = [ 'media' ];
		$i                  = $start;

		while ( $i < $end ) {
			while ( $i < $end && ctype_space( $css[ $i ] ) ) {
				$i++;
			}
			if ( $i >= $end ) {
				break;
			}

			if ( '@' === $css[ $i ] ) {
				$at_rule = self::parse_at_rule_header( $css, $i, $end );
				if ( ! $at_rule ) {
					break;
				}
				if ( $at_rule['isBlock'] && in_array( $at_rule['name'], $supported_at_rules, true ) ) {
					$nested = self::nested_media( $media_query, $at_rule['prelude'] );
					self::walk_blocks( $css, $at_rule['bodyStart'], $at_rule['bodyEnd'], $nested, $candidates );
					$i = $at_rule['blockEnd'];
				} else {
					$i = $at_rule['isBlock'] ? $at_rule['blockEnd'] : $at_rule['statementEnd'];
				}
				continue;
			}

			if ( '/' === $css[ $i ] && ( $i + 1 < $end ) && '*' === $css[ $i + 1 ] ) {
				$close = strpos( $css, '*/', $i + 2 );
				$i     = ( false === $close ) ? $end : $close + 2;
				continue;
			}

			$rule = self::parse_style_rule( $css, $i, $end );
			if ( ! $rule ) {
				break;
			}
			self::process_rule( $css, $rule, $media_query, $candidates );
			$i = $rule['blockEnd'];
		}
	}

	private static function parse_style_rule( string $css, int $i, int $end ): ?array {
		$selector_start = $i;
		$paren_depth    = 0;
		$brace_open     = -1;

		while ( $i < $end ) {
			$ch = $css[ $i ];
			if ( '/' === $ch && ( $i + 1 < $end ) && '*' === $css[ $i + 1 ] ) {
				$close = strpos( $css, '*/', $i + 2 );
				$i     = ( false === $close ) ? $end : $close + 2;
				continue;
			}
			if ( '(' === $ch ) {
				$paren_depth++;
			} elseif ( ')' === $ch ) {
				--$paren_depth;
			} elseif ( 0 === $paren_depth && '{' === $ch ) {
				$brace_open = $i;
				$i++;
				break;
			}
			$i++;
		}

		if ( -1 === $brace_open ) {
			return null;
		}

		$selector   = trim( substr( $css, $selector_start, $brace_open - $selector_start ) );
		$body_start = $brace_open + 1;
		$body_end   = self::find_block_end( $css, $body_start, $end );

		return [
			'selector'  => $selector,
			'bodyStart' => $body_start,
			'bodyEnd'   => $body_end,
			'blockEnd'  => $body_end + 1,
		];
	}

	private static function parse_at_rule_header( string $css, int $i, int $end ): ?array {
		if ( '@' !== $css[ $i ] ) {
			return null;
		}
		$i++;
		$name_start = $i;
		while ( $i < $end && preg_match( '/[a-zA-Z-]/', $css[ $i ] ) ) {
			$i++;
		}
		$name          = strtolower( substr( $css, $name_start, $i - $name_start ) );
		$prelude_start = $i;
		$paren_depth   = 0;

		while ( $i < $end ) {
			$ch = $css[ $i ];
			if ( '/' === $ch && ( $i + 1 < $end ) && '*' === $css[ $i + 1 ] ) {
				$close = strpos( $css, '*/', $i + 2 );
				$i     = ( false === $close ) ? $end : $close + 2;
				continue;
			}
			if ( '(' === $ch ) {
				$paren_depth++;
			} elseif ( ')' === $ch ) {
				--$paren_depth;
			} elseif ( 0 === $paren_depth && '{' === $ch ) {
				$prelude    = trim( substr( $css, $prelude_start, $i - $prelude_start ) );
				$body_start = $i + 1;
				$body_end   = self::find_block_end( $css, $body_start, $end );
				return [
					'name'      => $name,
					'prelude'   => $prelude,
					'isBlock'   => true,
					'bodyStart' => $body_start,
					'bodyEnd'   => $body_end,
					'blockEnd'  => $body_end + 1,
				];
			} elseif ( 0 === $paren_depth && ';' === $ch ) {
				$prelude = trim( substr( $css, $prelude_start, $i - $prelude_start ) );
				return [
					'name'         => $name,
					'prelude'      => $prelude,
					'isBlock'      => false,
					'statementEnd' => $i + 1,
				];
			}
			$i++;
		}
		return null;
	}

	private static function find_block_end( string $css, int $body_start, int $end ): int {
		$depth = 1;
		$i     = $body_start;
		while ( $i < $end && $depth > 0 ) {
			$ch = $css[ $i ];
			if ( '/' === $ch && ( $i + 1 < $end ) && '*' === $css[ $i + 1 ] ) {
				$close = strpos( $css, '*/', $i + 2 );
				$i     = ( false === $close ) ? $end : $close + 2;
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$i = self::skip_string( $css, $i, $end, $ch );
				continue;
			}
			if ( '{' === $ch ) {
				$depth++;
			} elseif ( '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
			$i++;
		}
		return $end;
	}

	private static function skip_string( string $css, int $i, int $end, string $quote ): int {
		$i++;
		while ( $i < $end ) {
			if ( '\\' === $css[ $i ] ) {
				$i += 2;
				continue;
			}
			if ( $quote === $css[ $i ] ) {
				return $i + 1;
			}
			$i++;
		}
		return $end;
	}

	private static function process_rule( string $css, array $rule, ?string $media_query, array &$candidates ): void {
		$is_class_chain = self::is_class_chain_selector( $rule['selector'] );
		$decls          = self::find_background_image_declarations( $css, $rule['bodyStart'], $rule['bodyEnd'] );

		foreach ( $decls as $decl ) {
			$candidate = self::build_candidate( $css, $rule, $media_query, $decl, $is_class_chain );
			if ( null !== $candidate ) {
				$candidates[] = $candidate;
			}
		}
	}

	private static function find_background_image_declarations( string $css, int $body_start, int $body_end ): array {
		$decls = [];
		$i     = $body_start;

		while ( $i < $body_end ) {
			while ( $i < $body_end ) {
				if ( ctype_space( $css[ $i ] ) ) {
					$i++;
					continue;
				}
				if ( '/' === $css[ $i ] && ( $i + 1 < $body_end ) && '*' === $css[ $i + 1 ] ) {
					$close = strpos( $css, '*/', $i + 2 );
					$i     = ( false === $close ) ? $body_end : $close + 2;
					continue;
				}
				break;
			}
			if ( $i >= $body_end ) {
				break;
			}
			if ( '{' === $css[ $i ] ) {
				$nested_end = self::find_block_end( $css, $i + 1, $body_end );
				$i          = $nested_end + 1;
				continue;
			}

			$prop_start = $i;
			while ( $i < $body_end && preg_match( '/[a-zA-Z0-9_-]/', $css[ $i ] ) ) {
				$i++;
			}
			$prop_name = strtolower( substr( $css, $prop_start, $i - $prop_start ) );

			while ( $i < $body_end && ctype_space( $css[ $i ] ) ) {
				$i++;
			}

			if ( ':' !== ( $css[ $i ] ?? '' ) ) {
				$i = self::find_declaration_end( $css, $i, $body_end );
				continue;
			}
			$i++;

			while ( $i < $body_end && ctype_space( $css[ $i ] ) ) {
				$i++;
			}

			$value_start = $i;
			$decl_end    = self::find_declaration_end( $css, $i, $body_end );
			$value_end   = self::trim_trailing_semicolon( $css, $value_start, $decl_end );

			if ( 'background-image' === $prop_name ) {
				$decls[] = [
					'propertyStart'  => $prop_start,
					'valueStart'     => $value_start,
					'valueEnd'       => $value_end,
					'declarationEnd' => $decl_end,
				];
			}

			$i = $decl_end;
		}

		return $decls;
	}

	private static function find_declaration_end( string $css, int $start, int $end ): int {
		$i           = $start;
		$paren_depth = 0;
		while ( $i < $end ) {
			$ch = $css[ $i ];
			if ( '/' === $ch && ( $i + 1 < $end ) && '*' === $css[ $i + 1 ] ) {
				$close = strpos( $css, '*/', $i + 2 );
				$i     = ( false === $close ) ? $end : $close + 2;
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) {
				$i = self::skip_string( $css, $i, $end, $ch );
				continue;
			}
			if ( '(' === $ch ) {
				$paren_depth++;
			} elseif ( ')' === $ch ) {
				--$paren_depth;
			} elseif ( 0 === $paren_depth && ( ';' === $ch || '}' === $ch ) ) {
				return ( ';' === $ch ) ? $i + 1 : $i;
			}
			$i++;
		}
		return $end;
	}

	private static function trim_trailing_semicolon( string $css, int $start, int $end ): int {
		$i = $end;
		if ( $i > $start && ';' === $css[ $i - 1 ] ) {
			--$i;
		}
		while ( $i > $start && ctype_space( $css[ $i - 1 ] ) ) {
			--$i;
		}
		return $i;
	}

	private static function build_candidate( string $css, array $rule, ?string $media_query, array $decl, bool $is_class_chain ): ?array {
		$value_string = substr( $css, $decl['valueStart'], $decl['valueEnd'] - $decl['valueStart'] );

		// Already-rewritten single-value form.
		if ( preg_match( '/^\s*var\(\s*(--settings-[a-zA-Z0-9_-]+)\s*\)\s*$/', $value_string, $m ) ) {
			return array_merge( $rule, [
				'selector'               => $rule['selector'],
				'mediaQuery'             => $media_query,
				'property'               => 'background-image',
				'declarationStart'       => $decl['propertyStart'],
				'declarationEnd'         => $decl['declarationEnd'],
				'valueStart'             => $decl['valueStart'],
				'valueEnd'               => $decl['valueEnd'],
				'values'                 => [],
				'existingCustomProperty' => $m[1],
				'skipReason'             => 'already-rewritten',
			] );
		}

		if ( self::declaration_has_no_field_comment( $css, $decl['propertyStart'] ) ) {
			return [
				'selector'         => $rule['selector'],
				'mediaQuery'       => $media_query,
				'property'         => 'background-image',
				'declarationStart' => $decl['propertyStart'],
				'declarationEnd'   => $decl['declarationEnd'],
				'valueStart'       => $decl['valueStart'],
				'valueEnd'         => $decl['valueEnd'],
				'values'           => [],
				'skipReason'       => 'no-field-comment',
			];
		}

		if ( ! $is_class_chain ) {
			return [
				'selector'         => $rule['selector'],
				'mediaQuery'       => $media_query,
				'property'         => 'background-image',
				'declarationStart' => $decl['propertyStart'],
				'declarationEnd'   => $decl['declarationEnd'],
				'valueStart'       => $decl['valueStart'],
				'valueEnd'         => $decl['valueEnd'],
				'values'           => [],
				'skipReason'       => 'not-class-chain-selector',
			];
		}

		$raw_urls = self::extract_urls_from_value( $value_string );
		$values   = [];
		foreach ( $raw_urls as $u ) {
			$values[] = [
				'url'            => $u['url'],
				'valueStart'     => $decl['valueStart'] + $u['start'],
				'valueEnd'       => $decl['valueStart'] + $u['end'],
				'quote'          => $u['quote'],
				'classification' => self::classify_url( $u['url'] ),
			];
		}

		$has_content = false;
		foreach ( $values as $v ) {
			if ( 'content' === $v['classification'] ) {
				$has_content = true;
				break;
			}
		}
		if ( ! $has_content ) {
			return null;
		}

		return [
			'selector'         => $rule['selector'],
			'mediaQuery'       => $media_query,
			'property'         => 'background-image',
			'declarationStart' => $decl['propertyStart'],
			'declarationEnd'   => $decl['declarationEnd'],
			'valueStart'       => $decl['valueStart'],
			'valueEnd'         => $decl['valueEnd'],
			'values'           => $values,
			'skipReason'       => null,
		];
	}

	// ─── URL classification ────────────────────────────────────────────

	public static function extract_urls_from_value( string $value ): array {
		$results = [];
		$length  = strlen( $value );
		$i       = 0;
		$depth   = 0;

		while ( $i < $length ) {
			$ch = $value[ $i ];
			if ( '(' === $ch ) {
				if ( 0 === $depth && self::is_url_start( $value, $i ) ) {
					$call = self::parse_url_call( $value, $i );
					if ( $call ) {
						$results[] = $call;
						$i         = $call['end'];
						continue;
					}
				}
				$depth++;
			} elseif ( ')' === $ch ) {
				--$depth;
			}
			$i++;
		}

		return $results;
	}

	private static function is_url_start( string $str, int $paren_index ): bool {
		if ( $paren_index < 3 ) {
			return false;
		}
		$prev = strtolower( substr( $str, $paren_index - 3, 3 ) );
		if ( 'url' !== $prev ) {
			return false;
		}
		if ( $paren_index - 4 >= 0 ) {
			$before = $str[ $paren_index - 4 ];
			if ( preg_match( '/[a-zA-Z0-9_-]/', $before ) ) {
				return false;
			}
		}
		return true;
	}

	private static function parse_url_call( string $str, int $paren_index ): ?array {
		$start  = $paren_index - 3;
		$length = strlen( $str );
		$i      = $paren_index + 1;
		while ( $i < $length && ctype_space( $str[ $i ] ) ) {
			$i++;
		}

		$quote     = '';
		$url_start = $i;

		if ( $i < $length && ( '"' === $str[ $i ] || "'" === $str[ $i ] ) ) {
			$quote = $str[ $i ];
			$i++;
			$url_start = $i;
			while ( $i < $length && $str[ $i ] !== $quote ) {
				$i++;
			}
			$url_end = $i;
			if ( $i < $length ) {
				$i++;
			}
		} else {
			while ( $i < $length && ')' !== $str[ $i ] ) {
				$i++;
			}
			$url_end = $i;
		}

		while ( $i < $length && ctype_space( $str[ $i ] ) ) {
			$i++;
		}
		if ( $i >= $length || ')' !== $str[ $i ] ) {
			return null;
		}
		$end = $i + 1;
		$url = trim( substr( $str, $url_start, $url_end - $url_start ) );
		return [
			'url'   => $url,
			'start' => $start,
			'end'   => $end,
			'quote' => $quote,
		];
	}

	public static function classify_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return 'styling';
		}
		if ( 0 === strncmp( $url, 'data:', 5 ) ) {
			return 'styling';
		}
		if ( 0 === strncmp( $url, 'blob:', 5 ) ) {
			return 'styling';
		}
		if ( 0 === strncmp( $url, '//', 2 ) ) {
			return 'styling';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return 'content';
		}
		return 'styling';
	}

	private static function declaration_has_no_field_comment( string $css, int $decl_start ): bool {
		if ( $decl_start <= 0 ) {
			return false;
		}
		$i = $decl_start - 1;
		while ( $i >= 0 && ctype_space( $css[ $i ] ) ) {
			--$i;
		}
		if ( $i < 1 || '/' !== $css[ $i ] || '*' !== $css[ $i - 1 ] ) {
			return false;
		}
		$comment_end  = $i + 1;
		$comment_open = strrpos( substr( $css, 0, $i - 1 ), '/*' );
		if ( false === $comment_open ) {
			return false;
		}
		$comment = substr( $css, $comment_open, $comment_end - $comment_open );
		return (bool) preg_match( '/\/\*\s*@no-field\s*\*\//', $comment );
	}

	// ─── Selector handling ─────────────────────────────────────────────

	public static function is_class_chain_selector( string $selector ): bool {
		if ( '' === $selector ) {
			return false;
		}
		if ( preg_match( '/[^a-zA-Z0-9\s._-]/', $selector ) ) {
			return false;
		}
		$trimmed = trim( $selector );
		if ( '.' !== ( $trimmed[0] ?? '' ) ) {
			return false;
		}
		$segments = preg_split( '/\s+/', $trimmed );
		foreach ( $segments as $seg ) {
			if ( ! preg_match( '/^(?:\.[a-zA-Z_][a-zA-Z0-9_-]*)+$/', $seg ) ) {
				return false;
			}
		}
		return true;
	}

	private static function parse_selector_segments( string $selector ): array {
		$trimmed = trim( $selector );
		if ( '' === $trimmed ) {
			return [];
		}
		$segments = [];
		foreach ( preg_split( '/\s+/', $trimmed ) as $seg ) {
			$classes = array_filter( explode( '.', $seg ) );
			if ( ! empty( $classes ) ) {
				$segments[] = array_values( $classes );
			}
		}
		return $segments;
	}

	private static function find_element_for_selector( string $selector, \DOMElement $root, array $variation_sets ): ?\DOMElement {
		$segments = self::parse_selector_segments( $selector );
		if ( empty( $segments ) ) {
			return null;
		}

		$xpath = new \DOMXPath( $root->ownerDocument );
		// Traverse all descendants in document order.
		$iter = $xpath->query( './/*', $root );
		if ( false === $iter ) {
			return null;
		}
		foreach ( $iter as $el ) {
			if ( ! $el instanceof \DOMElement ) {
				continue;
			}
			if ( self::element_matches( $el, $segments, $variation_sets ) ) {
				return $el;
			}
		}
		return null;
	}

	private static function element_matches( \DOMElement $el, array $segments, array $variation_sets ): bool {
		$last = $segments[ count( $segments ) - 1 ];
		if ( ! self::element_has_all_classes( $el, $last, $variation_sets ) ) {
			return false;
		}
		if ( 1 === count( $segments ) ) {
			return true;
		}
		$seg_idx = count( $segments ) - 2;
		$current = $el->parentNode;
		while ( $current instanceof \DOMElement && $seg_idx >= 0 ) {
			if ( self::element_has_all_classes( $current, $segments[ $seg_idx ], $variation_sets ) ) {
				--$seg_idx;
			}
			$current = $current->parentNode;
		}
		return $seg_idx < 0;
	}

	private static function element_has_all_classes( \DOMElement $el, array $required, array $variation_sets ): bool {
		$effective = self::get_effective_class_set( $el, $variation_sets );
		foreach ( $required as $cls ) {
			if ( ! in_array( $cls, $effective, true ) ) {
				return false;
			}
		}
		return true;
	}

	private static function get_effective_class_set( \DOMElement $el, array $variation_sets ): array {
		$attr = $el->getAttribute( 'class' );
		$set  = [];
		foreach ( preg_split( '/\s+/', preg_replace( '/\{\{\{?[^}]*\}\}?\}/', ' ', $attr ) ) as $cls ) {
			if ( '' !== $cls ) {
				$set[] = $cls;
			}
		}

		if ( preg_match( '/\{\{\{?[^}]*\}\}?\}/', $attr ) ) {
			$variation_key = self::find_repeater_key_for_item( $el );
			if ( $variation_key && isset( $variation_sets[ $variation_key ] ) ) {
				foreach ( $variation_sets[ $variation_key ] as $cls ) {
					if ( '' !== $cls ) {
						$set[] = $cls;
					}
				}
			}
		}

		return array_values( array_unique( $set ) );
	}

	private static function find_repeater_key_for_item( \DOMElement $el ): ?string {
		$current = $el;
		while ( $current instanceof \DOMElement ) {
			$key = $current->getAttribute( 'data-repeater' );
			if ( '' !== $key ) {
				return $key;
			}
			$current = $current->parentNode;
		}
		return null;
	}

	private static function build_variation_class_sets( \DOMElement $root ): array {
		$sets      = [];
		$xpath     = new \DOMXPath( $root->ownerDocument );
		$repeaters = $xpath->query( './/*[@data-repeater]', $root );
		if ( false === $repeaters ) {
			return $sets;
		}
		foreach ( $repeaters as $rep ) {
			if ( ! $rep instanceof \DOMElement ) {
				continue;
			}
			$key = $rep->getAttribute( 'data-repeater' );
			if ( '' === $key ) {
				continue;
			}
			$classes = [];
			foreach ( $rep->childNodes as $child ) {
				if ( ! $child instanceof \DOMElement ) {
					continue;
				}
				if ( ! $child->hasAttribute( 'data-repeater-item' ) ) {
					continue;
				}
				$attr = $child->getAttribute( 'class' );
				foreach ( preg_split( '/\s+/', $attr ) as $cls ) {
					if ( '' !== $cls ) {
						$classes[] = $cls;
					}
				}
			}
			$sets[ $key ] = array_values( array_unique( $classes ) );
		}
		return $sets;
	}

	// ─── Opt-outs ──────────────────────────────────────────────────────

	private static function element_is_aria_hidden( \DOMElement $element ): bool {
		$current = $element;
		while ( $current instanceof \DOMElement ) {
			if ( 'true' === $current->getAttribute( 'aria-hidden' ) ) {
				return true;
			}
			$current = $current->parentNode;
		}
		return false;
	}

	private static function find_repeater_key( \DOMElement $element ): ?string {
		$current = $element;
		while ( $current instanceof \DOMElement ) {
			$key = $current->getAttribute( 'data-repeater' );
			if ( '' !== $key ) {
				return $key;
			}
			$current = $current->parentNode;
		}
		return null;
	}

	// ─── Key + style helpers ───────────────────────────────────────────

	public static function custom_property_to_key( string $custom_property ): ?string {
		if ( ! preg_match( '/^--settings-([a-zA-Z0-9_-]+)$/', $custom_property, $m ) ) {
			return null;
		}
		return str_replace( '-', '_', $m[1] );
	}

	public static function key_to_custom_property( string $key ): string {
		return '--settings-' . str_replace( '_', '-', $key );
	}

	private static function allocate_key( array &$keys_by_selector, string $selector, array $existing_settings, array $new_settings ): string {
		$base_key  = self::selector_to_key( $selector );
		$used      = isset( $keys_by_selector[ $selector ] ) ? $keys_by_selector[ $selector ] : [];
		$candidate = $base_key;
		$n         = 2;
		while ( in_array( $candidate, $used, true ) ||
			( ! in_array( $candidate, $used, true ) && isset( $new_settings[ $candidate ] ) )
		) {
			$candidate = $base_key . '_' . $n;
			$n++;
		}
		self::register_key( $keys_by_selector, $selector, $candidate );
		return $candidate;
	}

	private static function register_key( array &$map, string $selector, string $key ): void {
		if ( ! isset( $map[ $selector ] ) ) {
			$map[ $selector ] = [];
		}
		if ( ! in_array( $key, $map[ $selector ], true ) ) {
			$map[ $selector ][] = $key;
		}
	}

	private static function selector_to_key( string $selector ): string {
		$segments = preg_split( '/\s+/', trim( $selector ) );
		$last     = end( $segments );
		if ( false === $last ) {
			$last = '';
		}
		$classes = array_filter( explode( '.', $last ) );
		$slug    = implode( '_', array_map( static fn( $c ) => strtolower( str_replace( '-', '_', $c ) ), $classes ) );
		return $slug . '_background';
	}

	private static function build_label( string $key, ?string $media_query, bool $is_multi_value ): string {
		$acronyms = [ 'cta', 'url', 'svg', 'html', 'css', 'api', 'id', 'faq' ];
		$parts    = preg_split( '/[_-]/', $key );
		$label    = implode(
			' ',
			array_map(
				static function ( $word ) use ( $acronyms ) {
					$lower = strtolower( $word );
					if ( in_array( $lower, $acronyms, true ) ) {
						return strtoupper( $word );
					}
					return ucfirst( $word );
				},
				$parts
			)
		);
		if ( $media_query ) {
			$trimmed = trim( $media_query );
			$wrapped = ( 0 === strpos( $trimmed, '(' ) && substr( $trimmed, -1 ) === ')' ) ? $trimmed : '(' . $trimmed . ')';
			$label  .= ' ' . $wrapped;
		}
		return $label;
	}

	private static function build_inline_style_token_value( string $key, bool $inside_repeater ): string {
		$prefix = $inside_repeater ? '' : 'settings.';
		return 'url({{{' . $prefix . $key . '.url}}})';
	}

	private static function append_custom_property_to_style( \DOMElement $element, string $custom_property, string $token_value ): void {
		$existing = $element->getAttribute( 'style' );
		$decl     = $custom_property . ': ' . $token_value;
		if ( self::style_contains_custom_property( $element, $custom_property ) ) {
			return;
		}
		$trimmed = trim( $existing );
		if ( '' === $trimmed ) {
			$element->setAttribute( 'style', $decl );
			return;
		}
		$sep = ( substr( $trimmed, -1 ) === ';' ) ? ' ' : '; ';
		$element->setAttribute( 'style', $trimmed . $sep . $decl );
	}

	private static function style_contains_custom_property( \DOMElement $element, string $custom_property ): bool {
		$existing = $element->getAttribute( 'style' );
		$escaped  = preg_quote( $custom_property, '/' );
		return (bool) preg_match( '/(?:^|[;\s])' . $escaped . '\s*:/', $existing );
	}

	// ─── Already-rewritten handler ─────────────────────────────────────

	/**
	 * Narrowed responsibility (see plan css-background-roundtrip-tokenizer):
	 * keep field registration and the defensive declaration-existence write.
	 * The inline-style tokenizer owns rewriting concrete URLs back to tokens —
	 * this path now only fires when the element's style attribute does not
	 * carry the `--settings-X:` declaration at all (broken stored data,
	 * hand-edited template). It re-emits the declaration with the mustache
	 * token form so the next reconstruct can paint again.
	 */
	private static function handle_already_rewritten( array $candidate, \DOMElement $dom, array $variation_sets, array $existing_settings, array &$result, array &$pending_styles, array &$element_map ): void {
		$key = self::custom_property_to_key( $candidate['existingCustomProperty'] );
		if ( null === $key ) {
			return;
		}
		$element = self::find_element_for_selector( $candidate['selector'], $dom, $variation_sets );
		if ( ! $element ) {
			return;
		}
		if ( self::element_is_aria_hidden( $element ) ) {
			return;
		}

		$repeater_key    = self::find_repeater_key( $element );
		$inside_repeater = (bool) $repeater_key;

		$result['fields'][] = [
			'key'            => $key,
			'label'          => self::build_label( $key, $candidate['mediaQuery'] ?? null, false ),
			'mediaQuery'     => $candidate['mediaQuery'] ?? null,
			'defaultUrl'     => $existing_settings[ $key ]['url'] ?? '',
			'insideRepeater' => $inside_repeater,
			'repeaterKey'    => $repeater_key,
		];

		if ( ! $inside_repeater ) {
			$existing                   = $existing_settings[ $key ] ?? null;
			$result['settings'][ $key ] = $existing ?? [
				'url'  => '',
				'alt'  => '',
				'id'   => null,
				'size' => null,
			];
		}

		// Defensive declaration-existence write: only emit the inline-style
		// token form when the declaration is missing entirely. The tokenizer
		// pass handles updating an existing declaration's value.
		if ( ! self::style_contains_custom_property( $element, $candidate['existingCustomProperty'] ) ) {
			$token_value               = self::build_inline_style_token_value( $key, $inside_repeater );
			$hash                      = spl_object_hash( $element );
			$element_map[ $hash ]      = $element;
			$pending_styles[ $hash ][] = [
				'customProperty' => $candidate['existingCustomProperty'],
				'tokenValue'     => $token_value,
			];
		}
	}

	// ─── Inline-style tokenizer ────────────────────────────────────────

	/**
	 * Walk the DOM, tokenize every `--settings-KEY: url(<concrete>)`
	 * declaration, and return the recorded URLs. Mirrors the JS
	 * implementation in `inline-style-tokenizer.js`.
	 *
	 * Per-item URLs are keyed by `${repeaterKey}|${itemIndex}|${fieldKey}`
	 * — the same string-key shape the JS runtime uses — so per-runtime
	 * tests can compare keys directly. Items detach during processNode /
	 * processRepeater, so element-keyed maps would not survive the
	 * parser handoff.
	 *
	 * @param \DOMElement $body              Template DOM root (mutated in place).
	 * @param array       $existing_settings Top-level settings, used to
	 *   preserve `alt`/`id`/`size` when the URL changes.
	 * @return array{
	 *     topLevelSettings: array,
	 *     perItemUrls: array<string, array{url:string}>,
	 *     fieldsRegistered: array<int, array{key:string, insideRepeater:bool, repeaterKey:?string, itemIndex:int}>,
	 *     changed: bool,
	 * }
	 */
	public static function tokenize_inline_styles( \DOMElement $body, array $existing_settings = [] ): array {
		$result = [
			'topLevelSettings' => [],
			'perItemUrls'      => [],
			'fieldsRegistered' => [],
			'changed'          => false,
		];

		$xpath    = new \DOMXPath( $body->ownerDocument );
		$elements = $xpath->query( './/*[@style]', $body );
		if ( false === $elements ) {
			return $result;
		}

		foreach ( $elements as $el ) {
			if ( ! $el instanceof \DOMElement ) {
				continue;
			}
			$style_attr = $el->getAttribute( 'style' );
			if ( '' === $style_attr || false === strpos( $style_attr, '--settings-' ) ) {
				continue;
			}

			$ctx              = self::find_repeater_context( $el );
			$inside_repeater  = null !== $ctx;
			$repeater_key     = $inside_repeater ? $ctx['repeaterKey'] : null;
			$item_index       = $inside_repeater ? $ctx['itemIndex'] : -1;

			$rewritten = self::rewrite_style_attr(
				$style_attr,
				function ( string $key, string $url ) use ( &$result, $inside_repeater, $repeater_key, $item_index, $existing_settings ): string {
					if ( $inside_repeater && $item_index >= 0 ) {
						$per_item_key                          = $repeater_key . '|' . $item_index . '|' . $key;
						$result['perItemUrls'][ $per_item_key ] = [ 'url' => $url ];
					} else {
						$existing                                = $existing_settings[ $key ] ?? null;
						$result['topLevelSettings'][ $key ]      = [
							'url'  => $url,
							'alt'  => $existing['alt'] ?? '',
							'id'   => array_key_exists( 'id', $existing ?? [] ) ? $existing['id'] : null,
							'size' => array_key_exists( 'size', $existing ?? [] ) ? $existing['size'] : null,
						];
					}
					$result['fieldsRegistered'][] = [
						'key'            => $key,
						'insideRepeater' => $inside_repeater,
						'repeaterKey'    => $repeater_key,
						'itemIndex'      => $item_index,
					];
					return self::build_inline_style_token_value( $key, $inside_repeater );
				}
			);

			if ( $rewritten['changed'] ) {
				$el->setAttribute( 'style', $rewritten['style'] );
				$result['changed'] = true;
			}
		}

		return $result;
	}

	/**
	 * Rewrite `--settings-KEY: url(<concrete>)` declarations within a
	 * style attribute by calling $on_match($key, $url) and substituting
	 * the returned token value. Already-tokenized values pass through.
	 *
	 * @param string   $style_attr The raw style-attribute string.
	 * @param callable $on_match   Function(string $key, string $url): string.
	 * @return array{style:string, changed:bool}
	 */
	private static function rewrite_style_attr( string $style_attr, callable $on_match ): array {
		$changed   = false;
		$new_style = preg_replace_callback(
			'/(--settings-[a-zA-Z0-9_-]+)\s*:\s*url\(\s*([^)]*?)\s*\)/',
			function ( $m ) use ( $on_match, &$changed ) {
				$custom_property = $m[1];
				$raw_url         = trim( $m[2] );
				$stripped        = self::strip_quotes( $raw_url );
				if ( '' !== $stripped && 0 === strncmp( $stripped, '{{{', 3 ) && '}}}' === substr( $stripped, -3 ) ) {
					return $m[0];
				}
				$key = self::custom_property_to_key( $custom_property );
				if ( null === $key ) {
					return $m[0];
				}
				$new_value = $on_match( $key, $stripped );
				if ( ! is_string( $new_value ) ) {
					return $m[0];
				}
				$changed = true;
				return $custom_property . ': ' . $new_value;
			},
			$style_attr
		);

		return [
			'style'   => null === $new_style ? $style_attr : $new_style,
			'changed' => $changed,
		];
	}

	private static function strip_quotes( string $str ): string {
		$len = strlen( $str );
		if ( $len >= 2 ) {
			$first = $str[0];
			$last  = $str[ $len - 1 ];
			if ( ( '"' === $first || "'" === $first ) && $first === $last ) {
				return substr( $str, 1, -1 );
			}
		}
		return $str;
	}

	/**
	 * Walk up to the nearest [data-repeater] ancestor, tracking the most
	 * recent [data-repeater-item] along the way. Returns null when the
	 * element is not inside a repeater.
	 *
	 * @return array{repeaterKey:string, itemIndex:int}|null
	 */
	private static function find_repeater_context( \DOMElement $el ): ?array {
		$item    = null;
		$current = $el;
		while ( $current instanceof \DOMElement ) {
			if ( null === $item && $current->hasAttribute( 'data-repeater-item' ) ) {
				$item = $current;
			}
			if ( $current->hasAttribute( 'data-repeater' ) ) {
				$repeater_key = $current->getAttribute( 'data-repeater' );
				if ( '' === $repeater_key ) {
					return null;
				}
				$item_index = -1;
				if ( null !== $item ) {
					$idx = 0;
					foreach ( $current->childNodes as $child ) {
						if ( ! $child instanceof \DOMElement ) {
							continue;
						}
						if ( ! $child->hasAttribute( 'data-repeater-item' ) ) {
							continue;
						}
						if ( $child === $item ) {
							$item_index = $idx;
							break;
						}
						$idx++;
					}
				}
				return [
					'repeaterKey' => $repeater_key,
					'itemIndex'   => $item_index,
				];
			}
			$parent  = $current->parentNode;
			$current = $parent instanceof \DOMElement ? $parent : null;
		}
		return null;
	}

	// ─── Small helpers ─────────────────────────────────────────────────

	private static function nested_media( ?string $parent, ?string $child ): ?string {
		if ( null === $parent || '' === $parent ) {
			return $child;
		}
		if ( null === $child || '' === $child ) {
			return $parent;
		}
		return $parent . ' and ' . $child;
	}
}
