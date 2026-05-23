<?php

namespace FL\DesignSystem\BeaverBuilder;

/**
 * Manages Beaver Builder layout tree operations for Custom Module nodes.
 *
 * Pure layout data manipulation — no REST handling or WordPress side effects.
 * Receives layout data arrays and returns modified versions.
 */
class LayoutManager {

	/**
	 * Create a module instance in the BB layout.
	 *
	 * DS blocks store their full data {label, template, css, js, settings}
	 * at the top level of ds_block_data.
	 *
	 * @param  array       $data        Current layout data.
	 * @param  array       $module_data Module data to store.
	 * @param  int         $position    Resolved numeric position index.
	 * @param  string      $module_type Module type slug (e.g. 'ds-hero', 'ds-abc123').
	 * @param  string|null $parent_id   Optional parent node ID for container insertion.
	 * @return array  Updated layout data with new module nodes added.
	 */
	public function create_module( array $data, array $module_data, int $position, string $module_type, ?string $parent_id = null ): array {
		// Shift existing siblings at or after the target position
		foreach ( $data as &$existing ) {
			$node_parent = $existing->parent ?? '';
			$matches     = $parent_id
				? ( $node_parent === $parent_id )
				: empty( $node_parent );
			if ( $matches && $existing->position >= $position ) {
				++$existing->position;
			}
		}
		unset( $existing );

		$module_id = \FLBuilderModel::generate_node_id();

		$slim_data = $this->build_slim_data( $module_data );

		$defaults = \FLBuilderModel::get_module_defaults( $module_type );
		$settings = (object) array_merge(
			(array) $defaults,
			array(
				'type'          => $module_type,
				'ds_block_data' => wp_json_encode( $slim_data ),
			)
		);

		$module           = new \StdClass();
		$module->node     = $module_id;
		$module->type     = 'module';
		$module->parent   = $parent_id;
		$module->position = $position;
		$module->settings = $settings;

		$data[ $module_id ] = $module;

		return array(
			'data'      => $data,
			'module_id' => $module_id,
		);
	}

	/**
	 * Update ds_block_data on an existing Custom Module node.
	 *
	 * @param  array  $data        Current layout data.
	 * @param  string $node_id     The node ID to update.
	 * @param  array  $module_data New module data fields.
	 * @return array  Updated layout data.
	 */
	public function update_module( array $data, string $node_id, array $module_data ): array {
		$node = $data[ $node_id ];

		// Get existing stored data
		$raw      = $node->settings->ds_block_data ?? '{}';
		$existing = is_string( $raw ) ? json_decode( $raw, true ) : (array) $raw;

		// Merge: incoming data overlays existing
		$merged = array_merge( $existing, $module_data );

		$slim = $this->build_slim_data( $merged );

		// Preserve existing settings if not in the update
		if ( ! isset( $module_data['settings'] ) && isset( $existing['settings'] ) ) {
			$slim['settings'] = $existing['settings'];
		}

		// Preserve existing bindings if not in the update; bindings live on
		// the node alongside settings and must round-trip across writes that
		// only touch other fields (e.g. css/js patches).
		if ( ! array_key_exists( 'bindings', $module_data ) && isset( $existing['bindings'] ) ) {
			$slim['bindings'] = $existing['bindings'];
		}

		$node->settings->ds_block_data = wp_json_encode( $slim );
		$data[ $node_id ]              = $node;

		return $data;
	}

	/**
	 * Build the slim data payload for ds_block_data storage.
	 *
	 * DS blocks store their full data at the top level: {label, template, css, js, settings}.
	 *
	 * @param  array $module_data Raw module data from the request.
	 * @return array Slim data for storage.
	 */
	private function build_slim_data( array $module_data ): array {
		$slim = array();
		foreach ( array( 'label', 'template', 'css', 'js' ) as $field ) {
			if ( array_key_exists( $field, $module_data ) ) {
				$slim[ $field ] = $module_data[ $field ];
			}
		}
		if ( ! empty( $module_data['settings'] ) ) {
			$slim['settings'] = $module_data['settings'];
		}
		// Bindings is a top-level slot on the node alongside settings; persist
		// it whenever the caller passes an array (including an empty array, so
		// callers can intentionally clear bindings).
		if ( array_key_exists( 'bindings', $module_data ) && is_array( $module_data['bindings'] ) ) {
			$slim['bindings'] = $module_data['bindings'];
		}

		return $slim;
	}

