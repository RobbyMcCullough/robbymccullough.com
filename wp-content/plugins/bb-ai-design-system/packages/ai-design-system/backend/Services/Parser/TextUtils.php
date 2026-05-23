<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * Shared text utilities for section parsers.
 */
class TextUtils {

	/**
	 * Strip common leading whitespace from a multiline string.
	 *
	 * Finds the minimum indentation across all non-empty lines
	 * and removes that many leading whitespace characters from each line.
	 * The result is also trimmed of leading/trailing blank lines.
	 *
	 * @param string $str Input string.
	 * @return string Dedented and trimmed string.
	 */
	public static function dedent( string $str ): string {
		if ( '' === $str ) {
			return '';
		}

		$lines     = explode( "\n", $str );
		$non_empty = array_filter( $lines, function ( $line ) {
			return '' !== trim( $line );
		} );

		if ( empty( $non_empty ) ) {
			return '';
		}

		// Find minimum indentation across non-empty lines.
		$indent = PHP_INT_MAX;
		foreach ( $non_empty as $line ) {
			preg_match( '/^(\s*)/', $line, $matches );
			$len = strlen( $matches[1] );
			if ( $len < $indent ) {
				$indent = $len;
			}
		}

		if ( 0 === $indent ) {
			return trim( $str );
		}

		$pattern = '/^\s{' . $indent . '}/';
		$result  = array_map( function ( $line ) use ( $pattern ) {
			return preg_replace( $pattern, '', $line );
		}, $lines );

		return trim( implode( "\n", $result ) );
	}
}
