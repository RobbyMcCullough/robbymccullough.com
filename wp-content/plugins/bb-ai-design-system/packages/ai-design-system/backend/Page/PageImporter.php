<?php

namespace FL\DesignSystem\Page;

use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Media\ImageValidator;
use FL\DesignSystem\Font\FontEntry;
use FL\DesignSystem\Services\Parser\PageParser;
use FL\DesignSystem\Services\Parser\TokenCssParser;

/**
 * Imports a spec-compliant HTML document into a WordPress post.
 *
 * Orchestrates the parsing pipeline, design system creation, and
 * editor adapter to import a complete page from a single HTML string.
 */
class PageImporter {

	/**
	 * Import a spec-compliant HTML document into a WordPress post.
	 *
	 * @param string                     $html    Complete HTML document string.
	 * @param int                        $post_id WordPress post ID to import into.
	 * @param PageEditorAdapterInterface $adapter Editor adapter.
	 * @param array                      $options Optional settings:
	 *     @type string $design_system_uuid   Existing DS UUID to assign.
	 *     @type bool   $create_design_system Whether to create a DS (default true).
	 * @return array {
	 *     @type string[]    $sections   Labels of imported sections.
	 *     @type string[]    $errors     Errors that prevented or partially blocked the import.
	 *     @type string[]    $warnings   Non-blocking issues the agent should know about (e.g., orphan CSS markers).
	 *     @type bool        $ds_created Whether a new DS was created.
	 *     @type string|null $ds_uuid    UUID of the assigned design system.
	 * }
	 */
	public static function import(
		string $html,
		int $post_id,
		PageEditorAdapterInterface $adapter,
		array $options = []
	): array {
		$create_ds = $options['create_design_system'] ?? true;
		$ds_uuid   = $options['design_system_uuid'] ?? null;
		$ds_label  = $options['design_system_label'] ?? null;

		$errors     = [];
		$ds_created = false;

		// Parse the HTML document.
		$parsed   = PageParser::parse( $html );
		$ds_data  = $parsed['designSystem'];
		$sections = $parsed['sections'];
		$warnings = $parsed['warnings'] ?? [];

		// Separate DS sections from preserved markers, preserving document order.
		$ds_sections  = [];
		$ordering_map = [];

		foreach ( $sections as $section ) {
			if ( ( $section['type'] ?? '' ) === 'preserved' ) {
				$ordering_map[] = [ 'kind' => 'preserved', 'data' => $section ];
			} else {
				$ds_index       = count( $ds_sections );
				$ds_sections[]  = $section;
				$ordering_map[] = [ 'kind' => 'ds', 'index' => $ds_index ];
			}
		}

		if ( empty( $ds_sections ) ) {
			$has_preserved = ! empty( array_filter( $ordering_map, fn( $item ) => 'preserved' === $item['kind'] ) );
			if ( ! $has_preserved ) {
				$diagnostic = $parsed['diagnostic'] ?? null;
				$errors[]   = '' !== (string) $diagnostic ? $diagnostic : 'No sections found in the HTML document.';
			}
			return [
				'sections'   => [],
				'errors'     => $errors,
				'warnings'   => $warnings,
				'ds_created' => false,
				'ds_uuid'    => $ds_uuid,
			];
		}

		// Handle design system creation/assignment.
		if ( ! empty( $ds_uuid ) ) {
			// Assign existing DS to the post.
			update_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, $ds_uuid );
		} elseif ( $create_ds && self::has_design_system_data( $ds_data ) ) {
			$ds_result = self::create_design_system( $ds_data, $ds_label );

			if ( is_wp_error( $ds_result ) ) {
				$errors[] = 'Failed to create design system: ' . $ds_result->get_error_message();
			} else {
				$ds_uuid    = get_post_meta( $ds_result['post']->ID, DesignSystemPostType::META_UUID, true );
				$ds_created = true;
				update_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, $ds_uuid );

				if ( ! empty( $ds_result['warnings'] ) ) {
					$warnings = array_merge( $warnings, $ds_result['warnings'] );
				}
			}
		}

		// Store page-level CSS and JS if present.
		if ( ! empty( $ds_data['page'] ) ) {
			update_post_meta( $post_id, PageOverrideProvider::PAGE_CSS_META_KEY, DesignSystemPostType::sanitize_css( $ds_data['page'] ) );
		}

		$page_js = self::build_page_js( $ds_data );
		if ( ! empty( $page_js ) ) {
			update_post_meta( $post_id, PageOverrideProvider::PAGE_JS_META_KEY, DesignSystemPostType::sanitize_js( $page_js ) );
		}

		// Validate image URLs in DS sections only.
		foreach ( $ds_sections as &$section ) {
			$section['html'] = ImageValidator::validate( $section['html'] );
		}
		unset( $section );

		// Prepare DS sections for the adapter.
		$prepared_ds = self::prepare_sections( $ds_sections );

		// Reconstruct the full ordered list for the adapter.
		$adapter_sections = [];
		foreach ( $ordering_map as $entry ) {
			if ( 'ds' === $entry['kind'] ) {
				$adapter_sections[] = $prepared_ds[ $entry['index'] ];
			} else {
				$adapter_sections[] = $entry['data'];
			}
		}

		// Import via the editor adapter.
		$adapter->import_sections( $post_id, $adapter_sections );

		// Collect DS section labels.
		$section_labels = array_map( fn( $s ) => $s['label'], $ds_sections );

		return [
			'sections'   => $section_labels,
			'errors'     => $errors,
			'warnings'   => $warnings,
			'ds_created' => $ds_created,
			'ds_uuid'    => $ds_uuid,
		];
	}

	/**
	 * Create a new design system from a head-only HTML document.
	 *
	 * Mirrors the create-from-page flow used by import(): parse the HTML
	 * via PageParser and pull out the designSystem payload (tokens, reset,
	 * base, fonts, baseJs). The body may be empty — parseHealth and the
	 * "body is empty" diagnostic are expected and ignored here.
	 *
	 * @param string      $html  Complete HTML document; <body> may be empty.
	 * @param string|null $label Optional design system label.
	 * @return array{post: \WP_Post, warnings: array<int, string>}|\WP_Error
	 */
	public static function create_design_system_from_html( string $html, ?string $label = null ) {
		$parsed  = PageParser::parse( $html );
		$ds_data = $parsed['designSystem'];

		if ( ! self::has_design_system_data( $ds_data ) ) {
			return new \WP_Error(
				'empty_design_system',
				'No design system data found in <head>. Add <style> blocks with /* @tokens */, /* @reset */, or /* @base */ markers.',
				[ 'status' => 400 ]
			);
		}

		if ( null === $label ) {
			$label = self::extract_title_from_html( $html );
		}

		return self::create_design_system( $ds_data, $label );
	}

	/**
	 * Extract the trimmed text content of the first <title> element.
	 *
	 * Returns null when the document has no <title> or the tag is empty,
	 * so callers can chain into their own final fallback. Mirrors the
	 * regex used by PageGenerator so both DS-creation paths agree.
	 *
	 * @param string $html Full HTML document.
	 * @return string|null Trimmed title, or null if missing/empty.
	 */
	public static function extract_title_from_html( string $html ): ?string {
		if ( ! preg_match( '/<title>(.+?)<\/title>/i', $html, $matches ) ) {
			return null;
		}
		$trimmed = trim( $matches[1] );
		return '' !== $trimmed ? $trimmed : null;
	}

	/**
	 * Check whether the parsed design system data has meaningful content.
	 *
	 * @param array $ds_data Parsed design system array.
	 * @return bool
	 */
	private static function has_design_system_data( array $ds_data ): bool {
		return ! empty( $ds_data['tokens'] )
			|| ! empty( $ds_data['reset'] )
			|| ! empty( $ds_data['base'] );
	}

	/**
	 * Create a new design system post from parsed data.
	 *
	 * @param array       $ds_data Parsed design system array.
	 * @param string|null $label   Optional design system label.
	 * @return array{post: \WP_Post, warnings: array<int, string>}|\WP_Error
	 */
	private static function create_design_system( array $ds_data, ?string $label = null ) {
		$parsed   = TokenCssParser::parse( $ds_data['tokens'] ?? '' );
		$tokens   = $parsed['tokens'];
		$warnings = $parsed['warnings'];

		$args = [
			'label'  => $label ?? 'Imported Design System',
			'tokens' => $tokens,
			'reset'  => $ds_data['reset'] ?? '',
			'base'   => $ds_data['base'] ?? '',
			'fonts'  => FontEntry::normalize( $ds_data['fonts'] ?? [] ),
		];

		if ( ! empty( $ds_data['baseJs'] ) ) {
			$args['js'] = $ds_data['baseJs'];
		}

		$post = DesignSystemPostType::create( $args );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return [
			'post'     => $post,
			'warnings' => $warnings,
		];
	}

	/**
	 * Build combined page-level JS from parsed data.
	 *
	 * @param array $ds_data Parsed design system array.
	 * @return string
	 */
	private static function build_page_js( array $ds_data ): string {
		$parts = [];

		if ( ! empty( $ds_data['pageJs'] ) ) {
			$parts[] = $ds_data['pageJs'];
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Prepare parsed sections for the editor adapter.
	 *
	 * Wraps section JS in an IIFE for isolation.
	 *
	 * @param array $sections Parsed sections from PageParser.
	 * @return array Sections ready for adapter import.
	 */
	/**
	 * Parse CSS custom properties from a :root block into a key-value array.
	 *
	 * Backward-compat shim — delegates to {@see TokenCssParser::parse()} and
	 * returns only the tokens map. Callers that need the warnings array
	 * (dropped non-`:root` blocks) call TokenCssParser directly.
	 *
	 * @param string $css CSS string containing a :root { ... } block.
	 * @return array Token map { name => value }.
	 */
	public static function parse_tokens_from_css( string $css ): array {
		return TokenCssParser::parse( $css )['tokens'];
	}

	/**
	 * Prepare parsed sections for the editor adapter.
	 *
	 * Wraps section JS in an IIFE for isolation.
	 *
	 * @param array $sections Parsed sections from PageParser.
	 * @return array Sections ready for adapter import.
	 */
	private static function prepare_sections( array $sections ): array {
		return array_map( function ( $section ) {
			$prepared = [
				'label' => $section['label'],
				'html'  => $section['html'],
				'css'   => $section['css'] ?? '',
				'js'    => '',
			];

			if ( ! empty( $section['js'] ) ) {
				$prepared['js'] = '(function() { ' . $section['js'] . ' })();';
			}

			return $prepared;
		}, $sections );
	}
}
