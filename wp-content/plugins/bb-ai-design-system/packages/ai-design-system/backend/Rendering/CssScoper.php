<?php

namespace FL\DesignSystem\Rendering;

use FL\DesignSystem\Settings\SettingsResolver;

/**
 * CSS scoping utility for Design System blocks.
 *
 * Prefixes CSS selectors with a scope selector so each block instance
 * gets isolated styles. Root-element selectors use compound joining
 * (no space); other selectors use descendant joining (space).
 */
class CssScoper {

	/**
	 * Extract static class names from the root element of a Mustache template.
	 *
	 * Parses the first opening HTML tag's class attribute and returns an array
	 * of static class names, ignoring Mustache expressions ({{...}}).
	 *
	 * @param  string $template Mustache template string.
	 * @return array  Array of static class name strings.
	 */
	public static function extract_root_classes( string $template ): array {
		if ( empty( $template ) ) {
			return [];
		}

		// Trim leading whitespace left by collapsed Mustache conditional sections.
		$template = ltrim( $template );

		// Match the class attribute on the first opening HTML tag.
		if ( ! preg_match( '/^<\w+[^>]*\bclass="([^"]*)"/', $template, $match ) ) {
			return [];
		}

		$class_value = $match[1];

		// Remove Mustache block sections ({{#...}}content{{/...}}) and their content.
		$class_value = preg_replace( '/\{\{[#^].*?\}\}.*?\{\{\/.*?\}\}/', '', $class_value );

		// Remove remaining Mustache variable expressions ({{...}}).
		$class_value = preg_replace( '/\{\{.*?\}\}/', '', $class_value );

		// Split on whitespace and filter empty strings.
		$classes = preg_split( '/\s+/', trim( $class_value ), -1, PREG_SPLIT_NO_EMPTY );

		return $classes;
	}

	/**
	 * Extract all possible root classes from a template, considering all
	 * variant values for Mustache fields referenced in the root class attribute.
	 *
	 * @param  string $template Raw Mustache template string.
	 * @param  array  $form     Form configuration tree for option lookup.
	 * @return array  Array of all possible root class names.
	 */
	public static function extract_all_root_classes( string $template, array $form = [] ): array {
		if ( empty( $template ) || empty( $form ) ) {
			return self::extract_root_classes( $template );
		}

		// Extract the raw class attribute from the root element in the unresolved template.
		$raw_class_attr = self::extract_raw_class_attribute( $template );
		if ( null === $raw_class_attr ) {
			return [];
		}

		// Find all settings.FIELDNAME variable references in the class attribute.
		$variable_fields = [];
		if ( preg_match_all( '/\{\{(?:#|\/|\^)?settings\.(\w+)\}\}/', $raw_class_attr, $matches ) ) {
			$variable_fields = array_unique( $matches[1] );
		}

		if ( empty( $variable_fields ) ) {
			return self::extract_root_classes( $template );
		}

		$defaults  = SettingsResolver::resolve_defaults( $form );
		$mustache  = new MustacheEngine();
		$all_classes = [];

		// Start with defaults-resolved root classes.
		$resolved = $mustache->render( $template, [ 'settings' => $defaults ] );
		$all_classes = self::extract_root_classes( $resolved );

		// For each field, resolve with each option value and collect root classes.
		foreach ( $variable_fields as $field_key ) {
			$options = SettingsResolver::find_field_options( $form, $field_key );

			if ( ! empty( $options ) ) {
				foreach ( array_keys( $options ) as $option_value ) {
					$modified = $defaults;
					$modified[ $field_key ] = $option_value;
					$variant_resolved = $mustache->render( $template, [ 'settings' => $modified ] );
					$variant_classes = self::extract_root_classes( $variant_resolved );
					$all_classes = array_merge( $all_classes, $variant_classes );
				}
			}

			// For conditional sections, also resolve with falsy value.
			if ( preg_match( '/\{\{[#^]settings\.' . preg_quote( $field_key, '/' ) . '\}\}/', $raw_class_attr ) ) {
				$modified = $defaults;
				$modified[ $field_key ] = '';
				$variant_resolved = $mustache->render( $template, [ 'settings' => $modified ] );
				$variant_classes = self::extract_root_classes( $variant_resolved );
				$all_classes = array_merge( $all_classes, $variant_classes );

				// Also resolve with truthy value for {{#settings.X}} conditionals.
				$modified[ $field_key ] = '1';
				$variant_resolved = $mustache->render( $template, [ 'settings' => $modified ] );
				$variant_classes = self::extract_root_classes( $variant_resolved );
				$all_classes = array_merge( $all_classes, $variant_classes );
			}
		}

		return array_values( array_unique( $all_classes ) );
	}

