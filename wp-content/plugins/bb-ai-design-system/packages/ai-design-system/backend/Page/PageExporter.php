<?php

namespace FL\DesignSystem\Page;

use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\DesignSystem\SystemTokens;
use FL\DesignSystem\Services\Parser\AnnotationReconstructor;
use FL\DesignSystem\Font\FontEntry;
use FL\DesignSystem\Font\GoogleFontsUrl;

/**
 * Exports a WordPress post's content as a spec-compliant HTML document.
 *
 * Reassembles design system CSS, section content, and page metadata
 * into a single HTML document matching the build agent format.
 *
 * Supports two output formats:
 * - 'self_contained' (default): tokens/reset/base CSS and base JS are inlined.
 *   This is the format consumed by MCP and the /pages REST endpoints.
 * - 'kit': page links to ../design-system/styles.css (and optionally
 *   ../design-system/script.js); only @page and @section CSS/JS are inlined.
 *   This is the format used by Design Kit downloads.
 */
class PageExporter {

	public const FORMAT_SELF_CONTAINED = 'self_contained';
	public const FORMAT_KIT            = 'kit';

	/**
	 * Export a WordPress post as a spec-compliant HTML document.
	 *
	 * @param int                        $post_id WordPress post ID.
	 * @param PageEditorAdapterInterface $adapter Editor adapter.
	 * @param array                      $options {
	 *     Optional. Export options.
	 *
	 *     @type string $format One of 'self_contained' (default) or 'kit'.
	 * }
	 * @return array {
	 *     @type string      $html               Complete HTML document.
	 *     @type string      $title               Post title.
	 *     @type string      $status              Post status.
	 *     @type string|null $design_system_uuid   UUID of the assigned design system.
	 * }
	 */
	public static function export(
		int $post_id,
		PageEditorAdapterInterface $adapter,
		array $options = []
	): array {
		$format = $options['format'] ?? self::FORMAT_SELF_CONTAINED;

		$post   = get_post( $post_id );
		$title  = $post ? $post->post_title : '';
		$status = $post ? $post->post_status : 'draft';

		// Resolve design system.
		$ds_uuid = get_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, true ) ?: null;
		$ds_data = null;

		if ( $ds_uuid ) {
			$ds_post = DesignSystemPostType::get_by_uuid( $ds_uuid );
			if ( $ds_post ) {
				$ds_data = self::load_design_system_data( $ds_post );
			}
		}

		// Load page-level CSS/JS.
		$page_css = get_post_meta( $post_id, PageOverrideProvider::PAGE_CSS_META_KEY, true ) ?: '';
		$page_js  = get_post_meta( $post_id, PageOverrideProvider::PAGE_JS_META_KEY, true ) ?: '';

		// Export sections from the editor.
		$sections = $adapter->export_sections( $post_id );

		// Build the HTML document.
		$html = self::build_html_document( $ds_data, $sections, $page_css, $page_js, $format );

