<?php

namespace FL\DesignSystem\Adapters\WordPress;

use FL\DesignSystem\BlockEditor\ContentParser;
use FL\DesignSystem\Contracts\FormBlockSettingsResolverInterface;
use FL\DesignSystem\Services\BindingsNormalizer;
use FL\DesignSystem\Settings\SettingsResolver;

/**
 * Resolve a custom block's saved settings from Gutenberg post_content.
 *
 * Parses `post_content` via `parse_blocks()` and walks (recursively,
 * since custom blocks can be nested in container blocks) for an
 * `fl-ds/custom` block whose `attrs.blockId` matches the submitted
 * block identifier.
 *
 * The submission's block_id is `fl-ds-custom-{blockId}` — it matches
 * the scope class generated at render time. This resolver strips the
 * prefix and compares against the block's `blockId` attribute.
 *
 * Matches the shape `CustomBlockRenderer::render()` produces at render
 * time: merges defaults resolved from the form schema (parsed out of
 * innerHTML) with saved attribute overrides, so the submission flow
 * sees the same effective settings the render did.
 */
class BlockEditorFormBlockSettingsResolver implements FormBlockSettingsResolverInterface {

	private const BLOCK_NAME = 'fl-ds/custom';
	private const ID_PREFIX  = 'fl-ds-custom-';

	/**
	 * Resolve the saved settings array for a block id.
	 *
	 * @param  string $block_id Submitted block identifier (`fl-ds-custom-{blockId}`).
	 * @param  array  $context  Expected to contain `post_id`.
	 * @return array|null
	 */
	public function resolve( string $block_id, array $context = [] ): ?array {
		if ( '' === $block_id || 0 !== strpos( $block_id, self::ID_PREFIX ) ) {
			return null;
		}

		$target_id = substr( $block_id, strlen( self::ID_PREFIX ) );
		if ( '' === $target_id ) {
			return null;
		}

		$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return null;
		}

		$block = $this->find_custom_block( parse_blocks( $post->post_content ), $target_id );
		if ( null === $block ) {
			return null;
		}

		return $this->resolve_block_settings( $block );
	}

	/**
	 * Walk a parsed blocks tree for a custom block matching blockId.
	 *
	 * @param  array  $blocks    Parsed blocks array from parse_blocks().
	 * @param  string $target_id blockId attribute to match.
	 * @return array|null Matched block, or null.
	 */
	private function find_custom_block( array $blocks, string $target_id ): ?array {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			if ( self::BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
				if ( ( $attrs['blockId'] ?? '' ) === $target_id ) {
					return $block;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$nested = $this->find_custom_block( $block['innerBlocks'], $target_id );
				if ( null !== $nested ) {
					return $nested;
				}
			}
		}

		return null;
	}

	/**
	 * Merge the block's form-schema defaults with its saved attribute overrides.
	 *
	 * Mirrors `CustomBlockRenderer::render()` so submission-time settings
	 * resolution matches render-time resolution.
	 *
	 * @param  array $block Matched custom block array.
	 * @return array|null Merged settings, or null if the block has no form schema.
	 */
	private function resolve_block_settings( array $block ): ?array {
		$inner_html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
		$parsed     = ContentParser::parse( $inner_html );
		$form       = is_array( $parsed['form'] ?? null ) ? $parsed['form'] : [];

		$attrs       = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : [];
		$saved       = is_array( $attrs['settings'] ?? null ) ? $attrs['settings'] : [];
		$saved_binds = is_array( $attrs['bindings'] ?? null ) ? $attrs['bindings'] : [];

		// Run the bindings normalizer so legacy object-shape data-source values
		// in `settings` are split out before merging with defaults. Form-action
		// lookup on the returned settings is always against the new shape.
		$normalized = ( new BindingsNormalizer() )->normalize(
			[
				'settings' => $saved,
				'bindings' => $saved_binds,
			]
		);

		$saved    = isset( $normalized['settings'] ) && is_array( $normalized['settings'] ) ? $normalized['settings'] : [];
		$defaults = SettingsResolver::resolve_defaults( $form );

		return array_merge( $defaults, $saved );
	}
}