	/**
	 * Extract the raw class attribute value from the root element of a Mustache template.
	 *
	 * Handles templates that start with Mustache conditionals by searching
	 * for the first HTML tag with a class attribute.
	 *
	 * @param  string $template Raw Mustache template string.
	 * @return string|null The raw class attribute value, or null if not found.
	 */
	private static function extract_raw_class_attribute( string $template ): ?string {
		// Skip leading Mustache conditionals to find the first HTML tag.
		// Match class="..." on the first HTML tag found.
		if ( preg_match( '/<\w+[^>]*\bclass="([^"]*)"/', $template, $match ) ) {
			return $match[1];
		}
		return null;
	}

	/**
	 * Scope definition CSS to .fl-ds-block with form-based variant root class detection.
	 *
	 * This is the canonical way to scope definition CSS. Both the Beaver Builder
	 * and Block Editor renderers should use this method to ensure identical output.
	 *
	 * @param  string $css      Raw definition CSS.
	 * @param  string $template Mustache template string.
	 * @param  array  $form     Form configuration tree.
	 * @return string Scoped CSS.
	 */
	public static function scope_definition_css( string $css, string $template, array $form ): string {
		return self::scope_css( $css, '.fl-ds-block', $template, [], $form );
	}

	/**
	 * Scope CSS by prefixing selectors with a node-specific scope selector.
	 *
	 * Root-class selectors get compound joining (no space): .scope.bb-hero
	 * Non-root selectors get descendant joining (space): .scope .bb-hero-inner
	 * @keyframes rules pass through unchanged.
	 * @media/@supports rules have their inner selectors scoped.
	 *
	 * Accepts a template string and optional template data. When data is provided,
	 * the template is rendered through Mustache before extracting root classes,
	 * ensuring classes with mustache expressions are properly resolved.
	 *
	 * When a form config is provided, all possible variant values for Mustache
	 * fields in the root class attribute are resolved to detect root classes
	 * for non-default variants.
	 *
	 * @param  string $css            Raw CSS string.
	 * @param  string $scope_selector Scope selector (e.g. '.fl-node-abc123').
	 * @param  string $template       Mustache template for root class extraction.
	 * @param  array  $template_data  Optional data to render template with before extraction.
	 * @param  array  $form           Optional form config for variant root class detection.
	 * @return string Scoped CSS.
	 */
	public static function scope_css( string $css, string $scope_selector, string $template, array $template_data = [], array $form = [] ): string {
		if ( empty( trim( $css ) ) ) {
			return '';
		}

		// When form config is provided, use variant-aware root class extraction.
		if ( ! empty( $form ) ) {
			$root_classes = self::extract_all_root_classes( $template, $form );
		} else {
			$resolved = $template;
			if ( ! empty( $template_data ) ) {
				$mustache = new MustacheEngine();
				$resolved = $mustache->render( $template, $template_data );
			}
			$root_classes = self::extract_root_classes( $resolved );
		}

		// Strip CSS comments before processing to prevent them from
		// being treated as selectors or breaking block splitting.
		$css = preg_replace( '/\/\*[\s\S]*?\*\//', '', $css );
		if ( empty( trim( $css ) ) ) {
			return '';
		}

		$blocks = self::split_top_level_blocks( $css );
		$output = [];

		foreach ( $blocks as $block ) {
			// @keyframes: pass through unchanged.
			if ( preg_match( '/^@keyframes\s/i', $block ) ) {
				$output[] = $block;
				continue;
			}

			// @media, @supports: scope inner rules, keep at-rule at top level.
			if ( preg_match( '/^@(media|supports)\s/i', $block ) ) {
				$brace_pos    = strpos( $block, '{' );
				$prelude      = substr( $block, 0, $brace_pos );
				$inner        = substr( $block, $brace_pos + 1, strrpos( $block, '}' ) - $brace_pos - 1 );
				$scoped_inner = self::scope_css_inner( $inner, $scope_selector, $root_classes );
				$output[]     = $prelude . '{ ' . $scoped_inner . ' }';
				continue;
			}

			// Regular rule: prefix selectors.
			$output[] = self::scope_rule( $block, $scope_selector, $root_classes );
		}

		return implode( "\n", $output );
	}

	/**
	 * Scope inner CSS content with already-resolved root classes.
	 *
	 * Used for recursing into @media/@supports blocks where root classes
	 * have already been extracted from the template.
	 *
	 * @param  string $css            Raw CSS string.
	 * @param  string $scope_selector Scope selector.
	 * @param  array  $root_classes   Already-resolved root classes.
	 * @return string Scoped CSS.
	 */
	private static function scope_css_inner( string $css, string $scope_selector, array $root_classes ): string {
		if ( empty( trim( $css ) ) ) {
			return '';
		}

		$blocks = self::split_top_level_blocks( $css );
		$output = [];

		foreach ( $blocks as $block ) {
			if ( preg_match( '/^@keyframes\s/i', $block ) ) {
				$output[] = $block;
				continue;
			}

			if ( preg_match( '/^@(media|supports)\s/i', $block ) ) {
				$brace_pos    = strpos( $block, '{' );
				$prelude      = substr( $block, 0, $brace_pos );
				$inner        = substr( $block, $brace_pos + 1, strrpos( $block, '}' ) - $brace_pos - 1 );
				$scoped_inner = self::scope_css_inner( $inner, $scope_selector, $root_classes );
				$output[]     = $prelude . '{ ' . $scoped_inner . ' }';
				continue;
			}

			$output[] = self::scope_rule( $block, $scope_selector, $root_classes );
		}

		return implode( "\n", $output );
	}

