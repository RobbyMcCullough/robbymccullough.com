<?php

namespace FL\DesignSystem\DesignKit;

use FL\DesignSystem\BeaverBuilder\BeaverBuilderPageAdapter;
use FL\DesignSystem\BeaverBuilder\LayoutManager;
use FL\DesignSystem\BlockEditor\BlockEditorPageAdapter;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Plugin;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\Font\FontExtractor;
use FL\DesignSystem\Page\PageImporter;
use FL\DesignSystem\Services\Parser\CssSectionParser;
use FL\DesignSystem\Services\Parser\PageParser;

/**
 * Orchestrates multi-page Design Kit import using the existing PageImporter.
 *
 * Handles design system resolution, CSS injection, page creation,
 * and adapter selection for each page in the kit.
 */
class KitImporter {

	/**
	 * Import a Design Kit into WordPress.
	 *
	 * @param string $dir    Absolute path to the extracted kit root.
	 * @param array  $config Import configuration from the client.
	 * @return array Import results.
	 */
	public static function import( string $dir, array $config ): array {
		$dir    = \trailingslashit( $dir );
		$errors = [];

		// Read the shared design system CSS.
		$styles_css = self::read_styles_css( $dir );

		// Resolve design system.
		$ds_result = self::resolve_design_system( $dir, $config['designSystem'] ?? [], $styles_css, $errors );
		$ds_uuid   = $ds_result['uuid'];
		$ds_action = $ds_result['action'];

		// Import pages.
		$page_results = self::import_pages(
			$dir,
			$config['pages'] ?? [],
			$config['editor'] ?? 'block-editor',
			$styles_css,
			$ds_uuid,
			$errors
		);

		// Import globals (header/footer).
		$ds_label = $config['designSystem']['label'] ?? '';
		$global_results = self::import_globals(
			$dir,
			$config['globals'] ?? [],
			$styles_css,
			$ds_uuid,
			$ds_label,
			$errors
		);

		return [
			'designSystem' => [
				'uuid'   => $ds_uuid ?? '',
				'action' => $ds_action,
			],
			'pages'        => $page_results,
			'globals'      => $global_results,
			'errors'       => $errors,
		];
	}

	/**
	 * Read design-system/styles.css from the kit.
	 *
	 * @param string $dir Kit root directory (with trailing slash).
	 * @return string CSS content, or empty string if not found.
	 */
	private static function read_styles_css( string $dir ): string {
		$path = $dir . 'design-system/styles.css';
		if ( file_exists( $path ) ) {
			return file_get_contents( $path );
		}
		return '';
	}

	/**
	 * Resolve the design system based on the user's chosen action.
	 *
	 * @param string $dir       Kit root directory.
	 * @param array  $ds_config Design system config from client.
	 * @param string $css       The styles.css content.
	 * @param array  $errors    Error collector (passed by reference).
	 * @return array { uuid: string|null, action: string }
	 */
	private static function resolve_design_system( string $dir, array $ds_config, string $css, array &$errors ): array {
		$action = $ds_config['action'] ?? 'skip';

		if ( 'use_existing' === $action ) {
			$uuid     = $ds_config['existingUuid'] ?? '';
			$existing = DesignSystemPostType::get_by_uuid( $uuid );

			if ( ! $existing ) {
				$errors[] = 'Existing design system not found for UUID: ' . $uuid;
				return [ 'uuid' => null, 'action' => 'skipped' ];
			}

			return [ 'uuid' => $uuid, 'action' => 'existing' ];
		}

		if ( 'create' === $action ) {
			$sections = CssSectionParser::parse( $css );
			$tokens   = PageImporter::parse_tokens_from_css( $sections['tokens'] );
			$fonts    = self::resolve_kit_fonts( $dir, $sections['tokens'] );
			$label    = $ds_config['label'] ?? 'Imported Design System';

			$kit_uuid = $ds_config['kitUuid'] ?? '';

			// Read art direction and business context from the kit.
			$guidance = '';
			$brief    = '';
			$art_direction_path = $dir . 'design-system/art-direction.md';

			if ( file_exists( $art_direction_path ) ) {
				$art_direction_content = trim( file_get_contents( $art_direction_path ) );

				if ( '' !== $art_direction_content ) {
					$parts = preg_split( '/^## Business Context\s*$/m', $art_direction_content, 2 );
					$guidance = trim( $parts[0] ?? '' );
					$brief    = trim( $parts[1] ?? '' );
				}
			}

			// Read base JavaScript from the kit.
			$base_js     = '';
			$script_path = $dir . 'design-system/script.js';

			if ( file_exists( $script_path ) ) {
				$base_js = trim( file_get_contents( $script_path ) );
			}

			$create_args = [
				'uuid'   => $kit_uuid,
				'label'  => $label,
				'tokens' => $tokens,
				'reset'  => $sections['reset'],
				'base'   => $sections['base'],
				'fonts'  => $fonts,
			];

			if ( '' !== $guidance ) {
				$create_args['guidance'] = $guidance;
			}

			if ( '' !== $brief ) {
				$create_args['brief'] = $brief;
			}

			if ( '' !== $base_js ) {
				$create_args['js'] = $base_js;
			}

			$ds_post = DesignSystemPostType::create( $create_args );

			if ( \is_wp_error( $ds_post ) ) {
				$errors[] = 'Failed to create design system: ' . $ds_post->get_error_message();
				return [ 'uuid' => null, 'action' => 'skipped' ];
			}

			$uuid = \get_post_meta( $ds_post->ID, DesignSystemPostType::META_UUID, true );
			return [ 'uuid' => $uuid, 'action' => 'created' ];
		}

		// 'skip' means don't create/update the DS, but still resolve
		// the UUID if a matching DS exists on the site.
		if ( ! empty( $ds_config['existingUuid'] ) ) {
			$existing = DesignSystemPostType::get_by_uuid( $ds_config['existingUuid'] );
			if ( $existing ) {
				return [ 'uuid' => $ds_config['existingUuid'], 'action' => 'skipped' ];
			}
		}

		return [ 'uuid' => null, 'action' => 'skipped' ];
	}