		return [
			'html'               => $html,
			'title'              => $title,
			'status'             => $status,
			'design_system_uuid' => $ds_uuid,
		];
	}

	/**
	 * Load design system data from a DS post.
	 *
	 * @param \WP_Post $ds_post Design system post.
	 * @return array DS data with tokens, reset, base, fonts, js.
	 */
	private static function load_design_system_data( \WP_Post $ds_post ): array {
		$structured = DesignSystemPostType::get_structured_data( $ds_post );

		$fonts_raw = get_post_meta( $ds_post->ID, DesignSystemPostType::META_FONTS, true );
		$fonts     = FontEntry::normalize( $fonts_raw );

		$js = get_post_meta( $ds_post->ID, DesignSystemPostType::META_BASE_JS, true ) ?: '';

		return [
			'tokens' => $structured['tokens'],
			'reset'  => $structured['reset'],
			'base'   => $structured['base'],
			'fonts'  => $fonts,
			'js'     => $js,
		];
	}

	/**
	 * Build a complete HTML document from design system data and sections.
	 *
	 * @param array|null $ds_data   Design system data or null.
	 * @param array      $sections  Exported sections from the adapter.
	 * @param string     $page_css  Page-level CSS.
	 * @param string     $page_js   Page-level JS.
	 * @param string     $format    Output format: FORMAT_SELF_CONTAINED or FORMAT_KIT.
	 * @return string Complete HTML document.
	 */
	private static function build_html_document(
		?array $ds_data,
		array $sections,
		string $page_css,
		string $page_js,
		string $format = self::FORMAT_SELF_CONTAINED
	): string {
		$is_kit = self::FORMAT_KIT === $format;

		$head_parts = [];

		// Google Fonts link.
		$fonts_link = self::build_fonts_link( $ds_data['fonts'] ?? [] );
		if ( $fonts_link ) {
			$head_parts[] = $fonts_link;
		}

		// In kit mode, link to the shared design-system stylesheet/script
		// instead of inlining tokens/reset/base. Only add the script link if
		// the DS has base JS — kits without shared JS don't ship script.js.
		if ( $is_kit && $ds_data ) {
			$head_parts[] = '<link rel="stylesheet" href="../design-system/styles.css">';
			if ( ! empty( $ds_data['js'] ) ) {
				$head_parts[] = '<script src="../design-system/script.js"></script>';
			}
		}

		// CSS block.
		$css = self::build_css_block( $ds_data, $sections, $page_css, $format );
		if ( '' !== $css ) {
			$head_parts[] = '<style>' . "\n" . $css . "\n" . '</style>';
		}

		// JS block.
		$js = self::build_js_block( $ds_data, $sections, $page_js, $format );
		if ( '' !== $js ) {
			$head_parts[] = '<script>' . "\n" . $js . "\n" . '</script>';
		}

		// Body sections and preserved markers.
		$body_parts = [];
		foreach ( $sections as $section ) {
			if ( ( $section['type'] ?? '' ) === 'preserved' ) {
				$body_parts[] = self::build_preserved_marker( $section );
				continue;
			}

			$section_html = self::reconstruct_section_html( $section );
			if ( $section_html ) {
				$body_parts[] = $section_html;
			}
		}

		$head_content = implode( "\n", $head_parts );
		$body_content = implode( "\n", $body_parts );

		return "<!DOCTYPE html>\n<html>\n<head>\n"
			. $head_content . "\n"
			. "</head>\n<body>\n"
			. $body_content . "\n"
			. "</body>\n</html>";
	}

	/**
	 * Build a Google Fonts link tag.
	 *
	 * Accepts either the legacy string[] or the new {family, variants}[]
	 * shape and routes through the shared URL builder.
	 *
	 * @param  array $fonts Font entries (legacy string[] or {family, variants}[]).
	 * @return string Link tag or empty string.
	 */
	private static function build_fonts_link( array $fonts ): string {
		if ( empty( $fonts ) ) {
			return '';
		}

		$url = GoogleFontsUrl::build( $fonts );
		if ( '' === $url ) {
			return '';
		}

		return '<link href="' . $url . '" rel="stylesheet">';
	}

	/**
	 * Build the combined CSS block with comment markers.
	 *
	 * @param array|null $ds_data   Design system data.
	 * @param array      $sections  Exported sections.
	 * @param string     $page_css  Page-level CSS.
	 * @param string     $format    Output format: FORMAT_SELF_CONTAINED or FORMAT_KIT.
	 * @return string Combined CSS with markers.
	 */
	private static function build_css_block( ?array $ds_data, array $sections, string $page_css, string $format = self::FORMAT_SELF_CONTAINED ): string {
		$parts  = [];
		$is_kit = self::FORMAT_KIT === $format;

		// In kit mode, DS-level CSS lives in design-system/styles.css and is
		// linked from the page; skip inlining tokens/reset/base here.
		if ( $ds_data && ! $is_kit ) {
			if ( ! empty( $ds_data['tokens'] ) ) {
				$tokens_css = self::build_tokens_css( $ds_data['tokens'] );
				if ( '' !== $tokens_css ) {
					$parts[] = "/* @tokens */\n" . $tokens_css;
				}
			}

			// System CSS rules (not user-editable, derived from system tokens).
			$system_css = SystemTokens::get_css_for_tokens( $ds_data['tokens'] );
			if ( '' !== $system_css ) {
				$parts[] = trim( $system_css );
			}

			if ( ! empty( $ds_data['reset'] ) ) {
				$parts[] = "/* @reset */\n" . $ds_data['reset'];
			}

			if ( ! empty( $ds_data['base'] ) ) {
				$parts[] = "/* @base */\n" . $ds_data['base'];
			}
		}

		if ( '' !== $page_css ) {
			$parts[] = "/* @page */\n" . self::strip_leading_page_marker( $page_css );
		}

		foreach ( $sections as $section ) {
			if ( ( $section['type'] ?? '' ) === 'preserved' ) {
				continue;
			}
			$css = $section['css'] ?? '';
			if ( '' !== $css ) {
				$label   = $section['label'] ?? 'Section';
				$parts[] = '/* @section ' . $label . " */\n" . $css;
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Drop a leading `/* @page *​/` marker (or a stack of them) from page CSS or
	 * page JS before re-injecting the canonical marker. Without this strip,
	 * agents that round-trip the rendered page through update-page-assets stack
	 * a duplicate marker on each save because the stored value already carries
	 * the marker the renderer is about to add back.
	 *
	 * @param string $content Page-level CSS or JS as stored.
	 * @return string Content with any leading @page marker(s) removed.
	 */
	private static function strip_leading_page_marker( string $content ): string {
		return (string) preg_replace( '/^(?:\s*\/\*\s*@page\s*\*\/\s*)+/i', '', $content );
	}

	/**
	 * Build :root CSS from a tokens array.
	 *
	 * @param array $tokens Token map { name => value }.
	 * @return string CSS :root block.
	 */
	private static function build_tokens_css( array $tokens ): string {
		if ( empty( $tokens ) ) {
			return '';
		}

		$lines = [];
		foreach ( $tokens as $name => $value ) {
			$lines[] = '  ' . $name . ': ' . $value . ';';
		}

		return ":root {\n" . implode( "\n", $lines ) . "\n}";
	}

	/**
	 * Build the combined JS block with comment markers.
	 *
	 * @param array|null $ds_data   Design system data.
	 * @param array      $sections  Exported sections.
	 * @param string     $page_js   Page-level JS.
	 * @param string     $format    Output format: FORMAT_SELF_CONTAINED or FORMAT_KIT.
	 * @return string Combined JS with markers.
	 */
	private static function build_js_block( ?array $ds_data, array $sections, string $page_js, string $format = self::FORMAT_SELF_CONTAINED ): string {
		$parts  = [];
		$is_kit = self::FORMAT_KIT === $format;

		// In kit mode, DS-level JS lives in design-system/script.js and is
		// linked from the page; skip inlining @base here.
		if ( $ds_data && ! empty( $ds_data['js'] ) && ! $is_kit ) {
			$parts[] = "/* @base */\n" . $ds_data['js'];
		}

		if ( '' !== $page_js ) {
			$parts[] = "/* @page */\n" . self::strip_leading_page_marker( $page_js );
		}

		foreach ( $sections as $section ) {
			if ( ( $section['type'] ?? '' ) === 'preserved' ) {
				continue;
			}
			$js = $section['js'] ?? '';
			if ( '' !== $js ) {
				$label   = $section['label'] ?? 'Section';
				$parts[] = '/* @section ' . $label . " */\n" . $js;
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Build an HTML comment marker for a preserved (non-DS) block.
	 *
	 * @param array $section Preserved marker data.
	 * @return string HTML comment.
	 */
	private static function build_preserved_marker( array $section ): string {
		$preserved_type = $section['preserved_type'] ?? 'wp';
		$block_name     = $section['block_name'] ?? $section['module_type'] ?? '';
		$label          = $section['label'] ?? '';
		$node_id        = $section['node_id'] ?? '';
		$block_index    = $section['block_index'] ?? '';

		$attrs  = 'type="' . htmlspecialchars( $block_name, ENT_QUOTES, 'UTF-8' ) . '"';
		$attrs .= ' label="' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '"';

		if ( '' !== $node_id ) {
			$attrs .= ' node="' . htmlspecialchars( $node_id, ENT_QUOTES, 'UTF-8' ) . '"';
		}
		if ( '' !== (string) $block_index ) {
			$attrs .= ' index="' . (int) $block_index . '"';
		}

		return "<!-- {$preserved_type}:preserved {$attrs} -->";
	}

	/**
	 * Reconstruct annotated HTML for a section, adding data-label and id attributes.
	 *
	 * @param array $section Section data with template, settings, label.
	 * @return string|null Annotated HTML string or null.
	 */
	private static function reconstruct_section_html( array $section ): ?string {
		$template = $section['template'] ?? '';
		$settings = $section['settings'] ?? [];
		$label    = $section['label'] ?? '';

		if ( '' === $template ) {
			return null;
		}

		$html = AnnotationReconstructor::reconstruct( $template, $settings );

		if ( null === $html || '' === $html ) {
			return null;
		}

		// Add data-label attribute to the outermost element.
		if ( '' !== $label ) {
			$html = preg_replace(
				'/^(\s*<[a-zA-Z][a-zA-Z0-9]*)/',
				'$1 data-label="' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '"',
				$html,
				1
			);
		}

		// Add data-node attribute for reconciliation on re-import.
		$node_id = $section['node_id'] ?? '';
		if ( '' !== $node_id ) {
			$html = preg_replace(
				'/^(\s*<[a-zA-Z][a-zA-Z0-9]*)/',
				'$1 data-node="' . htmlspecialchars( $node_id, ENT_QUOTES, 'UTF-8' ) . '"',
				$html,
				1
			);
		}

		return $html;
	}
}
