<?php

namespace FL\DesignSystem\Mcp\Support;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Font\FontEntry;
use FL\DesignSystem\Font\FontMerger;

/**
 * Apply a `font_overrides` patch to a design system's font list.
 *
 * Two abilities (UpdateDesignSystemTokens, UpdateDesignSystemAssets) accept
 * a `font_overrides` input shape and need to validate, merge, and persist
 * the same way. The merger lives in {@see FontMerger}; this service is the
 * thin glue around the read/write surface and the input validation.
 */
class FontOverridesService {

	/**
	 * Apply overrides and return null on success or a WP_Error on bad input.
	 *
	 * @param \WP_Post $ds_post   Design system post.
	 * @param mixed    $overrides Raw font_overrides input.
	 * @return \WP_Error|null
	 */
	public function apply( \WP_Post $ds_post, $overrides ): ?\WP_Error {
		if ( ! is_array( $overrides ) ) {
			return new \WP_Error(
				'invalid_font_overrides',
				'font_overrides must be an object mapping family names to { "variants": "..." } or null.',
				[ 'status' => 400 ]
			);
		}

		$existing = FontEntry::normalize( get_post_meta( $ds_post->ID, DesignSystemPostType::META_FONTS, true ) );
		$merged   = FontMerger::merge( $existing, $overrides );

		if ( is_wp_error( $merged ) ) {
			return $merged;
		}

		$safe = DesignSystemPostType::sanitize_font_entries( $merged );
		update_post_meta( $ds_post->ID, DesignSystemPostType::META_FONTS, wp_json_encode( $safe ) );

		return null;
	}
}
