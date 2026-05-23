<?php

namespace FL\DesignSystem\BlockEditor;

use FL\DesignSystem\DesignSystem\DesignSystemCssBuilder;

/**
 * Delivers design system CSS to block editor iframes via the official
 * settings.styles channel.
 *
 * Hooks `block_editor_settings_all` so the DS CSS reaches both the main
 * editor canvas and every BlockPreview iframe through EditorStyles. The
 * previous `enqueue_block_assets` + `get_the_ID()` approach failed in the
 * site editor because `get_the_ID()` returns 0 on site-editor.php; this
 * filter reads `$context->post`, which WP populates for both
 * post.php?post=X and site-editor.php?postId=X.
 */
class BlockEditorSettingsFilter {

	public function boot(): void {
		add_filter( 'block_editor_settings_all', [ $this, 'filter_settings' ], 10, 2 );
	}

	/**
	 * Append DS CSS entries to `$editor_settings['styles']`.
	 *
	 * @param  array                    $editor_settings Editor settings array.
	 * @param  \WP_Block_Editor_Context $context         Editor context; `->post` is
	 *                                                   populated on direct URL loads
	 *                                                   for both editor routes.
	 * @return array
	 */
	public function filter_settings( $editor_settings, $context ) {
		if ( ! is_array( $editor_settings ) ) {
			return $editor_settings;
		}

		if ( ! $context instanceof \WP_Block_Editor_Context ) {
			return $editor_settings;
		}

		$post = $context->post;

		if ( ! $post instanceof \WP_Post ) {
			return $editor_settings;
		}

		// Patterns (wp_block) are covered by the client-side fl-ds/scope
		// wrapper, which writes the pattern's own DS into the active document.
		// Server-side delivery for patterns is therefore redundant, and when
		// the pattern is edited inline on a page it would also leak the
		// pattern's DS into preview iframes for other patterns that use a
		// different DS.
		if ( 'wp_block' === $post->post_type ) {
			return $editor_settings;
		}

		$post_id  = (int) $post->ID;
		$ds_css   = DesignSystemCssBuilder::build_for_post( $post_id );
		$page_css = DesignSystemCssBuilder::build_page_override_for_post( $post_id );

		if ( '' === $ds_css && '' === $page_css ) {
			return $editor_settings;
		}

		if ( ! isset( $editor_settings['styles'] ) || ! is_array( $editor_settings['styles'] ) ) {
			$editor_settings['styles'] = [];
		}

		if ( '' !== $ds_css ) {
			$editor_settings['styles'][] = [
				'css'    => $ds_css,
				'source' => 'fl-design-system',
			];
		}

		if ( '' !== $page_css ) {
			$editor_settings['styles'][] = [
				'css'    => $page_css,
				'source' => 'fl-design-system-page-override',
			];
		}

		return $editor_settings;
	}
}
