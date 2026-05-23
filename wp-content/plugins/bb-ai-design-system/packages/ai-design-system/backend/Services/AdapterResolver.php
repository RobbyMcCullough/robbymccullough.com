<?php

namespace FL\DesignSystem\Services;

use FL\DesignSystem\BeaverBuilder\BeaverBuilderPageAdapter;
use FL\DesignSystem\BeaverBuilder\LayoutManager;
use FL\DesignSystem\BlockEditor\BlockEditorPageAdapter;
use FL\DesignSystem\Contracts\PageEditorAdapterInterface;

/**
 * Resolves the appropriate page editor adapter for a given post.
 *
 * Centralizes the BB-vs-Block-Editor decision so callers (MCP abilities,
 * REST controllers, kit downloads) all share one source of truth.
 */
class AdapterResolver {

	private LayoutManager $layout;
	private string $module_namespace;

	/**
	 * @param LayoutManager $layout           Layout manager for BB operations.
	 * @param string        $module_namespace Module namespace prefix.
	 */
	public function __construct( LayoutManager $layout, string $module_namespace = 'ds' ) {
		$this->layout           = $layout;
		$this->module_namespace = $module_namespace;
	}

	/**
	 * Resolve the appropriate editor adapter for a post.
	 *
	 * When a post_id is provided, checks whether it is managed by Beaver Builder
	 * or the block editor and returns the corresponding adapter. Without a post_id,
	 * uses BB if it is active and enabled for the given post type, otherwise falls
	 * back to the block editor adapter.
	 *
	 * @param int|null    $post_id   Optional post ID to detect the editor.
	 * @param string|null $post_type Optional post type for new posts (used when no post_id).
	 * @return PageEditorAdapterInterface
	 */
	public function for_post( ?int $post_id = null, ?string $post_type = null ): PageEditorAdapterInterface {
		if ( $post_id ) {
			$bb_enabled = get_post_meta( $post_id, '_fl_builder_enabled', true );
			if ( $bb_enabled && class_exists( 'FLBuilderModel' ) ) {
				return new BeaverBuilderPageAdapter( $this->layout, $this->module_namespace );
			}
			return new BlockEditorPageAdapter();
		}

		// No post_id (e.g. generate-page) — use BB if active and enabled for this post type.
		if ( $this->is_bb_enabled_for_post_type( $post_type ?? 'page' ) ) {
			return new BeaverBuilderPageAdapter( $this->layout, $this->module_namespace );
		}

		return new BlockEditorPageAdapter();
	}

	/**
	 * Check whether Beaver Builder is active and enabled for a post type.
	 *
	 * @param string $post_type Post type to check.
	 * @return bool
	 */
	private function is_bb_enabled_for_post_type( string $post_type ): bool {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return false;
		}

		$enabled_types = \FLBuilderModel::get_post_types();
		return in_array( $post_type, $enabled_types, true );
	}
}
