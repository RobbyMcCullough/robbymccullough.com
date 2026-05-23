<?php

namespace FL\DesignSystem\Rendering;

/**
 * Shared HTML utilities for block renderers.
 */
class BlockHtmlHelper {

	/**
	 * Inject CSS classes onto the root element of rendered HTML.
	 *
	 * Merges the given classes into the root element's class attribute so no
	 * extra wrapper div is needed. Falls back to a wrapper div if the HTML
	 * doesn't start with a valid element tag.
	 *
	 * @param string $html    Rendered block HTML.
	 * @param string $classes Space-separated CSS class names to inject.
	 * @return string HTML with classes on the root element.
	 */
	public static function inject_classes( string $html, string $classes ): string {
		$html = ltrim( $html );

		if ( ! preg_match( '/^(<[\w][\w:-]*)([\s\S]*?)(\/?>)/', $html, $match ) ) {
			return '<div class="' . esc_attr( $classes ) . '">' . $html . '</div>';
		}

		$tag_open       = $match[1];
		$existing_attrs = $match[2];
		$tag_close      = $match[3];
		$rest           = substr( $html, strlen( $match[0] ) );

		if ( preg_match( '/(\bclass="([^"]*)")/', $existing_attrs, $cm ) ) {
			$merged         = esc_attr( $classes ) . ' ' . $cm[2];
			$existing_attrs = str_replace( $cm[0], 'class="' . $merged . '"', $existing_attrs );
		} else {
			$existing_attrs = ' class="' . esc_attr( $classes ) . '"' . $existing_attrs;
		}

		return $tag_open . $existing_attrs . $tag_close . $rest;
	}
}
