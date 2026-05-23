<?php

namespace FL\DesignSystem\DesignKit;

/**
 * Canonical ordering rule for design kit page files.
 *
 * Both the analyze/import path (KitParser) and the preview path (KitPreviewer)
 * surface a list of pages, and both should present them in the same order:
 * `index.html` first, `style-guide.html` last, everything else alphabetical.
 */
class PageOrder {

	/**
	 * Sort kit page file paths into canonical display order.
	 *
	 * @param string[] $files Absolute or relative page file paths.
	 * @return string[] Same paths, reordered.
	 */
	public static function sort( array $files ): array {
		$files = array_values( $files );

		usort( $files, static function ( string $a, string $b ): int {
			$base_a = basename( $a );
			$base_b = basename( $b );
			$rank   = self::rank( $base_a ) <=> self::rank( $base_b );
			return 0 !== $rank ? $rank : strcmp( $base_a, $base_b );
		} );

		return $files;
	}

	/**
	 * Bucket rank for a page basename. Lower sorts first.
	 *
	 * @param string $basename File basename (e.g. "index.html").
	 * @return int
	 */
	private static function rank( string $basename ): int {
		if ( 'index.html' === $basename ) {
			return 0;
		}
		if ( 'style-guide.html' === $basename ) {
			return 2;
		}
		return 1;
	}
}
