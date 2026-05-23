<?php

namespace FL\DesignSystem\Mcp\Support;

use FL\DesignSystem\Services\PostTypeAccess;

/**
 * Surface the set of post types the current user can create and
 * shape its description for ability schemas.
 *
 * Called both during ability registration (to populate the post_type
 * enum on generate-page and generate-style-guide) and during ability
 * execution (auto-correct branch in PageGenerator, list-post-types).
 * Cannot live as a private helper of any single ability.
 */
class PostTypeService {

	/**
	 * @return string[] Post type slugs the current user can create.
	 */
	public function get_creatable_post_type_slugs(): array {
		return ( new PostTypeAccess() )->get_creatable_post_type_slugs();
	}

	/**
	 * Build a human-readable description for the post_type schema property.
	 *
	 * Handles the case where no post types are available (e.g. on the
	 * login screen before a user is authenticated).
	 *
	 * @param string[] $post_types Creatable post type slugs.
	 */
	public function build_post_type_description( array $post_types ): string {
		if ( empty( $post_types ) ) {
			return 'WordPress post type.';
		}
		if ( count( $post_types ) === 1 ) {
			return 'WordPress post type. Must be: ' . $post_types[0] . '.';
		}
		$default = in_array( 'page', $post_types, true ) ? 'page' : $post_types[0];
		return 'WordPress post type. Default: ' . $default . '.';
	}
}
