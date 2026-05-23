<?php

namespace FL\DesignSystem\BlockEditor;

use FL\DesignSystem\Rendering\CssScoper;

/**
 * Frontend rendering of flDsCss on non-DS WordPress blocks.
 *
 * Detects the custom attribute, scopes CSS to a generated class on the
 * block's root element. Extracts root element info from the rendered HTML
 * so CssScoper can use compound joining for root-targeting selectors.
 */
class WpBlockCssRenderer {

	/** @var int Counter for generating unique scope classes. */
	private static $counter = 0;

	/**
	 * Register filters for attribute registration and frontend rendering.
	 */
	public static function init() {
		add_filter( 'register_block_type_args', [ __CLASS__, 'register_css_attribute' ], 10, 2 );
		add_filter( 'render_block', [ __CLASS__, 'render_instance_css' ], 10, 2 );
	}

	/**
	 * Register the flDsCss attribute on non-DS blocks via PHP.
	 *
	 * @param  array  $args       Block type arguments.
	 * @param  string $block_type Block type name.
	 * @return array Modified block type arguments.
	 */
	public static function register_css_attribute( $args, $block_type ) {
		if ( ! str_starts_with( $block_type, 'fl-ds/' ) ) {
			$args['attributes']['flDsCss'] = [
				'type'    => 'string',
				'default' => '',
			];
		}
		return $args;
	}

	/**
	 * Render scoped instance CSS for non-DS blocks on the frontend.
	 *
	 * Short-circuits when the block has no CSS — zero overhead on
	 * blocks without instance CSS.
	 *
	 * @param  string $block_content Rendered block HTML.
	 * @param  array  $parsed_block  Parsed block array.
	 * @return string Modified block HTML with scoped CSS prepended.
	 */
	public static function render_instance_css( $block_content, $parsed_block ) {
		$block_name = $parsed_block['blockName'] ?? '';
		if ( empty( $block_name ) || str_starts_with( $block_name, 'fl-ds/' ) ) {
			return $block_content;
		}

		$css = $parsed_block['attrs']['flDsCss'] ?? '';
		if ( empty( trim( $css ) ) ) {
			return $block_content;
		}

		self::$counter++;
		$scope_class    = 'fl-ds-wp-' . self::$counter;
		$scope_selector = '.' . $scope_class;

		// Extract root element tag and classes from the block HTML so
		// CssScoper can do compound joining for root-targeting selectors.
		$root_info    = self::extract_root_info( $block_content );
		$root_tag     = $root_info['tag'];
		$root_classes = $root_info['classes'];

		// Build a synthetic template for CssScoper's root class extraction.
		// Include the scope class so that after root-tag selectors are
		// replaced with the scope class, CssScoper recognizes them as
		// root selectors and uses compound joining (not descendant).
		// e.g. '<p class="fl-ds-wp-1 wp-block-paragraph">'
		$synthetic_template = '';
		if ( $root_tag ) {
			$all_classes        = array_merge( [ $scope_class ], $root_classes );
			$synthetic_template = '<' . $root_tag . ' class="' . implode( ' ', $all_classes ) . '">';
		}

		// Pre-process: replace bare root-tag selectors with the scope class.
		// e.g. "p { color: red; }" → ".fl-ds-wp-1 { color: red; }"
		// CssScoper only recognizes class-based root selectors, so tag
		// selectors targeting the root element need this transformation.
		if ( $root_tag ) {
			$css = self::replace_root_tag_selectors( $css, $root_tag, $scope_selector );
		}

		$scoped_css = CssScoper::scope_css( $css, $scope_selector, $synthetic_template );

		// Add scope class to the root element.
		$processor = new \WP_HTML_Tag_Processor( $block_content );
		if ( $processor->next_tag() ) {
			$processor->add_class( $scope_class );
			$block_content = $processor->get_updated_html();
		}

		return '<style>' . $scoped_css . '</style>' . "\n" . $block_content;
	}

	/**
	 * Extract the root element's tag name and CSS classes from block HTML.
	 *
	 * @param  string $html Rendered block HTML.
	 * @return array  Array with 'tag' (string|null) and 'classes' (string[]).
	 */
	private static function extract_root_info( string $html ): array {
		$html = ltrim( $html );

		if ( ! preg_match( '/^<(\w+)/', $html, $tag_match ) ) {
			return [
				'tag'     => null,
				'classes' => [],
			];
		}

		$tag     = strtolower( $tag_match[1] );
		$classes = [];

		if ( preg_match( '/^<\w+[^>]*\bclass="([^"]*)"/', $html, $class_match ) ) {
			$classes = preg_split( '/\s+/', trim( $class_match[1] ), -1, PREG_SPLIT_NO_EMPTY );
		}

		return [
			'tag'     => $tag,
			'classes' => $classes,
		];
	}

	/**
	 * Replace bare root-tag selectors with the scope class selector.
	 *
	 * Handles the case where the agent writes tag selectors targeting the
	 * root element (e.g. "p { color: red; }"). Replaces the tag with the
	 * scope class so CssScoper produces a compound selector on the root
	 * element rather than a descendant selector that won't match.
	 *
	 * Only replaces when the tag is at the start of a selector (start of
	 * line or after a comma). Does not replace tags in descendant chains
	 * (e.g. "div p") since those target child elements.
	 *
	 * @param  string $css            Raw CSS string.
	 * @param  string $root_tag       Root element tag name (lowercase).
	 * @param  string $scope_selector Scope selector (e.g. '.fl-ds-wp-1').
	 * @return string CSS with root-tag selectors replaced.
	 */
	private static function replace_root_tag_selectors( string $css, string $root_tag, string $scope_selector ): string {
		$escaped = preg_quote( $root_tag, '/' );

		// Match the root tag at the start of a selector:
		// - (?:^|(?<=,)) — at the start of a line (multiline) or after a comma
		// - \s*\K — consume optional whitespace, then reset match start
		// - $tag\b — the root tag followed by a word boundary (prevents "p" matching "pre")
		// - (?=[\s{.:\[#,]) — followed by space, brace, class, pseudo, attr, id, or comma
		return preg_replace(
			'/(?:^|(?<=,))\s*\K' . $escaped . '\b(?=[\s{.:\[#,])/m',
			$scope_selector,
			$css
		);
	}
}
