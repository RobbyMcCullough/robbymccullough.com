<?php

namespace FL\DesignSystem\DesignKit;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Font\FontExtractor;
use FL\DesignSystem\Page\PageImporter;
use FL\DesignSystem\Services\Parser\CssSectionParser;

/**
 * Parses an extracted Design Kit directory and returns a structured analysis.
 *
 * Filesystem-first: pages, globals, and DS metadata are derived from the
 * directory structure and HTML content. kit.json provides only identity
 * fields (uuid, name, description).
 */
class KitParser {

	/**
	 * Analyze an extracted Design Kit directory.
	 *
	 * @param string $dir Absolute path to the extracted kit root.
	 * @return array KitAnalysis structure.
	 */
	public static function analyze( string $dir ): array {
		$dir    = \trailingslashit( $dir );
		$errors = [];

		// Read identity from kit.json (if present).
		$identity = self::read_identity( $dir );

		// Scan filesystem for pages.
		$page_files = self::scan_pages( $dir, $errors );

		// Analyze each page file.
		$pages = self::analyze_pages( $dir, $page_files );

		// Read design system data from styles.css.
		$ds_info         = self::analyze_design_system( $dir, $identity['uuid'] );
		$ds_info['name'] = $identity['name'];

		// Detect globals from filesystem.
		$globals = self::detect_globals( $dir );

		// Detect environment capabilities.
		$environment = self::detect_environment();

		return [
			'kit'          => [
				'uuid'        => $identity['uuid'],
				'name'        => $identity['name'],
				'description' => $identity['description'],
			],
			'designSystem' => $ds_info,
			'globals'      => $globals,
			'pages'        => $pages,
			'environment'  => $environment,
			'errors'       => $errors,
		];
	}

	/**
	 * Read identity fields from kit.json.
	 *
	 * Only extracts uuid, name, and description. All other metadata
	 * is derived from the filesystem.
	 *
	 * @param string $dir Kit root directory (with trailing slash).
	 * @return array { uuid: string, name: string, description: string }
	 */
	private static function read_identity( string $dir ): array {
		$defaults = [
			'uuid'        => '',
			'name'        => '',
			'description' => '',
		];

		$kit_json_path = $dir . 'kit.json';

		if ( ! file_exists( $kit_json_path ) ) {
			return $defaults;
		}

		$contents = file_get_contents( $kit_json_path );
		$data     = json_decode( $contents, true );

		if ( ! is_array( $data ) ) {
			return $defaults;
		}

		return [
			'uuid'        => \sanitize_text_field( $data['uuid'] ?? '' ),
			'name'        => \sanitize_text_field( $data['name'] ?? '' ),
			'description' => \sanitize_textarea_field( $data['description'] ?? '' ),
		];
	}

	/**
	 * Scan the filesystem for page HTML files.
	 *
	 * Looks in pages/ directory first, falls back to root-level HTML files.
	 *
	 * @param string $dir    Kit root directory (with trailing slash).
	 * @param array  $errors Error collector (passed by reference).
	 * @return string[] Absolute paths to page HTML files.
	 */
	private static function scan_pages( string $dir, array &$errors ): array {
		$pages_dir = $dir . 'pages';

		if ( is_dir( $pages_dir ) ) {
			$files = glob( $pages_dir . '/*.html' );
		} else {
			// Fall back to root-level HTML files.
			$files = glob( $dir . '*.html' );
			// Exclude globals.
			$files = array_filter( $files, function ( $f ) {
				$basename = basename( $f );
				return ! in_array( $basename, [ 'header.html', 'footer.html' ], true );
			} );
		}

		if ( ! is_dir( $dir . 'design-system' ) ) {
			$errors[] = 'No design-system/ directory found.';
		}

		if ( empty( $files ) ) {
			$errors[] = 'No page HTML files found.';
		}

		return PageOrder::sort( $files ?: [] );
	}

	/**
	 * Analyze the design system from styles.css.
	 *
	 * @param string $dir  Extracted kit root (with trailing slash).
	 * @param string $uuid Kit UUID for DS matching.
	 * @return array Design system analysis.
	 */
	private static function analyze_design_system( string $dir, string $uuid ): array {
		$styles_path = $dir . 'design-system/styles.css';

		$result = [
			'name'        => '',
			'tokenCount'  => 0,
			'fonts'       => [],
			'match'       => null,
			'hasGuidance' => false,
			'hasBaseJs'   => false,
		];

		if ( ! file_exists( $styles_path ) ) {
			return $result;
		}

		$css      = file_get_contents( $styles_path );
		$sections = CssSectionParser::parse( $css );

		// Count tokens from the tokens CSS section.
		$tokens = PageImporter::parse_tokens_from_css( $sections['tokens'] );
		$result['tokenCount'] = count( $tokens );

		// Extract font names from tokens.
		$result['fonts'] = self::extract_fonts( $sections['tokens'] );

		// Detect art direction and base JS files.
		$art_direction_path = $dir . 'design-system/art-direction.md';
		if ( file_exists( $art_direction_path ) && '' !== trim( file_get_contents( $art_direction_path ) ) ) {
			$result['hasGuidance'] = true;
		}

		$script_path = $dir . 'design-system/script.js';
		if ( file_exists( $script_path ) && '' !== trim( file_get_contents( $script_path ) ) ) {
			$result['hasBaseJs'] = true;
		}

		// Try to match an existing DS by UUID.
		if ( '' !== $uuid ) {
			$existing = DesignSystemPostType::get_by_uuid( $uuid );
			if ( $existing ) {
				$result['match'] = [
					'uuid'    => $uuid,
					'name'    => $existing->post_title,
					'post_id' => $existing->ID,
				];
			}
		}

		return $result;
	}

