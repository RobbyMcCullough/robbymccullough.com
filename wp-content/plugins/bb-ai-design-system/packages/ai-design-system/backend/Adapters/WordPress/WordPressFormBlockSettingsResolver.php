<?php

namespace FL\DesignSystem\Adapters\WordPress;

use FL\DesignSystem\Contracts\FormBlockSettingsResolverInterface;

/**
 * Route form-submission block lookups to the right storage backend.
 *
 * A DS block is rendered from exactly one source per request: either
 * a Beaver Builder layout or a Gutenberg post. This resolver picks
 * the matching specialist based on whether BB is enabled for the
 * submission's post.
 *
 * Requires `post_id` in context. The frontend runtime always includes
 * `data-fl-block-post-id` when the block was rendered inside the loop,
 * so a missing post_id is a genuine failure rather than a condition
 * to compensate for.
 */
class WordPressFormBlockSettingsResolver implements FormBlockSettingsResolverInterface {

	private FormBlockSettingsResolverInterface $bb;
	private FormBlockSettingsResolverInterface $block_editor;

	public function __construct(
		FormBlockSettingsResolverInterface $bb,
		FormBlockSettingsResolverInterface $block_editor
	) {
		$this->bb           = $bb;
		$this->block_editor = $block_editor;
	}

	/**
	 * Resolve the saved settings array for a block id.
	 *
	 * @param  string $block_id Block/node identifier.
	 * @param  array  $context  Expected to contain `post_id`.
	 * @return array|null
	 */
	public function resolve( string $block_id, array $context = [] ): ?array {
		if ( '' === $block_id ) {
			return null;
		}

		$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return null;
		}

		if ( $this->is_bb_page( $post_id ) ) {
			return $this->bb->resolve( $block_id, $context );
		}

		return $this->block_editor->resolve( $block_id, $context );
	}

	/**
	 * Whether Beaver Builder is enabled for a post.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	private function is_bb_page( int $post_id ): bool {
		return class_exists( '\FLBuilderModel' ) && \FLBuilderModel::is_builder_enabled( $post_id );
	}
}