	/**
	 * Resolve the font list for a kit being imported.
	 *
	 * Merges two sources into canonical `{family, variants}[]` entries:
	 *
	 * 1. Google Fonts `<link>` tags in the kit's first page (authoritative —
	 *    they carry the variant spec the kit was authored against).
	 * 2. Font family names referenced in the CSS tokens (fallback — a kit
	 *    may declare a family in a custom property without ever linking it).
	 *
	 * Link-tag entries win when a family appears in both sources.
	 *
	 * @param string $dir        Kit root directory (with trailing slash).
	 * @param string $tokens_css Tokens CSS section.
	 * @return array<int, array{family: string, variants: string}>
	 */
	private static function resolve_kit_fonts( string $dir, string $tokens_css ): array {
		$entries = self::extract_fonts_from_first_page( $dir );

		foreach ( self::extract_fonts_from_css( $tokens_css ) as $family ) {
			if ( ! isset( $entries[ $family ] ) ) {
				$entries[ $family ] = [ 'family' => $family, 'variants' => '' ];
			}
		}

		return array_values( $entries );
	}

	/**
	 * Extract font entries from the first pages/*.html file in the kit.
	 *
	 * Returns a `[family => entry]` map so merging with CSS-derived families
	 * is a straight `isset()` check.
	 *
	 * @param string $dir Kit root directory (with trailing slash).
	 * @return array<string, array{family: string, variants: string}>
	 */
	private static function extract_fonts_from_first_page( string $dir ): array {
		$pages_dir = $dir . 'pages';
		$files     = is_dir( $pages_dir ) ? glob( $pages_dir . '/*.html' ) : glob( $dir . '*.html' );

		if ( empty( $files ) ) {
			return [];
		}

		sort( $files );
		$first = $files[0];
		if ( ! file_exists( $first ) ) {
			return [];
		}

		$html = file_get_contents( $first );
		if ( '' === $html ) {
			return [];
		}

		// Load the document in the same HTML5-tolerant mode PageParser uses.
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$map = [];
		foreach ( PageParser::extract_fonts( $doc ) as $entry ) {
			$map[ $entry['family'] ] = $entry;
		}
		return $map;
	}

	/**
	 * Extract font family names from a CSS tokens string.
	 *
	 * @param string $css Tokens CSS section.
	 * @return string[] Font family names.
	 */
	private static function extract_fonts_from_css( string $css ): array {
		return FontExtractor::extract_families( $css );
	}

