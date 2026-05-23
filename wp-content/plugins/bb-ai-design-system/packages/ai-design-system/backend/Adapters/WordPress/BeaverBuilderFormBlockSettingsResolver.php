<?php

namespace FL\DesignSystem\Adapters\WordPress;

use FL\DesignSystem\Contracts\FormBlockSettingsResolverInterface;
use FL\DesignSystem\Services\BindingsNormalizer;

/**
 * Resolve a DS block's saved settings from Beaver Builder layout data.
 *
 * Reads `_fl_builder_data` postmeta via FLBuilderModel for the post id
 * carried on the submission. Each BB layout node stores its DS payload
 * in `ds_block_data.settings`.
 *
 * Requires `post_id` in context. Without it, resolution returns null —
 * the frontend always injects `data-fl-block-post-id` when the render
 * occurred inside the loop, so a missing post_id is a genuine error
 * rather than something to work around by scanning.
 */
class BeaverBuilderFormBlockSettingsResolver implements FormBlockSettingsResolverInterface {

	/**
	 * Resolve the saved settings array for a block id.
	 *
	 * @param  string $block_id BB node identifier.
	 * @param  array  $context  Expected to contain `post_id`.
	 * @return array|null
	 */
	public function resolve( string $block_id, array $context = [] ): ?array {
		if ( '' === $block_id ) {
			return null;
		}

		if ( ! class_exists( '\FLBuilderModel' ) ) {
			return null;
		}

		$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;
		if ( $post_id <= 0 ) {
			return null;
		}

		$data = \FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( ! is_array( $data ) || empty( $data[ $block_id ] ) ) {
			$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		}
		if ( ! is_array( $data ) || empty( $data[ $block_id ] ) ) {
			return null;
		}

		return $this->extract_ds_settings( $data[ $block_id ] );
	}

	/**
	 * Extract the settings array from a BB module's ds_block_data meta.
	 *
	 * `$raw` may arrive as a JSON string (freshly saved via a layout
	 * template path) or as a nested stdClass (rehydrated from BB's
	 * layout data after save+reload). A shallow (array) cast would
	 * leave nested objects as stdClass, so `settings` would fail the
	 * is_array() check below. Round-trip through JSON to force deep
	 * conversion.
	 *
	 * @param  object $module BB layout node.
	 * @return array|null
	 */
	private function extract_ds_settings( object $module ): ?array {
		$raw = $module->settings->ds_block_data ?? null;

		if ( empty( $raw ) ) {
			return null;
		}

		if ( is_string( $raw ) ) {
			$data = json_decode( $raw, true );
		} else {
			$json = wp_json_encode( $raw );
			$data = false !== $json ? json_decode( $json, true ) : null;
		}

		if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return null;
		}

		// Run the bindings normalizer so any legacy object-shape data-source
		// values are split out into a sibling `bindings` slot. Form-action
		// lookup on the returned settings always sees the new shape: a
		// repeater settings value is either an array of items or absent —
		// never the legacy object whose `actions` would live one level too
		// deep.
		$data = ( new BindingsNormalizer() )->normalize( $data );

		return $data['settings'];
	}
}
