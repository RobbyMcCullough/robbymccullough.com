<?php

namespace FL\DesignSystem\Services;

/**
 * Helpers for figuring out which post types the current user can create.
 *
 * Used by MCP (to filter the generate-page enum and pick a fallback) and
 * by the admin welcome screen (to link "Build a page" to a post-new URL
 * the user actually has access to).
 */
class PostTypeAccess {

	/**
	 * Get public post type slugs the current user can create.
	 *
	 * Excludes attachments.
	 *
	 * @return string[]
	 */
	public function get_creatable_post_type_slugs(): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$slugs      = [];
		foreach ( $post_types as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}
			if ( ! current_user_can( $post_type->cap->create_posts ) ) {
				continue;
			}
			$slugs[] = $post_type->name;
		}
		return $slugs;
	}

	/**
	 * Pick a sensible default post type for the current user.
	 *
	 * Prefers 'page' when available; otherwise returns the first
	 * creatable public post type. Returns null if the user can't
	 * create any public post types.
	 *
	 * @return string|null
	 */
	public function get_default_creatable_post_type(): ?string {
		$slugs = $this->get_creatable_post_type_slugs();
		if ( empty( $slugs ) ) {
			return null;
		}
		if ( in_array( 'page', $slugs, true ) ) {
			return 'page';
		}
		return $slugs[0];
	}

	/**
	 * Build a post-new.php URL for the current user's default creatable
	 * post type, or null if they can't create any.
	 *
	 * @return string|null
	 */
	public function get_build_page_url(): ?string {
		$slug = $this->get_default_creatable_post_type();
		if ( null === $slug ) {
			return null;
		}
		return add_query_arg( 'post_type', $slug, admin_url( 'post-new.php' ) );
	}
}
