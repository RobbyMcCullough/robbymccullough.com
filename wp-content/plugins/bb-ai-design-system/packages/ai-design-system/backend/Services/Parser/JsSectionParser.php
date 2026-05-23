<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * JS Section Parser
 *
 * Parses JavaScript strings with comment markers into base and named sections.
 * Markers: /* @base *​/, /* @page *​/, /* @section {Label} *​/
 *
 * Content before the first marker is treated as base (unmarked JS = base JS).
 */
class JsSectionParser {

	/**
	 * Regex pattern for JS section markers (no legacy format, no tokens/reset).
	 */
	private const MARKER_RE = '/\/\*[\s*=\-#]*@(base|page|section:?\s*(.+?))[\s*=\-#]*\*\//i';

	/**
	 * Parse a comment-marked JS string into base and sections.
	 *
	 * @param string $js Raw JS with comment markers.
	 * @return array{base: string, page: string, sections: array<string, string>}
	 */
	public static function parse( string $js ): array {
		$result = [
			'base'     => '',
			'page'     => '',
			'sections' => [],
		];

		if ( '' === $js ) {
			return $result;
		}

		// Find all markers and their positions.
		$markers = [];
		if ( preg_match_all( self::MARKER_RE, $js, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$raw           = $match[1][0];
				$section_label = isset( $match[2] ) && '' !== $match[2][0] ? trim( $match[2][0] ) : '';
				$marker_start  = $match[0][1];
				$match_length  = strlen( $match[0][0] );

				if ( '' !== $section_label ) {
					$key = [ 'type' => 'section', 'label' => $section_label ];
				} else {
					$key = [ 'type' => strtolower( $raw ) ];
				}

				$markers[] = [
					'key'           => $key,
					'marker_start'  => $marker_start,
					'content_start' => $marker_start + $match_length,
				];
			}
		}

		// No markers -- return everything as base.
		if ( empty( $markers ) ) {
			if ( strlen( $js ) > 200 ) {
				trigger_error(
					sprintf(
						'[js-section-parser] No comment markers found in JS (%d chars). '
						. 'Expected /* @base */, /* @section Label */, etc.',
						strlen( $js )
					),
					E_USER_WARNING
				);
			}
			$result['base'] = TextUtils::dedent( $js );
			return $result;
		}

		// Content before the first marker is treated as base.
		$pre_marker = TextUtils::dedent( substr( $js, 0, $markers[0]['marker_start'] ) );
		if ( '' !== $pre_marker ) {
			$result['base'] = $pre_marker;
		}

		// Extract content between each marker and the next (or end of string).
		$count = count( $markers );
		for ( $i = 0; $i < $count; $i++ ) {
			$start = $markers[ $i ]['content_start'];

			if ( $i + 1 < $count ) {
				$end = strrpos( substr( $js, 0, $markers[ $i + 1 ]['content_start'] ), '/*' );
			} else {
				$end = strlen( $js );
			}

			$content = TextUtils::dedent( substr( $js, $start, $end - $start ) );
			$key     = $markers[ $i ]['key'];

			if ( 'section' === $key['type'] ) {
				if ( isset( $result['sections'][ $key['label'] ] ) && '' !== $result['sections'][ $key['label'] ] ) {
					$result['sections'][ $key['label'] ] .= "\n\n" . $content;
				} else {
					$result['sections'][ $key['label'] ] = $content;
				}
			} elseif ( 'base' === $key['type'] ) {
				// Append to base (pre-marker content may already exist).
				$result['base'] = '' !== $result['base']
					? $result['base'] . "\n" . $content
					: $content;
			} elseif ( 'page' === $key['type'] ) {
				$result['page'] = '' !== $result['page']
					? $result['page'] . "\n" . $content
					: $content;
			}
		}

		return $result;
	}
}
