<?php

namespace FL\DesignSystem\Services;

/**
 * Read-time normalizer that splits the legacy "object-shape" data-source value
 * out of `settings` and into a sibling `bindings` slot on the block data.
 *
 * The shape contract for `bindings` is documented in
 * {@see \FL\DesignSystem\Services\BindingsShape}; both this normalizer and the
 * JS-side `frontend/src/core/data-sources/bindings-normalizer.js` consume it.
 *
 * # Bindings shape
 *
 * `bindings` is a sibling of `settings` on the node. Each entry keys off a
 * settings field name. Two variants are supported:
 *
 *   1. Data-source binding (drives a repeater from a registered source):
 *        bindings[<key>] = [
 *          'source'      => string,
 *          'config'      => array,
 *          'connections' => array,  // per-item field key -> source property name
 *          'defaults'    => array,  // per-item field key -> fallback literal
 *        ]
 *      The matching settings[<key>] is always an array of items used as the
 *      manual placeholder content.
 *
 *   2. Flat-field binding (binds a single setting value to a property):
 *        bindings[<key>] = [ 'connection' => string ]
 *      Reserved for future Themer-style flat connections; the resolver
 *      branches on `source` vs `connection`.
 *
 * # Legacy shape this normalizer migrates
 *
 *   settings.features = [
 *     'source'      => 'wp-post-query',
 *     'config'      => [...],
 *     'connections' => [...],
 *     'defaults'    => [...],
 *     'placeholder' => [...],   // the manual items (becomes settings.<key>)
 *   ]
 *
 * After normalization:
 *
 *   settings.features = [...placeholder items]
 *   bindings.features = [ source, config, connections, defaults ]
 *
 * The normalizer is one-way (legacy in -> new shape out) and idempotent:
 * running it on already-normalized block data is a no-op. The `placeholder`
 * key only exists on the legacy in-settings object; the new shape never
 * carries it because `settings[<key>]` IS the placeholder.
 *
 * # Self-healing
 *
 * Bindings can survive an HTML rewrite even when the parser renames the
 * repeater key. The reconciler (the JS side, since rewrites originate from
 * the agent in the browser) walks the binding map and:
 *   - Keeps a binding whose key still maps to an array in the new settings
 *     (pruning `connections`/`defaults` entries whose field key has vanished
 *     from every item).
 *   - Attempts a single-candidate fuzzy re-match for a vanished key: build
 *     `required = keys(connections) ∪ keys(defaults)` and look for an
 *     unclaimed array-valued key in the new settings whose union of item
 *     fields is a superset of `required`. Exactly one match -> re-key.
 *     Zero or two-plus matches -> drop with a logged notice.
 *
 * # Live consumers
 *
 * - `frontend/src/core/services/reconcile-bindings.js` — write-path self-heal.
 * - {@see \FL\DesignSystem\DataSources\DataSourceResolver} — render-time overlay.
 */
class BindingsNormalizer {

	/**
	 * Keys that must all be present in a settings value for it to be treated
	 * as a legacy data-source object. The presence of `source` alone is the
	 * primary discriminator; `connections` is required to disambiguate from
	 * future flat-field connections (which carry `connection`, singular) and
	 * arbitrary user-authored objects that happen to have a `source` key.
	 */
	private const LEGACY_DS_REQUIRED_KEYS = [ 'source', 'connections' ];

	/**
	 * Keys that move from the legacy settings object into `bindings[<key>]`.
	 */
	private const BINDING_KEYS = [ 'source', 'config', 'connections', 'defaults' ];

	/**
	 * Normalize a single `ds_block_data` array.
	 *
	 * @param array $block_data Decoded `ds_block_data` payload.
	 * @return array Normalized block data (settings + bindings split).
	 */
	public function normalize( array $block_data ): array {
		$settings = $block_data['settings'] ?? null;
		if ( ! is_array( $settings ) ) {
			return $block_data;
		}

		$bindings = isset( $block_data['bindings'] ) && is_array( $block_data['bindings'] )
			? $block_data['bindings']
			: [];

		foreach ( $settings as $key => $value ) {
			if ( ! $this->is_legacy_data_source_value( $value ) ) {
				continue;
			}

			// Idempotency: if a binding for this key already exists, leave both
			// in place. The new shape is the source of truth once it's set.
			if ( array_key_exists( $key, $bindings ) ) {
				continue;
			}

			$binding = [];
			foreach ( self::BINDING_KEYS as $binding_key ) {
				if ( array_key_exists( $binding_key, $value ) ) {
					$binding[ $binding_key ] = $value[ $binding_key ];
				}
			}

			$bindings[ $key ] = $binding;
			$placeholder      = $value['placeholder'] ?? [];
			$settings[ $key ] = is_array( $placeholder ) ? $placeholder : [];
		}

		$block_data['settings'] = $settings;

		if ( ! empty( $bindings ) ) {
			$block_data['bindings'] = $bindings;
		}

		return $block_data;
	}

	/**
	 * Whether a settings value matches the legacy data-source object shape.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	private function is_legacy_data_source_value( $value ): bool {
		if ( ! is_array( $value ) ) {
			return false;
		}

		// A list-shaped (sequential int-keyed) array is the new content shape.
		if ( $this->is_list( $value ) ) {
			return false;
		}

		foreach ( self::LEGACY_DS_REQUIRED_KEYS as $required ) {
			if ( ! array_key_exists( $required, $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Polyfill for array_is_list (PHP 8.1+); the plugin still supports
	 * earlier 8.x versions per its `Requires PHP` header.
	 *
	 * @param array $value
	 * @return bool
	 */
	private function is_list( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}
		$expected = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $expected ) {
				return false;
			}
			$expected++;
		}
		return true;
	}
}
