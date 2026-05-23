<?php

namespace FL\DesignSystem\DataSources;

/**
 * Overlay-time resolver for the `bindings` slot on a DS block node.
 *
 * Walks the bindings map and, for each data-source binding, resolves the
 * configured source through {@see DataSourceRegistry} and replaces the
 * matching `settings[<key>]` value with the resolved items array. The
 * settings array carried in the node remains the placeholder content;
 * this resolver overrides it at render time only when a binding asks
 * for it.
 *
 * Errors at resolution time are intentionally non-fatal: an unregistered
 * source, a throwing resolver callback, or empty results all leave
 * `settings[<key>]` as-is so the manual placeholder still renders.
 *
 * The bindings array itself is never mutated.
 */
class DataSourceResolver {

	/**
	 * Apply bindings overlay to a settings array.
	 *
	 * @param array $settings   Resolved settings array (defaults merged with saved).
	 * @param array $bindings   Bindings map keyed by settings field name.
	 * @param array $form_nodes The form configuration tree (reserved for flat-field bindings).
	 * @param array $context    Render context (e.g., ['post_id' => 123]).
	 * @return array Settings array with data-source-driven keys overlaid by resolved items.
	 */
	public static function resolve( array $settings, array $bindings, array $form_nodes = [], array $context = [] ): array {
		if ( empty( $bindings ) ) {
			return $settings;
		}

		foreach ( $bindings as $key => $binding ) {
			if ( ! is_array( $binding ) ) {
				continue;
			}

			// Data-source binding (drives a repeater).
			if ( isset( $binding['source'] ) && is_string( $binding['source'] ) && '' !== $binding['source'] ) {
				$resolved = self::resolve_data_source_binding( $binding, $context );
				if ( null !== $resolved ) {
					$settings[ $key ] = $resolved;
				}
				continue;
			}

			// TODO(flat-field-bindings): when `connection` (singular) lands, look
			// up the connection target via the form node tree + context and
			// overwrite the scalar settings value here. No-op for now so the
			// existing repeater flow stays the only resolved path.
			if ( isset( $binding['connection'] ) ) {
				continue;
			}
		}

		return $settings;
	}

	/**
	 * Resolve a single data-source binding into its merged items array.
	 *
	 * Returns null when resolution failed (unregistered source, thrown
	 * resolver, empty result) so the caller can leave the existing
	 * placeholder content in place.
	 *
	 * @param array $binding The binding spec: { source, config, connections, defaults }.
	 * @param array $context Render context.
	 * @return array|null Resolved items array, or null when the binding could not be resolved.
	 */
	private static function resolve_data_source_binding( array $binding, array $context ): ?array {
		$source      = $binding['source'] ?? '';
		$config      = self::deep_to_array( $binding['config'] ?? [] );
		$connections = self::deep_to_array( $binding['connections'] ?? [] );
		$defaults    = self::deep_to_array( $binding['defaults'] ?? [] );

		if ( ! is_array( $config ) ) {
			$config = [];
		}
		if ( ! is_array( $connections ) ) {
			$connections = [];
		}
		if ( ! is_array( $defaults ) ) {
			$defaults = [];
		}

		if ( ! DataSourceRegistry::has( $source ) ) {
			self::log_debug( sprintf( 'Unregistered data source "%s"; leaving placeholder in place.', $source ) );
			return null;
		}

		try {
			$raw_items = DataSourceRegistry::resolve( $source, $config, $context );
		} catch ( \Throwable $e ) {
			self::log_debug( sprintf( 'Data source "%s" threw during resolution: %s', $source, $e->getMessage() ) );
			return null;
		}

		if ( ! is_array( $raw_items ) || empty( $raw_items ) ) {
			return null;
		}

		$items = [];
		foreach ( $raw_items as $raw_item ) {
			$raw_item = self::deep_to_array( $raw_item );
			if ( ! is_array( $raw_item ) ) {
				continue;
			}

			$item = $defaults;

			foreach ( $connections as $field_key => $property_key ) {
				if ( ! is_string( $field_key ) || ! is_string( $property_key ) ) {
					continue;
				}
				if ( array_key_exists( $property_key, $raw_item ) ) {
					$item[ $field_key ] = $raw_item[ $property_key ];
				}
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Recursively convert stdClass objects to arrays.
	 *
	 * @param  mixed $data The data to convert.
	 * @return mixed Converted data.
	 */
	private static function deep_to_array( $data ) {
		if ( $data instanceof \stdClass ) {
			$data = (array) $data;
		}
		if ( is_array( $data ) ) {
			return array_map( [ self::class, 'deep_to_array' ], $data );
		}
		return $data;
	}

	/**
	 * Emit a debug-only log line from the resolver hot path.
	 *
	 * Failures here are routine (unregistered source on first render of a
	 * cloned block, transient query errors, etc.) so we keep them silent
	 * outside `WP_DEBUG`.
	 *
	 * @param string $message Human-readable message.
	 */
	private static function log_debug( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		if ( ! function_exists( 'error_log' ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[FL\\DesignSystem\\DataSourceResolver] ' . $message );
	}
}