	/**
	 * Import pages from the kit.
	 *
	 * @param string      $dir        Kit root directory.
	 * @param array       $pages      Page config entries from client.
	 * @param string      $editor     Editor choice ('beaver-builder' or 'block-editor').
	 * @param string      $styles_css The shared styles.css content.
	 * @param string|null $ds_uuid    Resolved DS UUID.
	 * @param array       $errors     Error collector (passed by reference).
	 * @return array Page import results.
	 */
	private static function import_pages( string $dir, array $pages, string $editor, string $styles_css, ?string $ds_uuid, array &$errors ): array {
		$results = [];

		foreach ( $pages as $page ) {
			if ( empty( $page['import'] ) ) {
				continue;
			}

			// Prevent path traversal.
			$page_file = $page['file'] ?? '';
			if ( str_contains( $page_file, '..' ) ) {
				$errors[]  = 'Invalid page file path: ' . $page_file;
				$results[] = [
					'slug'    => $page['slug'] ?? '',
					'postId'  => null,
					'editUrl' => '',
					'status'  => 'error',
				];
				continue;
			}

			$file_path = $dir . $page_file;

			// Verify resolved path stays within the kit directory.
			$real_path = realpath( $file_path );
			$real_dir  = realpath( $dir );

			if ( false === $real_path || false === $real_dir || ! str_starts_with( $real_path, $real_dir ) ) {
				$errors[]  = 'Page file path escapes kit directory: ' . $page_file;
				$results[] = [
					'slug'    => $page['slug'] ?? '',
					'postId'  => null,
					'editUrl' => '',
					'status'  => 'error',
				];
				continue;
			}

			if ( ! file_exists( $file_path ) ) {
				$errors[] = 'Page file not found: ' . $page_file;
				$results[] = [
					'slug'   => $page['slug'] ?? '',
					'postId' => null,
					'editUrl' => '',
					'status' => 'error',
				];
				continue;
			}

			$html = file_get_contents( $real_path );

			// Inject DS CSS into the page HTML.
			if ( '' !== $styles_css ) {
				$html = self::inject_css_into_html( $html, $styles_css );
			}

			// Validate post type capability.
			$post_type     = \sanitize_key( $page['postType'] ?? 'page' );
			$post_type_obj = \get_post_type_object( $post_type );

			if ( ! $post_type_obj || ! \current_user_can( $post_type_obj->cap->create_posts ) ) {
				$errors[]  = 'You do not have permission to create "' . $post_type . '" posts.';
				$results[] = [
					'slug'    => $page['slug'] ?? '',
					'postId'  => null,
					'editUrl' => '',
					'status'  => 'error',
				];
				continue;
			}

			// Create the WordPress post.
			$post_id = \wp_insert_post( [
				'post_type'   => $post_type,
				'post_title'  => \sanitize_text_field( $page['title'] ?? '' ),
				'post_name'   => \sanitize_title( $page['slug'] ?? '' ),
				'post_status' => 'draft',
			], true );

			if ( \is_wp_error( $post_id ) ) {
				$errors[] = 'Failed to create post for page: ' . ( $page['slug'] ?? '' ) . ' - ' . $post_id->get_error_message();
				$results[] = [
					'slug'    => $page['slug'] ?? '',
					'postId'  => null,
					'editUrl' => '',
					'status'  => 'error',
				];
				continue;
			}

			// Enable BB for the post if using the BB editor.
			if ( 'beaver-builder' === $editor && class_exists( 'FLBuilderModel' ) ) {
				\update_post_meta( $post_id, '_fl_builder_enabled', true );
			}

			// Select the editor adapter.
			$adapter = self::create_adapter( $editor );

			// Build import options.
			$options = [
				'create_design_system' => false,
			];
			if ( $ds_uuid ) {
				$options['design_system_uuid'] = $ds_uuid;
			}

			// Run the import pipeline.
			$import_result = PageImporter::import( $html, $post_id, $adapter, $options );

			if ( ! empty( $import_result['errors'] ) ) {
				foreach ( $import_result['errors'] as $err ) {
					$errors[] = 'Page "' . ( $page['slug'] ?? '' ) . '": ' . $err;
				}
			}

			$edit_url = ( 'beaver-builder' === $editor && class_exists( 'FLBuilderModel' ) )
				? \FLBuilderModel::get_edit_url( $post_id )
				: \get_edit_post_link( $post_id, 'raw' );

			$results[] = [
				'title'   => $page['title'] ?? '',
				'slug'    => $page['slug'] ?? '',
				'postId'  => $post_id,
				'editUrl' => $edit_url,
				'status'  => 'imported',
			];
		}

		return $results;
	}

