<?php

namespace FL\DesignSystem\BeaverBuilder;

use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * Copies the design system reference from the page being edited to a newly
 * saved BB template post.
 *
 * BB's save-as-template flow copies layout data but not post meta, so the DS
 * reference stored on the source page as `_fl_ds_ref` never reaches the new
 * template post. When the template is previewed, no DS assets are enqueued.
 *
 * Layout templates also receive the source page's CSS/JS overrides so the
 * preview matches the page snapshot. Node templates (rows/columns/modules)
 * receive only the DS reference — page-level overrides don't belong on a
 * reusable fragment.
 */
class TemplateSaveHandler {

	public function boot(): void {
		add_action( 'fl_builder_after_save_user_template', [ $this, 'copy_to_layout_template' ] );
		add_action( 'fl_builder_after_save_node_template', [ $this, 'copy_to_node_template' ], 10, 3 );
	}

	/**
	 * Copy DS reference and page-level CSS/JS from the source page to a newly
	 * saved layout template.
	 *
	 * @param int $template_post_id The new template post ID.
	 */
	public function copy_to_layout_template( int $template_post_id ): void {
		$source_post_id = $this->get_source_post_id();

		if ( ! $source_post_id || $source_post_id === $template_post_id ) {
			return;
		}

		$meta_keys = [
			PageOverrideProvider::DS_REF_META_KEY,
			PageOverrideProvider::PAGE_CSS_META_KEY,
			PageOverrideProvider::PAGE_JS_META_KEY,
		];

		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $source_post_id, $key, true );
			if ( ! empty( $value ) ) {
				update_post_meta( $template_post_id, $key, $value );
			}
		}
	}

	/**
	 * Copy the DS reference from the source page to a newly saved node template.
	 *
	 * Page-level CSS/JS overrides are intentionally not copied: they describe
	 * the source page, not a reusable row/column/module fragment.
	 *
	 * @param int    $template_post_id The new template post ID.
	 * @param object $root_node        The root node (unused, present for hook signature).
	 * @param array  $settings         Save settings (unused, present for hook signature).
	 */
	public function copy_to_node_template( int $template_post_id, $root_node = null, $settings = null ): void {
		$source_post_id = $this->get_source_post_id();

		if ( ! $source_post_id || $source_post_id === $template_post_id ) {
			return;
		}

		$ds_ref = get_post_meta( $source_post_id, PageOverrideProvider::DS_REF_META_KEY, true );

		if ( ! empty( $ds_ref ) ) {
			update_post_meta( $template_post_id, PageOverrideProvider::DS_REF_META_KEY, $ds_ref );
		}
	}

	/**
	 * Resolve the post ID of the page currently being edited in BB.
	 *
	 * @return int Source post ID, or 0 if unavailable.
	 */
	private function get_source_post_id(): int {
		if ( ! class_exists( '\\FLBuilderModel' ) ) {
			return 0;
		}

		return (int) \FLBuilderModel::get_post_id();
	}
}
