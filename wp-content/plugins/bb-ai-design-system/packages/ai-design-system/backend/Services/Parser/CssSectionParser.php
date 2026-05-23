<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * CSS Section Parser
 *
 * Parses CSS strings with comment markers into named sections.
 * Preferred markers: /* @tokens *​/, /* @reset *​/, /* @base *​/, /* @page *​/, /* @section {Label} *​/
 * Legacy markers (backward compat): /* Tokens *​/, /* Reset *​/, /* Base *​/, /* Section: {Label} *​/
 */
class CssSectionParser {

	/**
	 * Regex pattern for CSS section markers.
	 *
	 * Handles both `/* @tokens * /` and legacy `/* Tokens * /` formats.
	 */
	private const MARKER_RE = '/\/\*[\s*=\-#]*@?(tokens|reset|base|page|section:?\s*(.+?))[\s*=\-#]*\*\//i';

	/**
	 * Parse a comment-marked CSS string into sections.
	 *
	 * @param string $css Raw CSS with comment markers.
	 * @return array{tokens: string, reset: string, base: string, page: string, sections: array<string, string>}
	 */
	public static function parse( string $css ): array {
		$result = [
			'tokens'   => '',
			'reset'    => '',
			'base'     => '',
			'page'     => '',
			'sections' => [],
		];

		if ( '' === $css ) {
			return $result;
		}

		// Find all markers and their positions.
		$markers = [];
		if ( preg_match_all( self::MARKER_RE, $css, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$raw           = $match[1][0];
				$section_label = isset( $match[2] ) && '' !== $match[2][0] ? trim( $match[2][0] ) : '';
				$match_start   = $match[0][1];
				$match_length  = strlen( $match[0][0] );

				if ( '' !== $section_label ) {
					$key = [ 'type' => 'section', 'label' => $section_label ];
				} else {
					$key = [ 'type' => strtolower( $raw ) ];
				}

				$markers[] = [
					'key'          => $key,
					'match_start'  => $match_start,
					'content_start' => $match_start + $match_length,
				];
			}
		}

		// No markers -- return everything as base.
		if ( empty( $markers ) ) {
			if ( strlen( $css ) > 200 ) {
				// Non-trivial CSS without markers -- likely missing required format.
				trigger_error(
					sprintf(
						'[css-section-parser] No comment markers found in CSS (%d chars). '
						. 'Expected /* @tokens */, /* @section Label */, etc.',
						strlen( $css )
					),
					E_USER_WARNING
				);
			}
			$result['base'] = TextUtils::dedent( $css );
			return $result;
		}

		// Content before the first marker is treated as base.
		$pre_marker = TextUtils::dedent( substr( $css, 0, $markers[0]['match_start'] ) );
		if ( '' !== $pre_marker ) {
			$result['base'] = $pre_marker;
		}

		// Extract content between each marker and the next (or end of string).
		$count = count( $markers );
		for ( $i = 0; $i < $count; $i++ ) {
			$start = $markers[ $i ]['content_start'];

			if ( $i + 1 < $count ) {
				// Find the start of the next marker's comment opening.
				$end = strrpos( substr( $css, 0, $markers[ $i + 1 ]['content_start'] ), '/*' );
			} else {
				$end = strlen( $css );
			}

			$content = TextUtils::dedent( substr( $css, $start, $end - $start ) );
			$key     = $markers[ $i ]['key'];

			if ( 'section' === $key['type'] ) {
				if ( isset( $result['sections'][ $key['label'] ] ) && '' !== $result['sections'][ $key['label'] ] ) {
					$result['sections'][ $key['label'] ] .= "\n\n" . $content;
				} else {
					$result['sections'][ $key['label'] ] = $content;
				}
			} elseif ( '' !== $result[ $key['type'] ] ) {
				$result[ $key['type'] ] .= "\n\n" . $content;
			} else {
				$result[ $key['type'] ] = $content;
			}
		}

		return $result;
	}
}
