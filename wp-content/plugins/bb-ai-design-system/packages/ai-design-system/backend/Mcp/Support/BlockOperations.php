<?php

namespace FL\DesignSystem\Mcp\Support;

use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\Services\BlockService;
use FL\DesignSystem\Services\Parser\AnnotationParser;

/**
 * Applies block-level operations (update/add/remove/move) for the
 * update-page-blocks ability.
 *
 * The dispatcher ({@see apply()}) routes to per-op handlers. Each
 * handler is independent: a failure in one op does not abort later ops.
 * UpdatePageBlocks owns the surrounding hash-gating, staging, and
 * per-op result accumulation.
 *
 * Add ops cannot reference a block created earlier in the same batch
 * because position is resolved against the live outline. The dispatcher
 * surfaces this constraint via {@see validate_add_position()}.
 */
class BlockOperations {

	private BlockService $block_service;

	public function __construct( BlockService $block_service ) {
		$this->block_service = $block_service;
	}

	/**
	 * Dispatch a single operation to the matching handler.
	 *
	 * @param int                        $post_id Post ID.
	 * @param PageEditorAdapterInterface $adapter Resolved adapter.
	 * @param array                      $op      Operation data.
	 * @param string|null                $ds_uuid Design system UUID for the page, if any.
	 * @return true|string|array|\WP_Error Handler result. true (or string node_id for add)
	 *                                     on plain success; array{warnings: string[]} when
	 *                                     a DS-block update produced soft warnings such as
	 *                                     unknown settings keys; WP_Error on failure.
	 */
	public function apply( int $post_id, PageEditorAdapterInterface $adapter, array $op, ?string $ds_uuid ) {
		$op_type = $op['op'] ?? '';

		switch ( $op_type ) {
			case 'update':
				return $this->apply_update( $post_id, $adapter, $op );
			case 'add':
				return $this->apply_add( $post_id, $adapter, $op, $ds_uuid );
			case 'remove':
				return $this->apply_remove( $post_id, $adapter, $op );
			case 'move':
				return $this->apply_move( $post_id, $adapter, $op );
			default:
				return new \WP_Error(
					'invalid_op',
					sprintf( 'Unknown operation "%s". Must be one of: update, add, remove, move.', $op_type ),
					[ 'status' => 400 ]
				);
		}
	}

