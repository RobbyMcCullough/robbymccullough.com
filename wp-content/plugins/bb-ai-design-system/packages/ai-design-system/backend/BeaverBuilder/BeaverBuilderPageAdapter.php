<?php

namespace FL\DesignSystem\BeaverBuilder;

use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\Services\BlockService;
use FL\DesignSystem\Services\Parser\AnnotationParser;
use FL\DesignSystem\Settings\SettingsMerger;

/**
 * Beaver Builder implementation of PageEditorAdapterInterface.
 *
 * Bridges the design system's section model with BB's layout data storage.
 * DS blocks are exported as editable sections; native BB modules appear as
 * opaque preserved markers.
 */
class BeaverBuilderPageAdapter implements PageEditorAdapterInterface {

	private LayoutManager $layout;
	private string $module_namespace;
	private BlockService $block_service;

	/**
	 * @param LayoutManager $layout           Layout manager for BB operations.
	 * @param string        $module_namespace Module namespace prefix (e.g. 'ds').
	 */
	public function __construct( LayoutManager $layout, string $module_namespace = 'ds' ) {
		$this->layout           = $layout;
		$this->module_namespace = $module_namespace;
		$this->block_service    = new BlockService( $module_namespace );
	}

	/**
	 * Import sections into BB layout storage with node ID reconciliation.
	 *
	 * Handles three cases per incoming item:
	 * - Preserved markers: skip (the block stays as-is in the layout)
	 * - DS sections with a matching node_id: update existing module in place
	 * - DS sections without a node_id or unmatched: create a new module
	 *
	 * DS modules present in the layout but absent from the incoming sections
	 * are deleted. Positions are updated based on document order.
	 *
	 * @param int   $post_id  WordPress post ID.
	 * @param array $sections Array of section arrays and preserved markers.
	 * @return array Array of created/updated node IDs.
	 */
	public function import_sections( int $post_id, array $sections ): array {
		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$module_type     = $this->module_namespace . '-block';
		$node_ids        = [];
		$seen_ds_node_ids = [];
		$position        = 0;

		// Collect existing DS module node IDs for deletion tracking.
		$existing_ds_ids = [];
		foreach ( $data as $node ) {
			if ( ( $node->type ?? '' ) !== 'module' ) {
				continue;
			}
			$type = $node->settings->type ?? '';
			if ( str_starts_with( $type, $this->module_namespace . '-' ) ) {
				$existing_ds_ids[] = $node->node;
			}
		}

		foreach ( $sections as $section ) {
			// Preserved markers: update position of the referenced node but don't modify it.
			if ( ( $section['type'] ?? '' ) === 'preserved' ) {
				$ref_node_id = $section['node_id'] ?? '';
				if ( '' !== $ref_node_id && isset( $data[ $ref_node_id ] ) ) {
					// Update position of the top-level node.
					$top_node = $this->find_top_level_node( $data, $ref_node_id );
					if ( $top_node ) {
						$data[ $top_node->node ]->position = $position;
					}
				}
				$position++;
				continue;
			}

			$incoming_node_id = $section['node_id'] ?? '';

			// Check if this is an update to an existing DS module.
			if ( '' !== $incoming_node_id && isset( $data[ $incoming_node_id ] ) ) {
				$existing_type = $data[ $incoming_node_id ]->settings->type ?? '';
				if ( str_starts_with( $existing_type, $this->module_namespace . '-' ) ) {
					// Update existing module in place.
					$parsed = AnnotationParser::parse( $section['html'] );

					$module_data = [
						'label'    => $section['label'] ?? '',
						'template' => $parsed['template'],
						'css'      => $section['css'] ?? '',
						'js'       => $section['js'] ?? '',
						'settings' => $parsed['settings'],
					];

					$data = $this->layout->update_module( $data, $incoming_node_id, $module_data );

					if ( ! empty( $section['label'] ) ) {
						$data[ $incoming_node_id ]->settings->node_label = $section['label'];
					}

					// Update position of the top-level ancestor.
					$top_node = $this->find_top_level_node( $data, $incoming_node_id );
					if ( $top_node ) {
						$data[ $top_node->node ]->position = $position;
					}

					$seen_ds_node_ids[] = $incoming_node_id;
					$node_ids[]         = $incoming_node_id;
					$position++;
					continue;
				}
			}

			// Create a new module.
			$parsed = AnnotationParser::parse( $section['html'] );

			$module_data = [
				'label'    => $section['label'] ?? '',
				'template' => $parsed['template'],
				'css'      => $section['css'] ?? '',
				'js'       => $section['js'] ?? '',
				'settings' => $parsed['settings'],
			];

			$result = $this->layout->create_module( $data, $module_data, $position, $module_type, null );
			$data   = $result['data'];

			$module_id = $result['module_id'];
			if ( ! empty( $section['label'] ) && isset( $data[ $module_id ] ) ) {
				$data[ $module_id ]->settings->node_label = $section['label'];
			}

			$node_ids[] = $module_id;
			$position++;
		}

		// Delete DS modules that were in the layout but not in the incoming sections.
		$ds_to_delete = array_diff( $existing_ds_ids, $seen_ds_node_ids );
		foreach ( $ds_to_delete as $del_id ) {
			if ( isset( $data[ $del_id ] ) ) {
				$data = $this->layout->delete_module( $data, $del_id );
			}
		}

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );

