<?php

namespace FL\DesignSystem\Settings;

use FL\DesignSystem\DataSources\DataSourceResolver;

/**
 * Resolves default settings from a block's form configuration.
 *
 * PHP port of the JS resolveSettings() function in services/form-tree.js.
 * Walks the form node tree and produces a flat { key: value } defaults array.
 */
class SettingsResolver {

	/**
	 * Container node types that are transparent (recursed into, not collected).
	 */
	private const CONTAINER_TYPES = [ 'tab', 'section' ];

	/**
	 * Find a field's options by key in a form configuration tree.
	 *
	 * Walks the form tree (recursing into containers and repeaters)
	 * looking for a field with the given key.
	 *
	 * @param  array  $form_nodes The form configuration tree.
	 * @param  string $key        The field key to find.
	 * @return array|null The field's options array (keys are option values), or null.
	 */
	public static function find_field_options( array $form_nodes, string $key ): ?array {
		foreach ( $form_nodes as $node ) {
			$node_key  = $node['key'] ?? '';
			$node_type = $node['type'] ?? '';

			if ( '' === $node_key ) {
				continue;
			}

			// Container types: recurse into children.
			if ( in_array( $node_type, self::CONTAINER_TYPES, true ) || 'repeater' === $node_type ) {
				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					$found = self::find_field_options( $node['children'], $key );
					if ( null !== $found ) {
						return $found;
					}
				}
				continue;
			}

			// Field node: check if key matches and has options.
			if ( $node_key === $key ) {
				if ( isset( $node['options'] ) && is_array( $node['options'] ) ) {
					return $node['options'];
				}
				return null;
			}
		}

		return null;
	}

	/**
	 * Merge saved settings with resolved defaults.
	 *
	 * Repeater values are always arrays now (the legacy object-shape lives
	 * in `bindings`, not `settings`, after read-time normalization), so this
	 * is a straight `array_merge`.
	 *
	 * @param  array $defaults Resolved defaults from resolve_defaults().
	 * @param  array $saved    Saved settings values.
	 * @return array Merged settings.
	 */
	public static function merge_with_defaults( array $defaults, array $saved ): array {
		return array_merge( $defaults, $saved );
	}

	/**
	 * Resolve a node's full render-time settings: defaults + saved + bindings overlay.
	 *
	 * Single entry point used by every render path. Callers pass the form
	 * configuration, the saved settings array (always-array repeater values),
	 * the sibling `bindings` map, and a render context. Data-source-driven
	 * repeater keys come back overlaid with resolved items; everything else
	 * is the defaults-merged saved value.
	 *
	 * @param array $form_nodes Form configuration tree.
	 * @param array $saved      Saved settings array.
	 * @param array $bindings   Sibling bindings map keyed by settings field name.
	 * @param array $context    Render context (e.g., ['post_id' => 123]).
	 * @return array Fully resolved settings ready for Mustache rendering.
	 */
	public static function resolve_for_render( array $form_nodes, array $saved, array $bindings = [], array $context = [] ): array {
		$defaults = ! empty( $form_nodes ) ? self::resolve_defaults( $form_nodes ) : [];
		$merged   = self::merge_with_defaults( $defaults, $saved );

		return DataSourceResolver::resolve( $merged, $bindings, $form_nodes, $context );
	}

	/**
	 * Resolve default values for all fields in a form configuration.
	 *
	 * @param  array $form_nodes The form configuration tree (array of nodes).
	 * @return array Flat associative array of { key => default_value }.
	 */
	public static function resolve_defaults( array $form_nodes ): array {
		if ( empty( $form_nodes ) ) {
			return [];
		}

		$settings = [];
		self::resolve_nodes( $form_nodes, $settings );
		return $settings;
	}

	/**
	 * Recursively resolve nodes into a target settings array.
	 *
	 * Tabs and sections are transparent containers -- recurse into children.
	 * Repeaters produce arrays of item objects.
	 * Field nodes produce scalar defaults.
	 *
	 * @param array $nodes     Array of form nodes.
	 * @param array &$settings Target settings array (modified by reference).
	 */
	private static function resolve_nodes( array $nodes, array &$settings ): void {
		foreach ( $nodes as $node ) {
			$type = $node['type'] ?? '';
			$key  = $node['key'] ?? '';

			if ( '' === $key ) {
				continue;
			}

			if ( 'repeater' === $type ) {
				$settings[ $key ] = self::resolve_repeater( $node );
				continue;
			}

			if ( in_array( $type, self::CONTAINER_TYPES, true ) ) {
				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					self::resolve_nodes( $node['children'], $settings );
				}
				continue;
			}

			// Field node -- use default or empty string.
			$settings[ $key ] = $node['default'] ?? '';
		}
	}

	/**
	 * Resolve a repeater node into an array of item objects.
	 *
	 * If defaultItems is provided, each item is merged with the item template
	 * (field defaults from children). Otherwise, generates defaultCount copies
	 * of the item template.
	 *
	 * @param  array $node The repeater node.
	 * @return array Array of item objects.
	 */
	private static function resolve_repeater( array $node ): array {
		$item_template = self::resolve_repeater_item( $node );
		$count         = $node['defaultCount'] ?? 1;

		if ( ! empty( $node['defaultItems'] ) && is_array( $node['defaultItems'] ) ) {
			return array_map(
				function ( $item ) use ( $item_template ) {
					return array_merge( $item_template, $item );
				},
				$node['defaultItems'],
			);
		}

		$items = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$items[] = $item_template;
		}
		return $items;
	}

	/**
	 * Build a flat item template from a repeater's children.
	 *
	 * Sections and tabs inside repeaters are transparent -- only field
	 * values are collected. Nested repeaters produce sub-arrays.
	 *
	 * @param  array $node The repeater node.
	 * @return array Associative array of { key => default_value }.
	 */
	private static function resolve_repeater_item( array $node ): array {
		$item = [];
		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			self::collect_item_fields( $node['children'], $item );
		}
		return $item;
	}

	/**
	 * Recursively collect field defaults from nodes into an item object.
	 *
	 * Containers (sections, tabs) are walked through. Nested repeaters
	 * produce sub-arrays via resolve_repeater().
	 *
	 * @param array $nodes Array of child nodes.
	 * @param array &$item Target item array (modified by reference).
	 */
	private static function collect_item_fields( array $nodes, array &$item ): void {
		foreach ( $nodes as $node ) {
			$type = $node['type'] ?? '';
			$key  = $node['key'] ?? '';

			if ( '' === $key ) {
				continue;
			}

			if ( 'repeater' === $type ) {
				$item[ $key ] = self::resolve_repeater( $node );
				continue;
			}

			if ( in_array( $type, self::CONTAINER_TYPES, true ) ) {
				if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
					self::collect_item_fields( $node['children'], $item );
				}
				continue;
			}

			$item[ $key ] = $node['default'] ?? '';
		}
	}
}
