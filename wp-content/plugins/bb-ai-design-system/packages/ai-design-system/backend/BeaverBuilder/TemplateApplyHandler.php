<?php

namespace FL\DesignSystem\BeaverBuilder;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * Propagates the design system reference (and page-level overrides) from a BB
 * template to the target page when the template is applied.
 *
 * Rules:
 *   - Layout template, Replace mode: template DS wins when present; otherwise
 *     the target's existing DS is preserved.
 *   - Layout template, Append/Prepend: target's DS wins if set; otherwise the
 *     template's full snapshot (ref + page CSS + page JS) is adopted.
 *   - Node template (row/column/module, global or not): target's DS wins if
 *     set; otherwise the template's DS ref is adopted.
 *   - Before writing any ref, the UUID is validated against the local DS
 *     library. Unresolvable refs are skipped silently.
 */
class TemplateApplyHandler {

	public function boot(): void {
		add_action( 'fl_builder_after_apply_user_template', [ $this, 'apply_to_layout' ], 10, 2 );
		add_action( 'fl_builder_after_apply_node_template', [ $this, 'apply_to_node' ], 10, 3 );
	}

	/**
	 * Handle DS propagation when a layout template is applied.
	 *
	 * @param int   $template_post_id Source template post ID (0 if unknown).
	 * @param mixed $append           Mode flag: falsy = Replace, truthy = Append/Prepend.
	 */
	public function apply_to_layout( int $template_post_id, $append ): void {
		$target_post_id = $this->get_target_post_id();

		if ( ! $target_post_id || ! $template_post_id || $target_post_id === $template_post_id ) {
			return;
		}

		$is_replace = empty( $append );

		if ( $is_replace ) {
			$this->copy_snapshot( $template_post_id, $target_post_id );
			return;
		}

		// Append / Prepend: page wins if it already has a DS ref.
		$existing_ref = get_post_meta( $target_post_id, PageOverrideProvider::DS_REF_META_KEY, true );

		if ( ! empty( $existing_ref ) ) {
			return;
		}

		$this->copy_snapshot( $template_post_id, $target_post_id );
	}

	/**
	 * Handle DS propagation when a node template is applied.
	 *
	 * Node templates carry only the DS ref (no page CSS/JS), so adoption is
	 * scoped to that single meta key.
	 *
	 * @param int   $template_post_id Source template post ID (0 if unknown).
	 * @param mixed $root_node        Unused; present for hook signature symmetry.
	 * @param mixed $parent_id        Unused; present for hook signature symmetry.
	 */
	public function apply_to_node( int $template_post_id, $root_node = null, $parent_id = null ): void {
		$target_post_id = $this->get_target_post_id();

		if ( ! $target_post_id || ! $template_post_id || $target_post_id === $template_post_id ) {
			return;
		}

		$existing_ref = get_post_meta( $target_post_id, PageOverrideProvider::DS_REF_META_KEY, true );

		if ( ! empty( $existing_ref ) ) {
			return;
		}

		$template_ref = get_post_meta( $template_post_id, PageOverrideProvider::DS_REF_META_KEY, true );

		if ( empty( $template_ref ) || ! is_string( $template_ref ) ) {
			return;
		}

		if ( null === DesignSystemPostType::get_by_uuid( $template_ref ) ) {
			return;
		}

		update_post_meta( $target_post_id, PageOverrideProvider::DS_REF_META_KEY, $template_ref );
	}

	/**
	 * Copy the full page-style snapshot (ref + page CSS + page JS) from source
	 * to target. No-ops when the source has no DS ref or the ref doesn't
	 * resolve locally.
	 */
	private function copy_snapshot( int $source, int $target ): void {
		$ref = get_post_meta( $source, PageOverrideProvider::DS_REF_META_KEY, true );

		if ( empty( $ref ) || ! is_string( $ref ) ) {
			return;
		}

		if ( null === DesignSystemPostType::get_by_uuid( $ref ) ) {
			return;
		}

		$meta_keys = [
			PageOverrideProvider::DS_REF_META_KEY,
			PageOverrideProvider::PAGE_CSS_META_KEY,
			PageOverrideProvider::PAGE_JS_META_KEY,
		];

		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $source, $key, true );
			update_post_meta( $target, $key, $value );
		}
	}

	/**
	 * Resolve the post ID of the page currently being edited in BB.
	 *
	 * @return int Target post ID, or 0 if unavailable.
	 */
	private function get_target_post_id(): int {
		if ( ! class_exists( '\\FLBuilderModel' ) ) {
			return 0;
		}

		return (int) \FLBuilderModel::get_post_id();
	}
}
