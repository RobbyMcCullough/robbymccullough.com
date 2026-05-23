<?php

namespace FL\DesignSystem\Mcp;

/**
 * Abstract base for MCP ability classes.
 *
 * Provides the default permission gate and the post-validation helper
 * shared across abilities that touch a specific post.
 *
 * Note: get_post_url is intentionally NOT here. It lives on
 * {@see \FL\DesignSystem\Mcp\Support\PageResolver} so service classes
 * (like PageGenerator) can use it without extending this base.
 */
abstract class BaseAbility implements AbilityInterface {

	public function permission(): bool {
		return Permissions::content_creator();
	}

	/**
	 * Validate that a post exists and the current user can edit it.
	 *
	 * @param  int $post_id Post ID.
	 * @return \WP_Error|null Null on success, WP_Error on failure.
	 */
	protected function validate_post( int $post_id ): ?\WP_Error {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'invalid_post',
				'Invalid or missing post ID.',
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'forbidden',
				'You do not have permission to edit this post.',
				[ 'status' => 403 ]
			);
		}

		return null;
	}
}