	/**
	 * Extract font family names from a CSS tokens string.
	 *
	 * @param string $css Tokens CSS section.
	 * @return string[] Font family names.
	 */
	private static function extract_fonts( string $css ): array {
		return FontExtractor::extract_families( $css );
	}

	/**
	 * Analyze page files and extract metadata from HTML.
	 *
	 * Derives title from <title> tag, post type from <meta name="post-type">,
	 * and slug from filename. Also extracts section labels.
	 *
	 * @param string   $dir   Kit root directory (with trailing slash).
	 * @param string[] $files Absolute paths to page HTML files.
	 * @return array Page analysis entries.
	 */
	private static function analyze_pages( string $dir, array $files ): array {
		$result = [];

		foreach ( $files as $file_path ) {
			$slug     = pathinfo( $file_path, PATHINFO_FILENAME );
			$rel_path = self::relative_path( $dir, $file_path );

			$title    = ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );
			$postType = 'page';
			$sections = [];

			if ( file_exists( $file_path ) ) {
				$html = file_get_contents( $file_path );

				// Extract title from <title> tag.
				$parsed_title = self::extract_title( $html );
				if ( '' !== $parsed_title ) {
					$title = $parsed_title;
				}

				// Extract post type from <meta name="post-type">.
				$parsed_post_type = self::extract_meta( $html, 'post-type' );
				if ( '' !== $parsed_post_type ) {
					$postType = $parsed_post_type;
				}

				$sections = self::extract_section_labels( $html );
			}

			$result[] = [
				'slug'     => $slug,
				'title'    => $title,
				'file'     => $rel_path,
				'postType' => $postType,
				'sections' => $sections,
			];
		}

		return $result;
	}

	/**
	 * Extract the page title from an HTML <title> tag.
	 *
	 * @param string $html Page HTML content.
	 * @return string Title text, or empty string if not found.
	 */
	private static function extract_title( string $html ): string {
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * Extract a meta tag value by name.
	 *
	 * @param string $html Page HTML content.
	 * @param string $name Meta tag name attribute.
	 * @return string Content attribute value, or empty string if not found.
	 */
	private static function extract_meta( string $html, string $name ): string {
		$pattern = '/<meta[^>]*\bname=["\']' . preg_quote( $name, '/' ) . '["\'][^>]*\bcontent=["\']([^"\']+)["\'][^>]*>/i';
		if ( preg_match( $pattern, $html, $matches ) ) {
			return trim( $matches[1] );
		}

		// Also match content before name (attribute order varies).
		$pattern = '/<meta[^>]*\bcontent=["\']([^"\']+)["\'][^>]*\bname=["\']' . preg_quote( $name, '/' ) . '["\'][^>]*>/i';
		if ( preg_match( $pattern, $html, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Extract section labels from HTML using a lightweight regex scan.
	 *
	 * Looks for <section> elements with data-label attributes.
	 *
	 * @param string $html Page HTML content.
	 * @return string[] Section labels.
	 */
	private static function extract_section_labels( string $html ): array {
		$labels = [];

		if ( preg_match_all( '/<section[^>]*\bdata-label=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			$labels = $matches[1];
		}

		return $labels;
	}

	/**
	 * Detect global layout files (header/footer) from the filesystem.
	 *
	 * @param string $dir Kit root directory (with trailing slash).
	 * @return array Globals detection result.
	 */
	private static function detect_globals( string $dir ): array {
		return [
			'header' => file_exists( $dir . 'globals/header.html' ) ? 'globals/header.html' : null,
			'footer' => file_exists( $dir . 'globals/footer.html' ) ? 'globals/footer.html' : null,
		];
	}

	/**
	 * Detect the WordPress environment capabilities.
	 *
	 * @return array Environment flags.
	 */
	private static function detect_environment(): array {
		return [
			'beaverBuilder'    => class_exists( 'FLBuilderLoader' ),
			'beaverThemer'     => class_exists( 'FLThemeBuilderLoader' ),
			'blockTheme'       => function_exists( 'wp_is_block_theme' ) && \wp_is_block_theme(),
			'allowedPostTypes' => self::get_allowed_post_types(),
			'canImportGlobals' => \current_user_can( 'edit_others_posts' ),
		];
	}

	/**
	 * Get public post types the current user can create.
	 *
	 * @return array[] Array of { slug: string, label: string } entries.
	 */
	private static function get_allowed_post_types(): array {
		$post_types = \get_post_types( [ 'public' => true ], 'objects' );
		$allowed    = [];

		foreach ( $post_types as $slug => $post_type ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			if ( \current_user_can( $post_type->cap->create_posts ) ) {
				$allowed[] = [
					'slug'  => $slug,
					'label' => $post_type->labels->singular_name,
				];
			}
		}

		return $allowed;
	}

	/**
	 * Get a relative path from a base directory.
	 *
	 * @param string $base Base directory path.
	 * @param string $path Absolute file path.
	 * @return string Relative path.
	 */
	private static function relative_path( string $base, string $path ): string {
		$base = \trailingslashit( $base );
		if ( str_starts_with( $path, $base ) ) {
			return substr( $path, strlen( $base ) );
		}
		return $path;
	}
}