	/**
	 * Delete a module node from the layout.
	 *
	 * Top-level DS blocks (parent is null or parent is a row wrapper) delete the
	 * entire row tree since the row only exists to wrap the module. Modules inside
	 * containers (columns, box modules, etc.) delete only the module itself.
	 *
	 * @param  array  $data    Current layout data.
	 * @param  string $node_id The node ID to delete.
	 * @return array  Updated layout data.
	 */
	public function delete_module( array $data, string $node_id ): array {
		$node   = $data[ $node_id ] ?? null;
		$parent = $node ? ( $node->parent ?? '' ) : '';

		// Module inside a container — delete just the module and its descendants
		if ( ! empty( $parent ) ) {
			$parent_type = ( $data[ $parent ]->type ?? '' );

			// If parent is a row or column-group or column, check if this is a
			// top-level DS block wrapped in a row. Walk up: if the ancestor row
			// has no parent, it's a wrapper row — delete the whole tree.
			if ( in_array( $parent_type, array( 'row', 'column-group', 'column' ), true ) ) {
				$row = $this->find_ancestor_row( $data, $node_id );
				if ( $row && empty( $row->parent ) ) {
					// Top-level wrapper row — check if the module is the only
					// module inside this row tree. If so, delete the whole row.
					$modules_in_row = 0;
					foreach ( $data as $n ) {
						if ( ( $n->type ?? '' ) === 'module' && $n->node !== $node_id ) {
							$ancestor = $this->find_ancestor_row( $data, $n->node );
							if ( $ancestor && $ancestor->node === $row->node ) {
								++$modules_in_row;
							}
						}
					}

					if ( 0 === $modules_in_row ) {
						// Only module in the row — delete entire row tree
						foreach ( $this->collect_descendants( $data, $row->node ) as $id ) {
							unset( $data[ $id ] );
						}
						return $data;
					}
				}
			}

			// Parent is a container module or a column with siblings — delete just this node
			foreach ( $this->collect_descendants( $data, $node_id ) as $id ) {
				unset( $data[ $id ] );
			}
			// Note: collect_descendants includes the node itself
			return $data;
		}

		// Top-level module (no parent) — delete the node itself
		unset( $data[ $node_id ] );
		return $data;
	}