		// Also publish the layout so BB's editor can read it immediately.
		// Without this, pages created outside the editor (MCP, REST) have
		// empty published layout data, causing empty code tabs and forms.
		\FLBuilderModel::update_layout_data( $data, 'published', $post_id );

		return $node_ids;
	}

	/**
	 * Export all top-level items from a BB layout.
	 *
	 * DS blocks are returned as editable sections. Native BB modules and
	 * rows containing non-DS content are returned as preserved markers.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array Array of section arrays and preserved markers.
	 */
	public function export_sections( int $post_id ): array {
		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) || empty( $data ) ) {
			$data = \FLBuilderModel::get_layout_data( 'published', $post_id );
		}
		if ( ! is_array( $data ) || empty( $data ) ) {
			return [];
		}

		// Collect top-level nodes sorted by position.
		$top_level = [];
		foreach ( $data as $node ) {
			if ( empty( $node->parent ) ) {
				$top_level[] = $node;
			}
		}

		usort( $top_level, function ( $a, $b ) {
			return ( $a->position ?? 0 ) - ( $b->position ?? 0 );
		} );

		$sections = [];

		foreach ( $top_level as $row ) {
			$module = $this->layout->find_module_in_row( $data, $row->node );
			if ( ! $module ) {
				// Row with no module (unusual) — emit as preserved.
				$sections[] = [
					'type'           => 'preserved',
					'preserved_type' => 'bb',
					'node_id'        => $row->node,
					'module_type'    => $row->type ?? 'row',
					'label'          => $row->settings->node_label ?? 'Row',
				];
				continue;
			}

			$module_type = $module->settings->type ?? '';

			if ( str_starts_with( $module_type, $this->module_namespace . '-' ) ) {
				// DS block — export as editable section.
				$module_data = $this->layout->get_module_data( $module );
				if ( empty( $module_data['template'] ) && empty( $module_data['label'] ) ) {
					continue;
				}

				$sections[] = [
					'node_id'  => $module->node,
					'label'    => $module_data['label'] ?? ( $module->settings->node_label ?? '' ),
					'template' => $module_data['template'] ?? '',
					'css'      => $module_data['css'] ?? '',
					'js'       => $module_data['js'] ?? '',
					'settings' => $module_data['settings'] ?? [],
				];
			} else {
				// Native BB module — emit as preserved marker.
				$label = $module->settings->node_label ?? '';
				if ( '' === $label ) {
					// Use a readable version of the module type.
					$label = ucfirst( str_replace( [ '-', '_' ], ' ', $module_type ) );
				}

				$sections[] = [
					'type'           => 'preserved',
					'preserved_type' => 'bb',
					'node_id'        => $row->node,
					'module_type'    => $module_type,
					'label'          => $label,
				];
			}
		}

		return $sections;
	}

	/**
	 * Return a hierarchical outline of all blocks on a BB layout page.
	 *
	 * Builds a tree of rows -> columns -> modules, skipping BB's internal
	 * column-group nodes. Each node includes an ID, type, label, and a
	 * children array. Capability info per block type is returned by the
	 * get-page-blocks ability, not the outline.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array Array of row descriptor arrays with nested children.
	 */
	public function get_outline( int $post_id ): array {
		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) || empty( $data ) ) {
			$data = \FLBuilderModel::get_layout_data( 'published', $post_id );
		}
		if ( ! is_array( $data ) || empty( $data ) ) {
			return [];
		}

		// Index children by parent for efficient tree walking.
		$children_by_parent = [];
		foreach ( $data as $node ) {
			$parent = $node->parent ?? '';
			if ( '' !== $parent ) {
				$children_by_parent[ $parent ][] = $node;
			}
		}

		// Sort each parent's children by position.
		foreach ( $children_by_parent as &$children ) {
			usort( $children, function ( $a, $b ) {
				return ( $a->position ?? 0 ) - ( $b->position ?? 0 );
			} );
		}
		unset( $children );

		// Collect top-level rows sorted by position.
		$top_level = [];
		foreach ( $data as $node ) {
			if ( empty( $node->parent ) ) {
				$top_level[] = $node;
			}
		}

		usort( $top_level, function ( $a, $b ) {
			return ( $a->position ?? 0 ) - ( $b->position ?? 0 );
		} );

		$rows = [];

		foreach ( $top_level as $row ) {
			$rows[] = $this->build_outline_node( $data, $row, $children_by_parent );
		}

		return $rows;
	}

	/**
	 * Build an outline descriptor for a single layout node and its children.
	 *
	 * Column-group nodes are transparent: their children (columns) are promoted
	 * to be direct children of the parent row.
	 *
	 * @param array  $data               Full layout data.
	 * @param object $node               The layout node.
	 * @param array  $children_by_parent Children indexed by parent node ID.
	 * @return array Outline descriptor.
	 */
	private function build_outline_node( array $data, object $node, array $children_by_parent ): array {
		$node_type = $node->type ?? '';
		$children  = $children_by_parent[ $node->node ] ?? [];

		if ( 'module' === $node_type ) {
			return $this->build_module_outline( $data, $node, $children_by_parent );
		}

		if ( 'row' === $node_type ) {
			$label = $node->settings->node_label ?? '';
			if ( '' === $label ) {
				$label = 'Row ' . ( ( $node->position ?? 0 ) + 1 );
			}

			// Collect columns from column-groups (skip the column-group level).
			$child_descriptors = [];
			foreach ( $children as $child ) {
				$child_type = $child->type ?? '';
				if ( 'column-group' === $child_type ) {
					// Promote column-group's children (columns) directly.
					$grandchildren = $children_by_parent[ $child->node ] ?? [];
					foreach ( $grandchildren as $gc ) {
						$child_descriptors[] = $this->build_outline_node( $data, $gc, $children_by_parent );
					}
				} else {
					$child_descriptors[] = $this->build_outline_node( $data, $child, $children_by_parent );
				}
			}

			return [
				'node_id'   => $node->node,
				'type'      => 'row',
				'node_type' => 'row',
				'label'     => $label,
				'children'  => $child_descriptors,
			];
		}

		if ( 'column' === $node_type ) {
			$label = $node->settings->node_label ?? '';
			if ( '' === $label ) {
				$label = 'Column ' . ( ( $node->position ?? 0 ) + 1 );
			}

			$child_descriptors = [];
			foreach ( $children as $child ) {
				$child_descriptors[] = $this->build_outline_node( $data, $child, $children_by_parent );
			}

			return [
				'node_id'   => $node->node,
				'type'      => 'column',
				'node_type' => 'column',
				'label'     => $label,
				'children'  => $child_descriptors,
			];
		}

		// Fallback for unknown node types — treat as opaque container.
		$child_descriptors = [];
		foreach ( $children as $child ) {
			$child_descriptors[] = $this->build_outline_node( $data, $child, $children_by_parent );
		}

		return [
			'node_id'   => $node->node,
			'type'      => $node_type,
			'node_type' => $node_type,
			'label'     => $node->settings->node_label ?? $node_type,
			'children'  => $child_descriptors,
		];
	}

	/**
	 * Build an outline descriptor for a module node.
	 *
	 * Container modules (e.g., Box) can have child nodes (their own
	 * row/column-group/column/module subtree). This method recurses
	 * into those children the same way row and column nodes do.
	 *
	 * @param array  $data               Full layout data.
	 * @param object $node               Module layout node.
	 * @param array  $children_by_parent Children indexed by parent node ID.
	 * @return array Module outline descriptor.
	 */
	private function build_module_outline( array $data, object $node, array $children_by_parent ): array {
		$block_type  = $this->block_service->detect_block_type( $node );
		$module_type = $node->settings->type ?? '';

		$label = $node->settings->node_label ?? '';
		if ( '' === $label ) {
			$label = ucfirst( str_replace( [ '-', '_' ], ' ', $module_type ) );
		}

		// Container modules can have child nodes (rows inside the module).
		$children        = $children_by_parent[ $node->node ] ?? [];
		$child_descriptors = [];
		foreach ( $children as $child ) {
			$child_descriptors[] = $this->build_outline_node( $data, $child, $children_by_parent );
		}

		return [
			'node_id'     => $node->node,
			'type'        => $block_type,
			'module_type' => $module_type,
			'label'       => $label,
			'children'    => $child_descriptors,
		];
	}

	/**
	 * Remove only design system blocks from a post's BB layout.
	 *
	 * Preserves all non-DS content (native BB modules, rows, etc.).
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public function clear_layout( int $post_id ): void {
		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return;
		}

		// Collect DS module node IDs.
		$ds_module_ids = [];
		foreach ( $data as $node ) {
			if ( ( $node->type ?? '' ) !== 'module' ) {
				continue;
			}
			$module_type = $node->settings->type ?? '';
			if ( str_starts_with( $module_type, $this->module_namespace . '-' ) ) {
				$ds_module_ids[] = $node->node;
			}
		}

		// Delete each DS module (LayoutManager handles wrapper cleanup).
		foreach ( $ds_module_ids as $node_id ) {
			if ( isset( $data[ $node_id ] ) ) {
				$data = $this->layout->delete_module( $data, $node_id );
			}
		}

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );
		\FLBuilderModel::update_layout_data( $data, 'published', $post_id );
	}

	/**
	 * Export a single section by its BB node ID.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $node_id BB module node identifier.
	 * @return array|null Section data or null if not found.
	 */
	public function export_section( int $post_id, string $node_id ): ?array {
		$data = \FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( ! is_array( $data ) || empty( $data ) ) {
			$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		}
		if ( ! is_array( $data ) || empty( $data ) ) {
			return null;
		}

		$module = $data[ $node_id ] ?? null;
		if ( ! $module || ( $module->type ?? '' ) !== 'module' ) {
			return null;
		}

		$module_type = $module->settings->type ?? '';
		if ( ! str_starts_with( $module_type, $this->module_namespace . '-' ) ) {
			return null;
		}

		$module_data = $this->layout->get_module_data( $module );

		return [
			'node_id'  => $module->node,
			'label'    => $module_data['label'] ?? ( $module->settings->node_label ?? '' ),
			'template' => $module_data['template'] ?? '',
			'css'      => $module_data['css'] ?? '',
			'js'       => $module_data['js'] ?? '',
			'settings' => $module_data['settings'] ?? [],
		];
	}

	/**
	 * Update a single section's content in place by BB node ID.
	 *
	 * Only the fields present in $updates are changed; omitted fields
	 * keep their current values.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $node_id BB module node identifier.
	 * @param array  $updates Partial section data (html, css, js).
	 * @return true|\WP_Error True on success, WP_Error if not found.
	 */
	public function update_section( int $post_id, string $node_id, array $updates ): true|\WP_Error {
		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return new \WP_Error( 'node_not_found', 'No DS block found with the given node_id.', [ 'status' => 404 ] );
		}

		$module = $data[ $node_id ] ?? null;
		if ( ! $module || ( $module->type ?? '' ) !== 'module' ) {
			return new \WP_Error( 'node_not_found', 'No DS block found with the given node_id.', [ 'status' => 404 ] );
		}

		$module_type = $module->settings->type ?? '';
		if ( ! str_starts_with( $module_type, $this->module_namespace . '-' ) ) {
			return new \WP_Error( 'node_not_found', 'No DS block found with the given node_id.', [ 'status' => 404 ] );
		}

		// Get current module data to merge with updates.
		$current = $this->layout->get_module_data( $module );

		$module_data = [
			'label' => $current['label'] ?? ( $module->settings->node_label ?? '' ),
			'css'   => array_key_exists( 'css', $updates ) ? $updates['css'] : ( $current['css'] ?? '' ),
			'js'    => array_key_exists( 'js', $updates ) ? $updates['js'] : ( $current['js'] ?? '' ),
		];

		// Handle HTML/template: if html is provided, parse it for template + settings.
		// If only settings is provided (no html), merge into existing settings.
		// Otherwise keep existing values.
		if ( array_key_exists( 'html', $updates ) ) {
			$parsed                    = AnnotationParser::parse( $updates['html'] );
			$module_data['template']   = $parsed['template'];
			$module_data['settings']   = $parsed['settings'];
		} elseif ( array_key_exists( 'settings', $updates ) && is_array( $updates['settings'] ) ) {
			$module_data['template'] = $current['template'] ?? '';
			$existing_settings       = $current['settings'] ?? [];
			$module_data['settings'] = SettingsMerger::merge( $existing_settings, $updates['settings'] );
		} else {
			$module_data['template'] = $current['template'] ?? '';
			$module_data['settings'] = $current['settings'] ?? [];
		}

		$data = $this->layout->update_module( $data, $node_id, $module_data );

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );
		\FLBuilderModel::update_layout_data( $data, 'published', $post_id );

		return true;
	}

	/**
	 * Add a single section to the BB layout at a given position.
	 *
	 * @param int    $post_id  WordPress post ID.
	 * @param array  $section  Section data (label, template, css, js, settings).
	 * @param string $position Position descriptor ("first", "last", "before:{nodeId}", "after:{nodeId}").
	 * @return string|\WP_Error New node ID, or WP_Error on failure.
	 */
	public function add_section( int $post_id, array $section, string $position = 'last' ): string|\WP_Error {
		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$module_type       = $this->module_namespace . '-block';
		$resolved_position = $this->layout->resolve_position( $data, $position, null );

		$module_data = [
			'label'    => $section['label'] ?? '',
			'template' => $section['template'] ?? '',
			'css'      => $section['css'] ?? '',
			'js'       => $section['js'] ?? '',
			'settings' => $section['settings'] ?? [],
		];

		$result    = $this->layout->create_module( $data, $module_data, $resolved_position, $module_type, null );
		$data      = $result['data'];
		$module_id = $result['module_id'];

		if ( ! empty( $section['label'] ) && isset( $data[ $module_id ] ) ) {
			$data[ $module_id ]->settings->node_label = $section['label'];
		}

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );
		\FLBuilderModel::update_layout_data( $data, 'published', $post_id );

		return $module_id;
	}

	/**
	 * Remove a single DS section from the BB layout.
	 *
	 * Removes a module from the layout. Both DS and native BB modules
	 * are rejected to prevent accidental removal of non-DS content.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $node_id BB module node identifier.
	 * @return true|\WP_Error True on success, WP_Error if not found or not a DS block.
	 */
	public function remove_section( int $post_id, string $node_id ): true|\WP_Error {
		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) || ! isset( $data[ $node_id ] ) ) {
			return new \WP_Error( 'node_not_found', 'No block found with the given node_id.', [ 'status' => 404 ] );
		}

		$module = $data[ $node_id ];
		if ( ( $module->type ?? '' ) !== 'module' ) {
			return new \WP_Error( 'not_a_module', 'The specified node is not a module.', [ 'status' => 400 ] );
		}

		$data = $this->layout->delete_module( $data, $node_id );

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );
		\FLBuilderModel::update_layout_data( $data, 'published', $post_id );

		return true;
	}

	/**
	 * Move a section relative to another section in the BB layout.
	 *
	 * Delegates reordering to LayoutManager::move_node() which handles
	 * top-level row reordering, same-parent sibling reordering, and
	 * cross-container rejection.
	 *
	 * @param int    $post_id        WordPress post ID.
	 * @param string $node_id        Block/node to move.
	 * @param string $target_node_id Reference block/node.
	 * @param string $position       "before" or "after".
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function move_section( int $post_id, string $node_id, string $target_node_id, string $position ): true|\WP_Error {
		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'empty_layout', 'No layout data found.', [ 'status' => 404 ] );
		}

		if ( ! isset( $data[ $node_id ] ) ) {
			return new \WP_Error( 'node_not_found', sprintf( 'Source node "%s" not found.', $node_id ), [ 'status' => 404 ] );
		}

		if ( ! isset( $data[ $target_node_id ] ) ) {
			return new \WP_Error( 'target_not_found', sprintf( 'Target node "%s" not found.', $target_node_id ), [ 'status' => 404 ] );
		}

		$result = $this->layout->move_node( $data, $node_id, $target_node_id, $position );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = $result;

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );
		\FLBuilderModel::update_layout_data( $data, 'published', $post_id );

		return true;
	}

	/**
	 * Find the top-level ancestor node for a given node ID.
	 *
	 * @param array  $data    Layout data.
	 * @param string $node_id Node ID to trace up from.
	 * @return object|null The top-level node.
	 */
	private function find_top_level_node( array $data, string $node_id ) {
		$current = $data[ $node_id ] ?? null;
		while ( $current && ! empty( $current->parent ) ) {
			$current = $data[ $current->parent ] ?? null;
		}
		return $current;
	}
}