	/**
	 * Split a CSS string into top-level blocks using brace counting.
	 *
	 * @param  string $css Raw CSS string.
	 * @return array  Array of top-level CSS block strings.
	 */
	private static function split_top_level_blocks( string $css ): array {
		$blocks = [];
		$depth  = 0;
		$start  = 0;
		$len    = strlen( $css );

		for ( $i = 0; $i < $len; $i++ ) {
			if ( $css[ $i ] === '{' ) {
				$depth++;
			} elseif ( $css[ $i ] === '}' ) {
				--$depth;
				if ( $depth === 0 ) {
					$block = trim( substr( $css, $start, $i - $start + 1 ) );
					if ( $block !== '' ) {
						$blocks[] = $block;
					}
					$start = $i + 1;
				}
			}
		}

		$trailing = trim( substr( $css, $start ) );
		if ( $trailing !== '' ) {
			$blocks[] = $trailing;
		}

		return $blocks;
	}

	/**
	 * Find whether a selector starts with one of the root classes,
	 * with or without an HTML element prefix.
	 *
	 * Returns the length of the matched portion (element name + dot + class),
	 * or 0 if no root class match is found.
	 *
	 * Examples with root_classes = ['footer']:
	 *   '.footer'        → 7   (.footer)
	 *   'footer.footer'  → 13  (footer.footer)
	 *   '.footer:hover'  → 7
	 *   '.unrelated'     → 0
	 *
	 * @param  string $selector     CSS selector string.
	 * @param  array  $root_classes Root element class names.
	 * @return int    Length of matched portion, or 0 for no match.
	 */
	private static function find_root_class_match( string $selector, array $root_classes ): int {
		$boundary = '(?=[^a-zA-Z0-9_-]|$)';

		foreach ( $root_classes as $class ) {
			$escaped = preg_quote( $class, '/' );

			// Class-only: .rootClass
			if ( preg_match( '/^\.' . $escaped . $boundary . '/', $selector, $match ) ) {
				return strlen( $match[0] );
			}

			// Element-prefixed: element.rootClass
			if ( preg_match( '/^[a-zA-Z][a-zA-Z0-9]*\.' . $escaped . $boundary . '/', $selector, $match ) ) {
				return strlen( $match[0] );
			}
		}

		return 0;
	}

	/**
	 * Check if a selector starts with one of the root classes.
	 *
	 * @param  string $selector     CSS selector string.
	 * @param  array  $root_classes Root element class names.
	 * @return bool
	 */
	private static function starts_with_root_class( string $selector, array $root_classes ): bool {
		return self::find_root_class_match( $selector, $root_classes ) > 0;
	}

	/**
	 * Prefix a single selector with the scope selector.
	 *
	 * Root-class selectors get compound joining (no space).
	 * Non-root selectors get descendant joining (space).
	 * For element-prefixed root selectors (e.g. footer.footer), the scope
	 * class is inserted after the element+class compound.
	 *
	 * @param  string $selector       CSS selector string.
	 * @param  string $scope_selector Scope selector.
	 * @param  array  $root_classes   Root element class names.
	 * @return string Prefixed selector.
	 */
	private static function prefix_selector( string $selector, string $scope_selector, array $root_classes ): string {
		$trimmed = trim( $selector );
		if ( $trimmed === '' ) {
			return $trimmed;
		}

		$match_length = self::find_root_class_match( $trimmed, $root_classes );
		if ( $match_length > 0 ) {
			if ( $trimmed[0] === '.' ) {
				return $scope_selector . $trimmed;
			}
			// Element-prefixed: insert scope after the element+class compound.
			return substr( $trimmed, 0, $match_length ) . $scope_selector . substr( $trimmed, $match_length );
		}

		return $scope_selector . ' ' . $trimmed;
	}

	/**
	 * Scope a single CSS rule block by prefixing its selectors.
	 *
	 * @param  string $block          CSS rule block string.
	 * @param  string $scope_selector Scope selector.
	 * @param  array  $root_classes   Root element class names.
	 * @return string Scoped CSS rule block.
	 */
	private static function scope_rule( string $block, string $scope_selector, array $root_classes ): string {
		$brace_pos = strpos( $block, '{' );
		if ( $brace_pos === false ) {
			return $block;
		}

		$selector_part = substr( $block, 0, $brace_pos );
		$rest          = substr( $block, $brace_pos );

		$selectors = explode( ',', $selector_part );
		$prefixed  = array_map( function ( $s ) use ( $scope_selector, $root_classes ) {
			return self::prefix_selector( $s, $scope_selector, $root_classes );
		}, $selectors );

		return implode( ', ', $prefixed ) . ' ' . $rest;
	}
}
