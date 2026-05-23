<?php

namespace FL\DesignSystem\DesignSystem;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\DesignSystem\SystemTokens;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * Builds a scoped CSS payload for a given post's effective design system.
 *
 * Shared by DesignSystemAssetProvider (frontend enqueue) and
 * BlockEditorSettingsFilter (block editor delivery) so both paths emit
 * identical CSS. Reset and base CSS are scoped via DesignSystemCssScoper;
 * tokens are emitted as a :root block plus system-token fallbacks.
 */
class DesignSystemCssBuilder {

	/**
	 * Build the full DS CSS (tokens + scoped reset + scoped base + system tokens)
	 * for the post's effective design system.
	 *
	 * Combined string suitable for callers that emit DS CSS as a single payload
	 * (BlockEditorSettingsFilter, AI canvas previews). The cascade-aware frontend
	 * enqueue path uses build_parts_for_post() instead.
	 *
	 * @param  int $post_id Post being rendered/edited.
	 * @return string Compiled CSS, or empty string when no DS resolves.
	 */
	public static function build_for_post( int $post_id ): string {
		$parts = self::build_parts_for_post( $post_id );
		if ( '' === $parts['bulk'] && '' === $parts['html'] ) {
			return '';
		}
		return trim( $parts['bulk'] . "\n" . $parts['html'] );
	}

	/**
	 * Build the DS CSS for a post split into bulk and html-rule buckets.
	 *
	 * The bulk bucket carries tokens, scoped reset, and scoped base with all
	 * top-level html rules extracted. The html bucket carries any extracted
	 * html rules plus the SystemTokens output (html font-size). Callers route
	 * the buckets to different elements so the bulk can sit in the BB
	 * layout-CSS dep chain while the html rule remains cascade-last.
	 *
	 * @param  int $post_id Post being rendered/edited.
	 * @return array { bulk: string, html: string }
	 */
	public static function build_parts_for_post( int $post_id ): array {
		$empty = [
			'bulk' => '',
			'html' => '',
		];

		$ds_post = DesignSystemPostType::resolve_for_post( $post_id );

		if ( ! $ds_post instanceof \WP_Post ) {
			return $empty;
		}

		$structured = DesignSystemPostType::get_structured_data( $ds_post );

		$html_pieces = [];

		if ( ! empty( $structured['reset'] ) ) {
			$scoped_reset       = DesignSystemCssScoper::scope( $structured['reset'] );
			$split              = DesignSystemCssScoper::split_html_rules( $scoped_reset );
			$structured['reset'] = $split['withoutHtml'];
			if ( '' !== $split['htmlOnly'] ) {
				$html_pieces[] = $split['htmlOnly'];
			}
		}

		if ( ! empty( $structured['base'] ) ) {
			$scoped_base        = DesignSystemCssScoper::scope( $structured['base'] );
			$split              = DesignSystemCssScoper::split_html_rules( $scoped_base );
			$structured['base'] = $split['withoutHtml'];
			if ( '' !== $split['htmlOnly'] ) {
				$html_pieces[] = $split['htmlOnly'];
			}
		}

		$bulk        = DesignSystemPostType::reconstruct_css( $structured );
		$system_css  = SystemTokens::get_css_for_tokens( $structured['tokens'] ?? [] );

		if ( '' !== $system_css ) {
			$html_pieces[] = $system_css;
		}

		return [
			'bulk' => $bulk,
			'html' => implode( "\n", $html_pieces ),
		];
	}

	/**
	 * Build the scoped page-level CSS override for a post, if any.
	 *
	 * @param  int $post_id Post whose override to read.
	 * @return string Scoped page CSS, or empty string.
	 */
	public static function build_page_override_for_post( int $post_id ): string {
		$page_css = get_post_meta( $post_id, PageOverrideProvider::PAGE_CSS_META_KEY, true );

		if ( empty( $page_css ) || ! is_string( $page_css ) ) {
			return '';
		}

		return DesignSystemCssScoper::scope( $page_css );
	}
}