	/**
	 * Apply an update operation to a single block.
	 *
	 * @return true|array|\WP_Error true on plain success, array{warnings: string[]} when
	 *                              a DS-block settings patch contained unknown keys, or
	 *                              WP_Error on failure.
	 */
	private function apply_update( int $post_id, PageEditorAdapterInterface $adapter, array $op ) {
		$node_id = (string) ( $op['node_id'] ?? '' );

		if ( '' === $node_id ) {
			return new \WP_Error(
				'missing_node_id',
				'The node_id field is required for update operations.',
				[ 'status' => 400 ]
			);
		}

		$has_html     = array_key_exists( 'html', $op ) && '' !== $op['html'];
		$has_css      = array_key_exists( 'css', $op );
		$has_js       = array_key_exists( 'js', $op );
		$has_settings = array_key_exists( 'settings', $op ) && is_array( $op['settings'] );

		if ( ! $has_html && ! $has_css && ! $has_js && ! $has_settings ) {
			return new \WP_Error(
				'missing_updates',
				'At least one of html, css, js, or settings is required for update operations.',
				[ 'status' => 400 ]
			);
		}

		// settings cannot ride alongside html (settings come from annotation parsing).
		// Without this guard one of the two patches gets silently dropped.
		if ( $has_html && $has_settings ) {
			return new \WP_Error(
				'invalid_op_combination',
				'The html and settings fields cannot be combined in one update op. When html is provided, settings come from annotation parsing, so pick one.',
				[ 'status' => 400 ]
			);
		}

		// Collect unknown setting keys for DS blocks before the merge consumes them.
		// Schema can't enforce per-block settings shape (it's free-form), and
		// SettingsMerger adds patch keys to stored settings rather than dropping
		// them, so without this comparison an unknown key just sits in storage and
		// is never read by the Mustache template. Native modules are out of scope:
		// BlockService::validate_native_updates already rejects unknowns loudly.
		$warnings = [];
		if ( $has_settings ) {
			$existing_section = $adapter->export_section( $post_id, $node_id );
			if ( null !== $existing_section ) {
				$existing_settings = isset( $existing_section['settings'] ) && is_array( $existing_section['settings'] )
					? $existing_section['settings']
					: [];
				$unknown_keys      = array_diff( array_keys( $op['settings'] ), array_keys( $existing_settings ) );
				foreach ( $unknown_keys as $key ) {
					$warnings[] = sprintf(
						'Unknown setting key "%s" on block %s, ignored. The merge stored it but the block template does not read it; valid keys still applied.',
						(string) $key,
						$node_id
					);
				}
			}
		}

		$updates = [];
		if ( $has_html ) {
			$updates['html'] = $op['html'];
		}
		if ( $has_css ) {
			$updates['css'] = $op['css'];
		}
		if ( $has_js ) {
			$updates['js'] = $op['js'];
		}
		if ( $has_settings ) {
			$updates['settings'] = $op['settings'];
		}

		$result = $this->block_service->update_block_data( $post_id, $node_id, $updates, $adapter );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $warnings ) ) {
			return [ 'warnings' => $warnings ];
		}

		return $result;
	}

	/**
	 * Apply an add operation to insert a new DS block.
	 *
	 * @return string|\WP_Error The new block's node_id on success.
	 */
	private function apply_add( int $post_id, PageEditorAdapterInterface $adapter, array $op, ?string $ds_uuid ) {
		if ( ! $ds_uuid ) {
			return new \WP_Error(
				'no_design_system',
				'This page does not have a design system, which is required to add new blocks. Ask the user if they would like to assign an existing design system to this page -- call list-design-systems to show available options.',
				[ 'status' => 422 ]
			);
		}

		$html     = (string) ( $op['html'] ?? '' );
		$css      = (string) ( $op['css'] ?? '' );
		$label    = (string) ( $op['label'] ?? '' );
		$js       = (string) ( $op['js'] ?? '' );
		$position = (string) ( $op['position'] ?? 'last' );

		if ( '' === $html ) {
			return new \WP_Error( 'missing_html', 'The html field is required for add operations.', [ 'status' => 400 ] );
		}
		if ( '' === $css ) {
			return new \WP_Error( 'missing_css', 'The css field is required for add operations.', [ 'status' => 400 ] );
		}
		if ( '' === $label ) {
			return new \WP_Error( 'missing_label', 'The label field is required for add operations.', [ 'status' => 400 ] );
		}

		// Validate position reference before touching the adapter. Both adapters'
		// resolve_position silently fall through to "append" when the referenced
		// node doesn't exist -- we want a hard error so the agent doesn't think
		// a block landed where it didn't. Also ensures agents don't try to
		// reference a node added earlier in the same batch (not supported).
		$position_error = $this->validate_add_position( $post_id, $adapter, $position );
		if ( is_wp_error( $position_error ) ) {
			return $position_error;
		}

		$parsed  = AnnotationParser::parse( $html );
		$section = [
			'label'    => $label,
			'template' => $parsed['template'],
			'css'      => $css,
			'js'       => $js,
			'settings' => $parsed['settings'],
		];

		return $adapter->add_section( $post_id, $section, $position );
	}

	/**
	 * Validate a position descriptor used by add ops.
	 *
	 * Accepts "first", "last", or "before:{nodeId}" / "after:{nodeId}" referencing
	 * an existing top-level block. Returns WP_Error on an invalid shape or a
	 * missing reference, null otherwise.
	 */
	public function validate_add_position( int $post_id, PageEditorAdapterInterface $adapter, string $position ): ?\WP_Error {
		if ( 'first' === $position || 'last' === $position ) {
			return null;
		}

		if ( ! str_contains( $position, ':' ) ) {
			return new \WP_Error(
				'invalid_position',
				sprintf( 'Invalid position "%s". Must be "first", "last", or "before:{nodeId}" / "after:{nodeId}".', $position ),
				[ 'status' => 400 ]
			);
		}

		[ $rel, $ref_id ] = explode( ':', $position, 2 );

		if ( ! in_array( $rel, [ 'before', 'after' ], true ) || '' === $ref_id ) {
			return new \WP_Error(
				'invalid_position',
				sprintf( 'Invalid position "%s". Relative references must use the form "before:{nodeId}" or "after:{nodeId}".', $position ),
				[ 'status' => 400 ]
			);
		}

		$outline       = $adapter->get_outline( $post_id );
		$top_level_ids = [];
		$nested_ids    = [];
		$this->collect_outline_ids( $outline, $top_level_ids, $nested_ids, true );

		if ( in_array( $ref_id, $top_level_ids, true ) ) {
			return null;
		}

		if ( in_array( $ref_id, $nested_ids, true ) ) {
			return new \WP_Error(
				'nested_anchor_not_supported',
				sprintf( 'Block "%s" is nested inside another block. Nested add/move is not currently supported -- use a top-level block as the anchor.', $ref_id ),
				[ 'status' => 400 ]
			);
		}

		return new \WP_Error(
			'node_not_found',
			sprintf( 'No top-level block found with node_id "%s" to position relative to. Note: you cannot reference a block added earlier in the same batch -- chained adds require separate update-page-blocks calls.', $ref_id ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Recursively collect outline node IDs into top-level and nested buckets.
	 *
	 * @param array $outline       Outline tree from PageEditorAdapterInterface::get_outline().
	 * @param array $top_level_ids Output (by reference): IDs found at the outer level.
	 * @param array $nested_ids    Output (by reference): IDs found anywhere under a children[] subtree.
	 * @param bool  $is_top        True for the outermost call only.
	 */
	private function collect_outline_ids( array $outline, array &$top_level_ids, array &$nested_ids, bool $is_top ): void {
		foreach ( $outline as $entry ) {
			$id = isset( $entry['node_id'] ) ? (string) $entry['node_id'] : '';
			if ( '' !== $id ) {
				if ( $is_top ) {
					$top_level_ids[] = $id;
				} else {
					$nested_ids[] = $id;
				}
			}

			if ( ! empty( $entry['children'] ) && is_array( $entry['children'] ) ) {
				$this->collect_outline_ids( $entry['children'], $top_level_ids, $nested_ids, false );
			}
		}
	}

	/**
	 * Apply a remove operation to delete a block.
	 *
	 * @return true|\WP_Error
	 */
	private function apply_remove( int $post_id, PageEditorAdapterInterface $adapter, array $op ) {
		$node_id = (string) ( $op['node_id'] ?? '' );

		if ( '' === $node_id ) {
			return new \WP_Error(
				'missing_node_id',
				'The node_id field is required for remove operations.',
				[ 'status' => 400 ]
			);
		}

		return $adapter->remove_section( $post_id, $node_id );
	}

	/**
	 * Apply a move operation to reposition a block.
	 *
	 * @return true|\WP_Error
	 */
	private function apply_move( int $post_id, PageEditorAdapterInterface $adapter, array $op ) {
		$node_id        = (string) ( $op['node_id'] ?? '' );
		$target_node_id = (string) ( $op['target_node_id'] ?? '' );
		$position       = (string) ( $op['position'] ?? '' );

		if ( '' === $node_id ) {
			return new \WP_Error( 'missing_node_id', 'The node_id field is required for move operations.', [ 'status' => 400 ] );
		}
		if ( '' === $target_node_id ) {
			return new \WP_Error( 'missing_target_node_id', 'The target_node_id field is required for move operations.', [ 'status' => 400 ] );
		}
		if ( ! in_array( $position, [ 'before', 'after' ], true ) ) {
			return new \WP_Error( 'invalid_position', 'The position field must be "before" or "after" for move operations.', [ 'status' => 400 ] );
		}

		return $adapter->move_section( $post_id, $node_id, $target_node_id, $position );
	}
}