	/**
	 * Inject design system CSS into page HTML before the existing <style> block.
	 *
	 * PageParser expects CSS in a <style> block in <head>. Design Kit pages link
	 * to styles.css via <link>, so we inject the CSS content as a <style> element.
	 *
	 * @param string $html       Page HTML.
	 * @param string $styles_css CSS to inject.
	 * @return string Modified HTML.
	 */
	public static function inject_css_into_html( string $html, string $styles_css ): string {
		$style_tag = '<style>' . $styles_css . '</style>';

		// Remove the <link> to design-system/styles.css since we're inlining it.
		$html = preg_replace( '/<link[^>]*design-system\/styles\.css[^>]*>\s*/i', '', $html );

		// Try to inject before the first <style> tag in <head>.
		$style_pos = stripos( $html, '<style' );
		if ( false !== $style_pos ) {
			return substr( $html, 0, $style_pos ) . $style_tag . "\n" . substr( $html, $style_pos );
		}

		// Fall back to injecting before </head>.
		$head_close_pos = stripos( $html, '</head>' );
		if ( false !== $head_close_pos ) {
			return substr( $html, 0, $head_close_pos ) . $style_tag . "\n" . substr( $html, $head_close_pos );
		}

		// Last resort: prepend the style tag.
		return $style_tag . "\n" . $html;
	}

	/**
	 * Create the appropriate page editor adapter.
	 *
	 * @param string $editor Editor identifier.
	 * @return \FL\DesignSystem\Contracts\PageEditorAdapterInterface
	 */
	private static function create_adapter( string $editor ) {
		if ( 'beaver-builder' === $editor ) {
			$module_ns = Plugin::resolve_module_namespace( 'none' );
			$layout    = new LayoutManager();
			return new BeaverBuilderPageAdapter( $layout, $module_ns );
		}

		return new BlockEditorPageAdapter();
	}

	/**
	 * Import global elements (header/footer) using GlobalImporter.
	 *
	 * @param string      $dir        Kit root directory.
	 * @param array       $globals    Globals configuration from client.
	 * @param string      $styles_css The shared styles.css content.
	 * @param string|null $ds_uuid    Resolved DS UUID.
	 * @param string      $ds_label   Design system label for naming.
	 * @param array       $errors     Error collector (passed by reference).
	 * @return array Global import results keyed by type.
	 */
	private static function import_globals( string $dir, array $globals, string $styles_css, ?string $ds_uuid, string $ds_label, array &$errors ): array {
		$results = [];

		// Globals require editor-level access.
		if ( ! \current_user_can( 'edit_others_posts' ) ) {
			$has_imports = false;
			foreach ( [ 'header', 'footer' ] as $type ) {
				if ( ! empty( $globals[ $type ]['import'] ) ) {
					$has_imports = true;
					break;
				}
			}
			if ( $has_imports ) {
				$errors[] = 'You do not have permission to import global elements.';
			}
			return $results;
		}

		foreach ( [ 'header', 'footer' ] as $type ) {
			if ( ! isset( $globals[ $type ] ) ) {
				continue;
			}

			$global_config = $globals[ $type ];

			if ( empty( $global_config['import'] ) ) {
				$results[ $type ] = [ 'status' => 'skipped' ];
				continue;
			}

			$file_path = $dir . 'globals/' . $type . '.html';
			if ( ! file_exists( $file_path ) ) {
				$errors[]          = 'Global file not found: globals/' . $type . '.html';
				$results[ $type ]  = [ 'status' => 'error' ];
				continue;
			}

			$html   = file_get_contents( $file_path );
			$target = $global_config['target'] ?? '';

			if ( 'themer' === $target ) {
				$result = GlobalImporter::import_themer_layout( $html, $type, $styles_css, $ds_uuid, $ds_label );
			} elseif ( 'site-editor' === $target ) {
				$result = GlobalImporter::import_site_editor_part( $html, $type, $styles_css, $ds_uuid, $ds_label );
			} else {
				$results[ $type ] = [ 'status' => 'skipped' ];
				continue;
			}

			if ( ! empty( $result['errors'] ) ) {
				foreach ( $result['errors'] as $err ) {
					$errors[] = ucfirst( $type ) . ': ' . $err;
				}
			}

			$results[ $type ] = $result;
		}

		return $results;
	}
}