	/**
	 * Find the first module node inside a row's hierarchy.
	 *
	 * @param  array       $data   Layout data.
	 * @param  string      $row_id Row node ID.
	 * @return object|null The module node, or null.
	 */
	public function find_module_in_row( array $data, string $row_id ) {
		foreach ( $data as $node ) {
			if ( ( $node->type ?? '' ) !== 'module' ) {
				continue;
			}
			$ancestor = $this->find_ancestor_row( $data, $node->node );
			if ( $ancestor && $ancestor->node === $row_id ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Find the top-level ancestor row of a node by walking up the parent chain.
	 *
	 * @param  array       $data    Layout data.
	 * @param  string      $node_id Starting node ID.
	 * @return object|null The top-level row node, or null.
	 */
	public function find_ancestor_row( array $data, string $node_id ) {
		$current = $data[ $node_id ] ?? null;
		while ( $current && ! empty( $current->parent ) ) {
			$current = $data[ $current->parent ] ?? null;
		}
		return $current;
	}

	/**
	 * Collect all descendants of a node as a nested tree structure.
	 *
	 * Each entry contains the node object and a 'children' array of nested entries,
	 * sorted by position. Used for recursive layout overview responses.
	 *
	 * @param  array  $data      Layout data.
	 * @param  string $parent_id Parent node ID.
	 * @return array  Nested tree of [ 'node' => object, 'children' => array ].
	 */
	public function collect_descendant_tree( array $data, string $parent_id ): array {
		// Build parent → children index
		$children_map = array();
		foreach ( $data as $node ) {
			$parent = $node->parent ?? '';
			if ( '' !== $parent ) {
				$children_map[ $parent ][] = $node;
			}
		}

		return $this->build_tree_recursive( $children_map, $parent_id );
	}

	/**
	 * Recursively build a nested tree from a parent-children index.
	 *
	 * @param  array  $children_map Parent ID → child nodes index.
	 * @param  string $parent_id    Current parent node ID.
	 * @return array  Nested tree entries.
	 */
	private function build_tree_recursive( array $children_map, string $parent_id ): array {
		if ( ! isset( $children_map[ $parent_id ] ) ) {
			return array();
		}

		$children = $children_map[ $parent_id ];
		usort(
			$children,
			function ( $a, $b ) {
				return ( $a->position ?? 0 ) - ( $b->position ?? 0 );
			}
		);

		$tree = array();
		foreach ( $children as $child ) {
			$tree[] = array(
				'node'     => $child,
				'children' => $this->build_tree_recursive( $children_map, $child->node ),
			);
		}

		return $tree;
	}

	/**
	 * Collect all node IDs in a row's tree (the row itself + all descendants).
	 *
	 * Builds a parent-to-children index once, then performs a single DFS
	 * traversal instead of rescanning the full data array per tree level.
	 *
	 * @param  array    $data   Layout data.
	 * @param  string   $row_id Row node ID.
	 * @return string[] Array of node IDs to delete.
	 */
	public function collect_descendants( array $data, string $row_id ): array {
		// Build parent → children index
		$children = array();
		foreach ( $data as $node ) {
			$parent = $node->parent ?? '';
			if ( '' !== $parent ) {
				$children[ $parent ][] = $node->node;
			}
		}

		// DFS from the root
		$ids   = array();
		$stack = array( $row_id );
		while ( $stack ) {
			$id    = array_pop( $stack );
			$ids[] = $id;
			if ( isset( $children[ $id ] ) ) {
				foreach ( $children[ $id ] as $child_id ) {
					$stack[] = $child_id;
				}
			}
		}

		return $ids;
	}

	/**
	 * Find the ancestor row for a module, used for resolving positions.
	 *
	 * @param  array       $data   Layout data.
	 * @param  string      $ref_id Reference node ID.
	 * @return object|null The ancestor row, or null.
	 */
	public function find_row_for_module( array $data, string $ref_id ) {
		return $this->find_ancestor_row( $data, $ref_id );
	}

	/**
	 * Decode the ds_block_data JSON stored in a Custom Module node's settings.
	 *
	 * @param  object $node Layout node.
	 * @return array  Decoded block data.
	 */
	public function get_module_data( $node ): array {
		$raw = $node->settings->ds_block_data ?? '{}';
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return $decoded ? $decoded : array();
		}
		// When BB's pipeline has already decoded the JSON (e.g. after
		// FLBuilderModel::get_layout_data processes the data), $raw may
		// be a stdClass or contain nested stdClass objects. Deep-convert
		// to arrays so downstream code (AnnotationReconstructor) gets
		// the array format it expects.
		return json_decode( wp_json_encode( $raw ), true ) ?: array();
	}

	/**
	 * Move a node to a new position relative to a target node.
	 *
	 * Handles three cases:
	 * - Both nodes are top-level: reorders ancestor rows.
	 * - Both nodes share the same parent: reorders siblings.
	 * - Different parents: returns WP_Error (cross-container moves not supported).
	 *
	 * @param array  $data           Current layout data.
	 * @param string $node_id        Node to move.
	 * @param string $target_node_id Reference node.
	 * @param string $position       "before" or "after".
	 * @return array|\WP_Error Updated layout data, or WP_Error on failure.
	 */
	public function move_node( array $data, string $node_id, string $target_node_id, string $position ) {
		$source_node   = $data[ $node_id ];
		$target_node   = $data[ $target_node_id ];
		$source_parent = $source_node->parent ?? '';
		$target_parent = $target_node->parent ?? '';

		// Both top-level: reorder via ancestor rows.
		if ( empty( $source_parent ) && empty( $target_parent ) ) {
			$source_row = $this->find_ancestor_row( $data, $node_id );
			$target_row = $this->find_ancestor_row( $data, $target_node_id );

			if ( ! $source_row || ! $target_row ) {
				return new \WP_Error(
					'row_not_found',
					'Could not find parent row for source or target node.',
					[ 'status' => 400 ]
				);
			}

			$source_row_id = $source_row->node;
			$target_row_id = $target_row->node;

			$top_level = [];
			foreach ( $data as $n ) {
				if ( empty( $n->parent ) && $n->node !== $source_row_id ) {
					$top_level[] = $n;
				}
			}

			usort( $top_level, function ( $a, $b ) {
				return ( $a->position ?? 0 ) - ( $b->position ?? 0 );
			} );

			$reordered = [];
			foreach ( $top_level as $n ) {
				if ( $n->node === $target_row_id && 'before' === $position ) {
					$reordered[] = $source_row;
				}
				$reordered[] = $n;
				if ( $n->node === $target_row_id && 'after' === $position ) {
					$reordered[] = $source_row;
				}
			}

			foreach ( $reordered as $index => $n ) {
				$data[ $n->node ]->position = $index;
			}

			return $data;
		}

		// Same parent: reorder among siblings.
		if ( $source_parent === $target_parent ) {
			$siblings = [];
			foreach ( $data as $n ) {
				if ( ( $n->parent ?? '' ) === $source_parent && $n->node !== $node_id ) {
					$siblings[] = $n;
				}
			}

			usort( $siblings, function ( $a, $b ) {
				return ( $a->position ?? 0 ) - ( $b->position ?? 0 );
			} );

			$reordered = [];
			foreach ( $siblings as $n ) {
				if ( $n->node === $target_node_id && 'before' === $position ) {
					$reordered[] = $source_node;
				}
				$reordered[] = $n;
				if ( $n->node === $target_node_id && 'after' === $position ) {
					$reordered[] = $source_node;
				}
			}

			foreach ( $reordered as $index => $n ) {
				$data[ $n->node ]->position = $index;
			}

			return $data;
		}

		// Cross-container move.
		return new \WP_Error(
			'cross_container_move',
			'Cannot move blocks between different containers. Only same-parent reordering is supported.',
			[ 'status' => 400 ]
		);
	}

	/**
	 * Resolve a position string to a numeric position index.
	 *
	 * @param  array       $data      Current layout data.
	 * @param  int|string  $position  Position descriptor.
	 * @param  string|null $parent_id Optional parent ID — count siblings of this parent instead of top-level.
	 * @return int         Numeric position.
	 */
	public function resolve_position( array $data, $position, ?string $parent_id = null ): int {
		// Count existing sibling nodes (scoped to parent when provided)
		$max = 0;
		foreach ( $data as $node ) {
			$node_parent = $node->parent ?? '';
			$matches     = $parent_id
				? ( $node_parent === $parent_id )
				: empty( $node_parent );
			if ( $matches ) {
				$max = max( $max, ( $node->position ?? 0 ) + 1 );
			}
		}

		if ( 'first' === $position ) {
			return 0;
		}

		if ( 'last' === $position || null === $position ) {
			return $max;
		}

		if ( is_numeric( $position ) ) {
			return (int) $position;
		}

		// "before:{nodeId}" or "after:{nodeId}" — resolve relative to the reference node's own position among its siblings
		if ( is_string( $position ) && false !== strpos( $position, ':' ) ) {
			[ $rel, $ref_id ] = explode( ':', $position, 2 );
			if ( isset( $data[ $ref_id ] ) ) {
				$ref_node = $data[ $ref_id ];
				$ref_pos  = $ref_node->position ?? 0;
				return 'before' === $rel ? $ref_pos : $ref_pos + 1;
			}
		}

		return $max;
	}
}
