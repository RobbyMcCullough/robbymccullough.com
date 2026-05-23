<?php

namespace FL\DesignSystem\BeaverBuilder;

use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\Services\BindingsNormalizer;
use FL\DesignSystem\Services\BlockService;
use FL\DesignSystem\Services\SettingsArrayMutator;
use FL\DesignSystem\Settings\SettingsSanitizer;

class BeaverBuilderRestController {

	/**
	 * Maximum number of nodes accepted per /builder/layout/batch call.
	 * Mirrors `READ_BLOCKS_MAX` in the JS handler — duplicated for a
	 * defense-in-depth sanity check at the REST boundary.
	 */
	private const BATCH_NODE_CAP = 30;

	private AuthInterface $auth;
	private LayoutManager $layout;
	private string $module_namespace;
	private BlockService $block_service;
	private BindingsNormalizer $bindings_normalizer;

	/**
	 * Field-type-aware sanitizer used by the inline-edit endpoint when the
	 * client supplies a `type` hint per update. Mirrors the optional-ctor
	 * pattern used by {@see \FL\DesignSystem\BeaverBuilder\SaveGuard} and
	 * {@see \FL\DesignSystem\BlockEditor\KsesFallback}.
	 */
	private SettingsSanitizer $sanitizer;

	/**
	 * @param AuthInterface          $auth             Auth handler.
	 * @param LayoutManager          $layout           Layout manager.
	 * @param string                 $module_namespace Module namespace prefix (e.g. 'ds', 'tw').
	 * @param SettingsSanitizer|null $sanitizer        Inject for testability; defaults
	 *                                                  to a fresh instance when null.
	 */
	public function __construct(
		AuthInterface $auth,
		LayoutManager $layout,
		string $module_namespace = 'ds',
		?SettingsSanitizer $sanitizer = null,
	) {
		$this->auth                = $auth;
		$this->layout              = $layout;
		$this->module_namespace    = $module_namespace;
		$this->block_service       = new BlockService( $module_namespace );
		$this->bindings_normalizer = new BindingsNormalizer();
		$this->sanitizer           = $sanitizer ?? new SettingsSanitizer();
	}

	/**
	 * Register REST routes and hook into rest_api_init.
	 */
	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes for Custom Module CRUD operations.
	 */
	public function register_routes() {
		$namespace        = 'fl-design-system/v1';
		$read_permission  = [ $this->auth, 'content_creator_permission_callback' ];
		$write_permission = [ $this->auth, 'content_creator_permission_callback' ];

		// Layout overview
		register_rest_route($namespace, '/builder/layout', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_layout' ],
			'permission_callback' => $read_permission,
		]);

		// Batch read — N nodes in one round trip. Same per-node payload as
		// the singular GET; per-node errors are returned in the results array
		// so a partial-success response is still HTTP 200.
		register_rest_route($namespace, '/builder/layout/batch', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'get_layout_batch' ],
			'permission_callback' => $read_permission,
		]);

		// Create a Custom Module instance
		register_rest_route($namespace, '/builder/module', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_module' ],
			'permission_callback' => $write_permission,
		]);

		// Update ds_block_data on an existing Custom Module
		register_rest_route($namespace, '/builder/module/(?P<node_id>[a-z0-9]+)', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'update_module' ],
			'permission_callback' => $write_permission,
		]);

		// Bulk update — N modules in one round trip. Loads the layout once,
		// applies each row in memory, writes once. Per-row results are
		// returned in the response so a partial-success response is still
		// HTTP 200 — mirrors the JS-side batch tools' per-entry contract.
		register_rest_route($namespace, '/builder/modules/batch', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'update_modules_batch' ],
			'permission_callback' => $write_permission,
		]);

		// Delete a Custom Module
		register_rest_route($namespace, '/builder/module/(?P<node_id>[a-z0-9]+)', [
			'methods'             => 'DELETE',
			'callback'            => [ $this, 'delete_module' ],
			'permission_callback' => $read_permission,
		]);

		// Move a module to a new position
		register_rest_route($namespace, '/builder/module/(?P<node_id>[a-z0-9]+)/move', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'move_module' ],
			'permission_callback' => $read_permission,
		]);

		// Inline-edit settings update. Generalized from a text-only endpoint to
		// accept an `op` field per update so PR 4's repeater operations
		// (splice, reorder) can land here too. Only `op: 'set'` is valid in
		// PR 1. Open to any user who can edit the post; sanitization
		// (SaveGuard) happens on the save pipeline, so permission here matches
		// PageOverrideProvider's write path rather than the content-creator gate.
		register_rest_route($namespace, '/builder/module/(?P<node_id>[a-z0-9]+)/settings', [
			'methods'             => 'PATCH',
			'callback'            => [ $this, 'update_module_settings' ],
			'permission_callback' => [ $this, 'edit_post_permission_callback' ],
		]);
	}

	/**
	 * Return a layout overview for the current post.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_layout( \WP_REST_Request $request ) {
		$post_id   = $request->get_param( 'post_id' );
		$node_id   = $request->get_param( 'node_id' );
		$parent_id = $request->get_param( 'parent_id' );
		$tree      = $this->parse_tree_param( $request->get_param( 'tree' ) );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) || empty( $data ) ) {
			if ( $node_id ) {
				return new \WP_Error( 'node_not_found', 'Node not found.', [ 'status' => 404 ] );
			}
			return new \WP_REST_Response( [ 'nodes' => [] ], 200 );
		}

		// Single node detail mode
		if ( $node_id ) {
			return $this->get_node_detail( $data, $node_id );
		}

		// Scoped children mode — recursive subtree by default, shallow when tree=false.
		if ( $parent_id ) {
			$recurse = ( null === $tree ) ? true : $tree;
			return $this->get_children_overview( $data, $parent_id, $recurse );
		}

		// Top-level overview mode — flat by default, full tree when tree=true.
		$recurse_top = ( true === $tree );

		$top_level = [];
		foreach ( $data as $node ) {
			if ( empty( $node->parent ) ) {
				$top_level[] = $node;
			}
		}

		usort($top_level, function ( $a, $b ) {
			return ( $a->position ?? 0 ) - ( $b->position ?? 0 );
		});

		$nodes            = [];
		$native_row_count = 0;
		foreach ( $top_level as $row ) {
			$module = $this->layout->find_module_in_row( $data, $row->node );
			if ( $module && $this->block_service->is_ds_module( $module->settings->type ?? '' ) ) {
				// DS section — summarize the section module directly. Children stay
				// collapsed regardless of $recurse_top: the row IS the section.
				$nodes[] = $this->summarize_node( $module );
			} else {
				// Native row (or empty) — show as top-level row
				$global_label = $this->is_global_node( $row ) ? ' (global)' : '';
				$summary      = [
					'nodeId'     => $row->node,
					'type'       => 'native',
					'moduleType' => 'row' . $global_label,
				];
				if ( $recurse_top ) {
					$tree_entries        = $this->layout->collect_descendant_tree( $data, $row->node );
					$summary['children'] = $this->summarize_tree( $data, $tree_entries );
				} else {
					++$native_row_count;
				}
				$nodes[] = $summary;
			}
		}

		$response = [ 'nodes' => $nodes ];
		if ( $native_row_count > 0 ) {
			$noun             = 1 === $native_row_count ? 'native container' : 'native containers';
			$response['hint'] = sprintf( '%d %s — pass tree:true to see their contents.', $native_row_count, $noun );
		}

		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Normalize the optional `tree` query param into bool|null.
	 *
	 * Accepts the usual REST truthy/falsy variants and maps anything ambiguous
	 * to null so the caller can fall back to behavior-preserving defaults.
	 *
	 * @param  mixed $value Raw param value.
	 * @return bool|null    True/false when explicit; null when unset or unrecognized.
	 */
	private function parse_tree_param( $value ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$lower = strtolower( $value );
			if ( in_array( $lower, [ '1', 'true', 'yes', 'on' ], true ) ) {
				return true;
			}
			if ( in_array( $lower, [ '0', 'false', 'no', 'off' ], true ) ) {
				return false;
			}
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		return null;
	}

	/**
	 * Create a Custom Module instance in the BB layout.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function create_module( \WP_REST_Request $request ) {
		$body        = $request->get_json_params();
		$post_id     = $body['post_id'] ?? null;
		$module_data = $body['ds_block_data'] ?? null;
		$position    = $body['position'] ?? 'last';
		$parent_id   = $body['parent_id'] ?? null;

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( empty( $module_data ) || ! is_array( $module_data ) ) {
			return new \WP_Error(
				'invalid_ds_block_data',
				'ds_block_data must be a non-empty object.',
				[ 'status' => 400 ],
			);
		}

		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		// Auto-derive parent_id from relative position reference when not explicitly set
		if ( ! $parent_id && is_string( $position ) && false !== strpos( $position, ':' ) ) {
			$parts  = explode( ':', $position, 2 );
			$ref_id = $parts[1] ?? '';
			if ( $ref_id && isset( $data[ $ref_id ] ) ) {
				$ref_parent = $data[ $ref_id ]->parent ?? '';
				if ( ! empty( $ref_parent ) ) {
					$parent_id = $ref_parent;
				}
			}
		}

		// Row heuristic: resolve row → first column-group → first column
		if ( $parent_id ) {
			$resolved_parent = $this->resolve_row_to_column( $data, $parent_id );
			if ( is_wp_error( $resolved_parent ) ) {
				return $resolved_parent;
			}
			$parent_id = $resolved_parent;
		}

		$module_type       = $this->resolve_module_type();
		$resolved_position = $this->layout->resolve_position( $data, $position, $parent_id );
		$result            = $this->layout->create_module( $data, $module_data, $resolved_position, $module_type, $parent_id );

		// Auto-set node_label from the block label
		$block_label = $module_data['label'] ?? '';
		if ( $block_label && isset( $result['data'][ $result['module_id'] ] ) ) {
			$result['data'][ $result['module_id'] ]->settings->node_label = $block_label;
		}

		\FLBuilderModel::update_layout_data( $result['data'], 'draft', $post_id );

		return new \WP_REST_Response([
			'success' => true,
			'nodeId'  => $result['module_id'],
		], 200);
	}

	/**
	 * Update module_data on an existing Custom Module node.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_module( \WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$post_id = $body['post_id'] ?? null;
		$node_id = $request->get_param( 'node_id' );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) || ! isset( $data[ $node_id ] ) ) {
			return new \WP_Error(
				'node_not_found',
				sprintf( 'Node "%s" not found in layout.', $node_id ),
				[ 'status' => 404 ],
			);
		}

		// Native modules use module_data key; DS blocks use ds_block_data
		$is_ds       = $this->block_service->is_ds_module( $data[ $node_id ]->settings->type ?? '' );
		$module_data = $is_ds ? ( $body['ds_block_data'] ?? null ) : ( $body['module_data'] ?? null );

		if ( empty( $module_data ) || ! is_array( $module_data ) ) {
			$key = $is_ds ? 'ds_block_data' : 'module_data';
			return new \WP_Error(
				'invalid_data',
				"{$key} must be a non-empty object.",
				[ 'status' => 400 ],
			);
		}

		if ( ! $is_ds ) {
			// Native module — delegate to BlockService for validation and updates.
			$updates = [];

			if ( array_key_exists( 'bb_css_code', $module_data ) ) {
				$updates['css'] = $module_data['bb_css_code'];
			}
			if ( array_key_exists( 'bb_js_code', $module_data ) ) {
				$updates['js'] = $module_data['bb_js_code'];
			}

			$settings = $module_data['settings'] ?? [];
			if ( is_array( $settings ) && ! empty( $settings ) ) {
				$updates['settings'] = $settings;
			}

			if ( empty( $updates ) ) {
				return new \WP_Error(
					'native_module_restricted',
					'Only bb_css_code, bb_js_code, and settings can be updated on native modules.',
					[ 'status' => 400 ],
				);
			}

			$result = $this->block_service->update_block_data(
				$post_id,
				$node_id,
				$updates,
				new BeaverBuilderPageAdapter( $this->layout, $this->module_namespace )
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return new \WP_REST_Response( [ 'success' => true ], 200 );
		}

		$data = $this->layout->update_module( $data, $node_id, $module_data );

		// Auto-update node_label from the block label
		$block_label = $module_data['label'] ?? '';
		if ( $block_label ) {
			$data[ $node_id ]->settings->node_label = $block_label;
		}

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Bulk-update up to BATCH_NODE_CAP modules in a single REST call.
	 *
	 * Permission + post validation runs once; `FLBuilderModel::get_layout_data`
	 * runs once. Each row is dispatched in memory (DS rows through
	 * `LayoutManager::update_module`, native rows through
	 * `BlockService::update_native_block_in_data` after wire-key translation).
	 * Per-row errors are returned in the results array so a partial-success
	 * response is still HTTP 200 — mirrors the JS-side `write_blocks` /
	 * `update_block_settings_batch` per-entry contract.
	 *
	 * Body shape:
	 *   { post_id: int,
	 *     modules: [ { node_id, ds_block_data?, module_data? }, ... ] }
	 *
	 * Each row must specify exactly one of `ds_block_data` (for DS modules)
	 * or `module_data` (for native modules / structural nodes). DS settings
	 * arrive already merged + normalized from the JS side — the server stores
	 * them verbatim via `LayoutManager::update_module`. Native module_data
	 * uses the same wire-format keys as the singular PATCH (`bb_css_code`,
	 * `bb_js_code`, `settings`); this handler translates them to the
	 * BlockService's `css`/`js`/`settings` shape.
	 *
	 * Layout writes:
	 *   - `draft` is written once if any row succeeded.
	 *   - `published` is written once *additionally* if any *native* row
	 *     succeeded — matches the existing native singular's draft+published
	 *     write pattern. DS singular only writes draft; preserve that.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_modules_batch( \WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$post_id = $body['post_id'] ?? null;
		$modules = $body['modules'] ?? null;

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( ! is_array( $modules ) || empty( $modules ) ) {
			return new \WP_Error(
				'invalid_modules',
				'modules must be a non-empty array of { node_id, ds_block_data?, module_data? } entries.',
				[ 'status' => 400 ],
			);
		}

		if ( count( $modules ) > self::BATCH_NODE_CAP ) {
			return new \WP_Error(
				'too_many_modules',
				sprintf( 'At most %d modules per batch.', self::BATCH_NODE_CAP ),
				[ 'status' => 400 ],
			);
		}

		foreach ( $modules as $i => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['node_id'] ) || ! is_string( $entry['node_id'] ) || '' === $entry['node_id'] ) {
				return new \WP_Error(
					'invalid_module_entry',
					sprintf( 'modules[%d].node_id is required and must be a non-empty string.', $i ),
					[ 'status' => 400 ],
				);
			}
			$has_ds     = isset( $entry['ds_block_data'] ) && is_array( $entry['ds_block_data'] ) && ! empty( $entry['ds_block_data'] );
			$has_native = isset( $entry['module_data'] ) && is_array( $entry['module_data'] ) && ! empty( $entry['module_data'] );
			if ( $has_ds === $has_native ) {
				// Both present, or neither present.
				return new \WP_Error(
					'invalid_module_entry',
					sprintf( 'modules[%d] must provide exactly one of ds_block_data or module_data.', $i ),
					[ 'status' => 400 ],
				);
			}
		}

		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$results      = [];
		$wrote_any    = false;
		$wrote_native = false;

		foreach ( $modules as $entry ) {
			$node_id = $entry['node_id'];

			if ( ! isset( $data[ $node_id ] ) ) {
				$results[] = [
					'nodeId' => $node_id,
					'error'  => [
						'code'    => 'node_not_found',
						'message' => sprintf( 'Node "%s" not found in layout.', $node_id ),
						'status'  => 404,
					],
				];
				continue;
			}

			$is_ds_node = $this->block_service->is_ds_module( $data[ $node_id ]->settings->type ?? '' );

			if ( isset( $entry['ds_block_data'] ) ) {
				if ( ! $is_ds_node ) {
					$results[] = [
						'nodeId' => $node_id,
						'error'  => [
							'code'    => 'not_ds_module',
							'message' => sprintf( 'Node "%s" is not a DS module; use module_data instead.', $node_id ),
							'status'  => 400,
						],
					];
					continue;
				}

				$module_data = $entry['ds_block_data'];
				$data        = $this->layout->update_module( $data, $node_id, $module_data );

				// Mirror the singular controller's auto-label behavior so a
				// label change in the batch payload propagates to the node's
				// settings.node_label without requiring a follow-up call.
				$block_label = $module_data['label'] ?? '';
				if ( $block_label ) {
					$data[ $node_id ]->settings->node_label = $block_label;
				}

				$results[] = [
					'nodeId' => $node_id,
					'status' => 'success',
				];
				$wrote_any = true;
				continue;
			}

			// Native row: translate wire-format keys (bb_css_code, bb_js_code,
			// settings) into the BlockService shape (css, js, settings),
			// matching the singular controller's translation at the same seam.
			$module_data = $entry['module_data'];
			$updates     = [];

			if ( array_key_exists( 'bb_css_code', $module_data ) ) {
				$updates['css'] = $module_data['bb_css_code'];
			}
			if ( array_key_exists( 'bb_js_code', $module_data ) ) {
				$updates['js'] = $module_data['bb_js_code'];
			}
			$settings = $module_data['settings'] ?? [];
			if ( is_array( $settings ) && ! empty( $settings ) ) {
				$updates['settings'] = $settings;
			}

			if ( empty( $updates ) ) {
				$results[] = [
					'nodeId' => $node_id,
					'error'  => [
						'code'    => 'native_module_restricted',
						'message' => 'Only bb_css_code, bb_js_code, and settings can be updated on native modules.',
						'status'  => 400,
					],
				];
				continue;
			}

			if ( $is_ds_node ) {
				$results[] = [
					'nodeId' => $node_id,
					'error'  => [
						'code'    => 'not_native',
						'message' => sprintf( 'Node "%s" is a DS module; use ds_block_data instead.', $node_id ),
						'status'  => 400,
					],
				];
				continue;
			}

			$apply = $this->block_service->update_native_block_in_data( $data, $node_id, $updates );
			if ( is_wp_error( $apply ) ) {
				$status_data = $apply->get_error_data();
				$results[]   = [
					'nodeId' => $node_id,
					'error'  => [
						'code'    => $apply->get_error_code(),
						'message' => $apply->get_error_message(),
						'status'  => is_array( $status_data ) && isset( $status_data['status'] ) ? $status_data['status'] : 400,
					],
				];
				continue;
			}

			$results[]    = [
				'nodeId' => $node_id,
				'status' => 'success',
			];
			$wrote_any    = true;
			$wrote_native = true;
		}

		if ( $wrote_any ) {
			\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );
			if ( $wrote_native ) {
				\FLBuilderModel::update_layout_data( $data, 'published', $post_id );
			}
		}

		return new \WP_REST_Response( [ 'results' => $results ], 200 );
	}

	/**
	 * Delete a Custom Module node from the layout.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function delete_module( \WP_REST_Request $request ) {
		$post_id = $request->get_param( 'post_id' );
		$node_id = $request->get_param( 'node_id' );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) || ! isset( $data[ $node_id ] ) ) {
			return new \WP_Error(
				'node_not_found',
				sprintf( 'Node "%s" not found in layout.', $node_id ),
				[ 'status' => 404 ],
			);
		}

		$data = $this->layout->delete_module( $data, $node_id );
		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Move a Custom Module to a new position relative to a target node.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function move_module( \WP_REST_Request $request ) {
		$body           = $request->get_json_params();
		$post_id        = $body['post_id'] ?? null;
		$target_node_id = $body['target_node_id'] ?? null;
		$position       = $body['position'] ?? null;
		$node_id        = $request->get_param( 'node_id' );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( ! $target_node_id || ! in_array( $position, [ 'before', 'after' ], true ) ) {
			return new \WP_Error(
				'invalid_params',
				'target_node_id and position ("before" or "after") are required.',
				[ 'status' => 400 ],
			);
		}

		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		if ( ! isset( $data[ $node_id ] ) ) {
			return new \WP_Error(
				'node_not_found',
				sprintf( 'Source node "%s" not found.', $node_id ),
				[ 'status' => 404 ],
			);
		}

		if ( ! isset( $data[ $target_node_id ] ) ) {
			return new \WP_Error(
				'target_not_found',
				sprintf( 'Target node "%s" not found.', $target_node_id ),
				[ 'status' => 404 ],
			);
		}

		$result = $this->layout->move_node( $data, $node_id, $target_node_id, $position );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = $result;

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Apply a narrow list of settings updates to a DS block module.
	 *
	 * Each update carries an `op` field — only `'set'` is valid in PR 1.
	 * `'splice'` and `'reorder'` arrive in PR 4 (repeater editor). Path-walk
	 * + sanitization + save behavior is identical to the prior `/text`
	 * endpoint; the rename + `op` field exist so the body shape can grow
	 * without churn when array-mutation operations land.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_module_settings( \WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$post_id = isset( $body['post_id'] ) ? absint( $body['post_id'] ) : 0;
		$node_id = $request->get_param( 'node_id' );
		$updates = $body['updates'] ?? null;

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return new \WP_Error(
				'invalid_updates',
				'updates must be a non-empty array of { path, value } objects.',
				[ 'status' => 400 ],
			);
		}

		// Mirror the sibling write endpoints (update_module, move_module,
		// delete_module) — read and write the draft layout directly. Routing
		// through FLBuilderModel::save_settings would require reconstructing
		// the builder's post + status context for REST (set_post_id,
		// fl_builder_node_status filter, module-registry registration on
		// rest_api_init). SaveGuard's `fl_builder_before_update_layout_data`
		// filter still fires inside update_layout_data, so restricted-user
		// code-field preservation + SettingsSanitizer still run as
		// defense-in-depth without any of that scaffolding.
		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) || ! isset( $data[ $node_id ] ) ) {
			return new \WP_Error(
				'node_not_found',
				sprintf( 'Node "%s" not found in layout.', $node_id ),
				[ 'status' => 404 ],
			);
		}

		$node = $data[ $node_id ];

		if ( ! $this->block_service->is_ds_module( $node->settings->type ?? '' ) ) {
			return new \WP_Error(
				'not_ds_module',
				'Inline text editing is only supported on DS block modules.',
				[ 'status' => 400 ],
			);
		}

		if ( \FLBuilderModel::is_node_global( $node ) ) {
			return new \WP_Error(
				'global_node_inline_edit_not_supported',
				'Inline editing of global DS blocks is not supported.',
				[ 'status' => 400 ],
			);
		}

		$block_data = $this->decode_block_data( $node->settings->ds_block_data ?? null );
		if ( null === $block_data ) {
			return new \WP_Error(
				'invalid_block_data',
				'Stored block data could not be decoded.',
				[ 'status' => 500 ],
			);
		}

		$merged = $block_data;
		if ( ! isset( $merged['settings'] ) || ! is_array( $merged['settings'] ) ) {
			$merged['settings'] = [];
		}

		foreach ( $updates as $update ) {
			$validated = $this->validate_text_update( $update, $merged['settings'] );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$op = is_array( $update ) ? ( $update['op'] ?? 'set' ) : 'set';

			// Ops apply in array order against an evolving `$merged` snapshot.
			// Sequential dispatch on `op` lets structural edits (splice /
			// reorder / template-set) and inline-text edits (set settings /
			// label) co-exist in one PATCH atomically — `update_layout_data`
			// fires once at the end, so the batch is all-or-nothing.
			if ( 'splice' === $op ) {
				$relative           = substr( $update['path'], strlen( 'settings.' ) );
				$insert             = isset( $update['insert'] ) && is_array( $update['insert'] )
					? $this->sanitize_splice_insert( $update['insert'] )
					: [];
				$merged['settings'] = SettingsArrayMutator::splice_by_path(
					$merged['settings'],
					$relative,
					(int) $update['start'],
					(int) $update['deleteCount'],
					$insert,
				);
				continue;
			}

			if ( 'reorder' === $op ) {
				$relative           = substr( $update['path'], strlen( 'settings.' ) );
				$merged['settings'] = SettingsArrayMutator::reorder_by_path(
					$merged['settings'],
					$relative,
					(int) $update['from'],
					(int) $update['to'],
				);
				continue;
			}

			// op === 'set' falls through to the existing typed/untyped pipeline.
			[ $path, $value, $type ] = $validated;

			if ( 'template' === $path ) {
				// Template is code (Mustache HTML), not user content. Mirror Mode A
				// (BB settings-panel save) which never kses the template — capable
				// users keep verbatim HTML (including <form>, <input>, <script>),
				// and SaveGuard's `preserve_code_fields_in_layout` (fired inside
				// `update_layout_data` below) reverts restricted users' incoming
				// template to the DB copy regardless. The previous wp_kses_post
				// pass was dead code for restricted users and stripped legitimate
				// form markup for capable users.
				$merged['template'] = $value;
				continue;
			}

			// Sanitize at the REST boundary. The form schema isn't reliably
			// populated for AI-generated DS blocks (`ds_block_data.form` is
			// usually absent), so SaveGuard's per-type sanitization on the
			// `update_layout_data` filter below no-ops on most production
			// traffic — REST-boundary sanitization is the load-bearing pass.
			//
			// Typed updates (the client passes `type` as part of the update)
			// route through SettingsSanitizer's per-type logic so SVG goes
			// to enshrined/svg-sanitize and compound link/image objects walk
			// their sub-fields. Untyped updates fall back to the legacy
			// wp_kses_post path so older clients and any caller that hasn't
			// adopted the type field continue to work unchanged.
			if ( null !== $type ) {
				$altered = 0;
				$value   = $this->sanitizer->sanitize_value( $value, $type, $altered );
			} else {
				$value = $this->sanitize_inline_text_value( $value );
			}

			if ( 'label' === $path ) {
				$merged['label'] = $value;
				continue;
			}

			// Bound-repeater inner-field edits target `bindings.<key>.defaults.<field>`
			// per `commit-channel.maybeRewriteForBoundRepeater`. The path regex
			// allowlists only that exact sub-shape; `source` / `config` /
			// `connections` are managed through the settings panel, not this endpoint.
			if ( 0 === strpos( $path, 'bindings.' ) ) {
				if ( ! isset( $merged['bindings'] ) || ! is_array( $merged['bindings'] ) ) {
					$merged['bindings'] = [];
				}
				$relative           = substr( $path, strlen( 'bindings.' ) );
				$merged['bindings'] = $this->set_by_path( $merged['bindings'], $relative, $value );
				continue;
			}

			// path has been validated as /^settings\.[A-Za-z0-9_.]+$/.
			$relative           = substr( $path, strlen( 'settings.' ) );
			$merged['settings'] = $this->set_by_path( $merged['settings'], $relative, $value );
		}

		$node->settings->ds_block_data = wp_json_encode( $merged );
		$data[ $node_id ]              = $node;

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );

		// Re-read after the write so the response reflects whatever
		// SaveGuard's defense-in-depth may have re-sanitized or preserved.
		$final_data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		$final_node = $final_data[ $node_id ] ?? null;

		return new \WP_REST_Response( [
			'node_id'  => $node_id,
			'settings' => $final_node ? $final_node->settings : null,
		], 200 );
	}

	/**
	 * Permission callback for the inline text-update endpoint. Matches the
	 * `PageOverrideProvider::write_overrides_permission_callback` shape — any
	 * logged-in user who can edit the target post is allowed through;
	 * sanitization inside SaveGuard handles restricted-user hardening.
	 *
	 * @param  \WP_REST_Request $request
	 * @return bool
	 */
	public function edit_post_permission_callback( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$body    = $request->get_json_params();
		$post_id = isset( $body['post_id'] ) ? absint( $body['post_id'] ) : 0;
		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Type whitelist for typed update payloads. Mirrors what the canvas
	 * editors emit today (link/url-popover, image/toolbar Replace, svg
	 * Upload + Paste). New entries are added per editor as new editors
	 * land — don't pre-whitelist sanitizer types beyond what an editor
	 * actually emits today.
	 *
	 * Compound types take an array value; the rest take a string value.
	 */
	private const TYPED_COMPOUND_TYPES = [ 'link', 'image' ];
	private const TYPED_STRING_TYPES   = [ 'url', 'svg' ];

	/**
	 * Validate a single update. Dispatches on the `op` field to per-op
	 * validators; `set` validation runs inline (it's the original contract),
	 * while `splice` and `reorder` delegate to {@see SettingsArrayMutator}'s
	 * per-op helpers so the bounds-against-live-array checks live alongside
	 * the helpers that consume those bounds.
	 *
	 * Returns a 3-tuple `[ $path, $value, $type|null ]` for set ops so the
	 * apply loop can destructure without re-reading the update object. For
	 * splice/reorder returns a sentinel `[ null, null, null ]` (the apply
	 * loop reads the op directly off the update for those kinds).
	 *
	 * `$settings` is the current merged settings tree — splice/reorder
	 * validation bounds-checks indices against the live array.
	 *
	 * @param  mixed $update   The incoming update item.
	 * @param  array $settings Current settings tree (for bounds checks).
	 * @return array|\WP_Error
	 */
	private function validate_text_update( $update, array $settings = [] ) {
		if ( ! is_array( $update ) ) {
			return new \WP_Error(
				'invalid_update',
				'Each update must be an object with op, path, and value keys.',
				[ 'status' => 400 ],
			);
		}

		$op = $update['op'] ?? null;

		$allowed_ops = [ 'set', 'splice', 'reorder' ];
		if ( ! is_string( $op ) || ! in_array( $op, $allowed_ops, true ) ) {
			return new \WP_Error(
				'invalid_op',
				sprintf( 'Update op must be one of: %s.', implode( ', ', $allowed_ops ) ),
				[ 'status' => 400 ],
			);
		}

		if ( 'splice' === $op ) {
			$error = SettingsArrayMutator::validate_splice_op( $update, $settings );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			return [ null, null, null ];
		}

		if ( 'reorder' === $op ) {
			$error = SettingsArrayMutator::validate_reorder_op( $update, $settings );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			return [ null, null, null ];
		}

		// op === 'set' — validate path + value + optional type.
		$path  = $update['path'] ?? '';
		$value = $update['value'] ?? null;
		$type  = isset( $update['type'] ) && is_string( $update['type'] ) ? $update['type'] : null;

		if ( ! is_string( $path ) || '' === $path ) {
			return new \WP_Error(
				'invalid_path',
				'Update path must be a non-empty string.',
				[ 'status' => 400 ],
			);
		}

		// Path regex allows four top-level shapes:
		//   - `settings.<key>` (any depth of dot-separated identifiers)
		//   - `bindings.<key>.defaults.<field>` (bound-repeater inner-field edits;
		//     `source` / `config` / `connections` stay off-limits — those are
		//     managed by the settings panel through different write paths)
		//   - `label` (the block's user-visible label)
		//   - `template` (the full Mustache HTML)
		// Other top-level keys (`css`, `js`, `form`, `type`) are blocked —
		// structural edits only target the template + settings + bindings.defaults +
		// label surfaces.
		if ( ! preg_match( '/^(settings\.[A-Za-z0-9_.]+|bindings\.[A-Za-z0-9_]+\.defaults\.[A-Za-z0-9_]+|label|template)$/', $path ) ) {
			return new \WP_Error(
				'invalid_path',
				sprintf( 'Path "%s" is not allowed.', $path ),
				[ 'status' => 400 ],
			);
		}

		if ( 'template' === $path ) {
			$error = SettingsArrayMutator::validate_template_set_op( $update );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			return [ $path, $value, null ];
		}

		if ( null === $type ) {
			// Untyped path: preserve the original string-only contract for
			// backwards compatibility with any caller not yet updated.
			if ( ! is_string( $value ) ) {
				return new \WP_Error(
					'invalid_value',
					'Update value must be a string.',
					[ 'status' => 400 ],
				);
			}
			return [ $path, $value, null ];
		}

		if ( in_array( $type, self::TYPED_COMPOUND_TYPES, true ) ) {
			if ( ! is_array( $value ) ) {
				return new \WP_Error(
					'invalid_value',
					sprintf( 'Update value for type "%s" must be an array.', $type ),
					[ 'status' => 400 ],
				);
			}
			return [ $path, $value, $type ];
		}

		if ( in_array( $type, self::TYPED_STRING_TYPES, true ) ) {
			if ( ! is_string( $value ) ) {
				return new \WP_Error(
					'invalid_value',
					sprintf( 'Update value for type "%s" must be a string.', $type ),
					[ 'status' => 400 ],
				);
			}
			return [ $path, $value, $type ];
		}

		return new \WP_Error(
			'invalid_type',
			sprintf( 'Update type "%s" is not supported.', $type ),
			[ 'status' => 400 ],
		);
	}

	/**
	 * Recursively sanitize the inserted items for a splice op. The form
	 * schema isn't reliably populated for AI-generated DS blocks (the same
	 * reason the typed `set` path is the load-bearing sanitization pass at
	 * the REST boundary), so we conservatively run `wp_kses_post` on every
	 * string leaf. Arrays / objects are walked recursively; scalars other
	 * than strings pass through unchanged.
	 *
	 * Defense-in-depth: SaveGuard's `fl_builder_before_update_layout_data`
	 * filter still fires inside `update_layout_data`, so this isn't the only
	 * sanitization pass on the value.
	 *
	 * @param  array $insert
	 * @return array
	 */
	private function sanitize_splice_insert( array $insert ): array {
		return array_map( fn( $item ) => $this->sanitize_recursive( $item ), $insert );
	}

	/**
	 * Recursive string-leaf sanitizer used by `sanitize_splice_insert`.
	 *
	 * @param  mixed $value
	 * @return mixed
	 */
	private function sanitize_recursive( $value ) {
		if ( is_string( $value ) ) {
			return $this->sanitize_inline_text_value( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( fn( $item ) => $this->sanitize_recursive( $item ), $value );
		}
		return $value;
	}

	/**
	 * Sanitize an inline-text update value with `wp_kses_post`, mirroring
	 * {@see \FL\DesignSystem\Settings\SettingsSanitizer}'s
	 * `style=""` stripping so this endpoint's output matches what a save
	 * through the settings panel would produce.
	 *
	 * @param  string $value
	 * @return string
	 */
	private function sanitize_inline_text_value( string $value ): string {
		add_filter( 'safe_style_css', '__return_empty_array' );
		$sanitized = wp_kses_post( $value );
		remove_filter( 'safe_style_css', '__return_empty_array' );
		return $sanitized;
	}

	/**
	 * Immutable setByPath on a PHP array using dot/index segments.
	 *
	 * Mirrors the JS helper in `canvas-editing/path-splice.js` so the server
	 * and client resolve `items.0.label` the same way. Numeric segments index
	 * into arrays; non-numeric segments key into associative arrays.
	 *
	 * @param  array  $source  Starting array (treated as immutable input).
	 * @param  string $path    Dot-separated path (e.g. `items.0.label`).
	 * @param  mixed  $value   Value to set at the terminal segment.
	 * @return array The modified copy.
	 */
	private function set_by_path( array $source, string $path, $value ): array {
		if ( '' === $path ) {
			return $source;
		}
		$segments = array_values( array_filter( explode( '.', $path ), static fn( $s ) => '' !== $s ) );
		if ( empty( $segments ) ) {
			return $source;
		}

		$root    = $source;
		$ref     =& $root;
		$last_ix = count( $segments ) - 1;

		foreach ( $segments as $i => $segment ) {
			$key = ctype_digit( $segment ) ? (int) $segment : $segment;
			if ( $i === $last_ix ) {
				$ref[ $key ] = $value;
				break;
			}
			if ( ! isset( $ref[ $key ] ) || ! is_array( $ref[ $key ] ) ) {
				$next_segment = $segments[ $i + 1 ];
				$ref[ $key ]  = ctype_digit( $next_segment ) ? [] : [];
			}
			$ref =& $ref[ $key ];
		}
		unset( $ref );
		return $root;
	}

	/**
	 * Decode a `ds_block_data` value into an associative array.
	 *
	 * Mirrors `SaveGuard::decode_block_data`: BB's AJAX deserializer can hand
	 * back nested JSON as stdClass rather than a flat string, and `get_node`
	 * may surface the value through BB's settings cache in either shape
	 * depending on the write path that produced it. Arrays pass through,
	 * objects round-trip through json_encode to normalize to an associative
	 * array, and strings are json-decoded with a wp_unslash fallback.
	 *
	 * @param  mixed $raw
	 * @return array|null
	 */
	private function decode_block_data( $raw ): ?array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_object( $raw ) ) {
			$round_tripped = json_decode( wp_json_encode( $raw ), true );
			return is_array( $round_tripped ) ? $round_tripped : null;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = json_decode( wp_unslash( $raw ), true );
		}
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Return the per-instance data and resolved module definition for a node.
	 *
	 * Thin wrapper over {@see build_node_detail_payload()} so the singular GET
	 * route's response shape is the BC contract — the batch route reuses the
	 * same payload builder per node.
	 *
	 * @param  array                       $data    Layout data.
	 * @param  string                      $node_id Node ID.
	 * @return \WP_Error|\WP_REST_Response
	 */
	private function get_node_detail( array $data, string $node_id ) {
		$payload = $this->build_node_detail_payload( $data, $node_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Build the per-node detail payload.
	 *
	 * Returns the same shape the singular GET route serializes:
	 *  - DS blocks: `[ 'moduleData' => [...], 'module' => [...] ]`
	 *  - Native modules: `[ 'moduleData' => [...] ]`
	 *
	 * Used by both `get_node_detail` (single-node mode of the GET route) and
	 * `get_layout_batch` (POST batch). Keeping a single helper means both
	 * paths return the exact same per-node payload.
	 *
	 * @param  array            $data    Layout data.
	 * @param  string           $node_id Node ID.
	 * @return array|\WP_Error  Payload array on success, WP_Error on failure.
	 */
	private function build_node_detail_payload( array $data, string $node_id ) {
		if ( ! isset( $data[ $node_id ] ) ) {
			return new \WP_Error( 'node_not_found', 'Node not found.', [ 'status' => 404 ] );
		}

		$node        = $data[ $node_id ];
		$module_type = $node->settings->type ?? '';

		if ( ! $this->block_service->is_ds_module( $module_type ) ) {
			// Native module — return code fields plus the restricted, allow-listed
			// editable text fields map (mirrors BlockService::format_native_block_data
			// so read_block include: ['settings'] works on natives end-to-end).
			$editable_text_fields = $this->block_service->get_editable_text_fields( $node );

			return [
				'moduleData' => [
					'type'           => $module_type,
					'native'         => true,
					'settings'       => [
						'bb_css_code' => $node->settings->bb_css_code ?? '',
						'bb_js_code'  => $node->settings->bb_js_code ?? '',
					],
					'editableFields' => $editable_text_fields,
				],
			];
		}

		$module_data = $this->layout->get_module_data( $node );

		// Read-time normalization: split legacy `{ source, ... }` data-source
		// objects out of `settings` into a sibling `bindings` slot so callers
		// observe the new shape regardless of when the data was last saved.
		// Idempotent — running it on already-normalized data is a no-op.
		$module_data = $this->bindings_normalizer->normalize( $module_data );

		$definition = $module_data;

		// Block data fields are at the top level of ds_block_data
		if ( empty( $definition['template'] ) && empty( $definition['label'] ) ) {
			return new \WP_Error(
				'module_not_found',
				'Block data not found.',
				[ 'status' => 404 ],
			);
		}

		$response_settings = (array) ( $module_data['settings'] ?? [] );

		// Populate code fields so code editors show current values
		if ( ! array_key_exists( 'code_html', $response_settings ) && isset( $definition['template'] ) ) {
			$response_settings['code_html'] = $definition['template'];
		}
		if ( ! array_key_exists( 'code_css', $response_settings ) && isset( $definition['css'] ) ) {
			$response_settings['code_css'] = $definition['css'];
		}
		if ( ! array_key_exists( 'code_js', $response_settings ) && isset( $definition['js'] ) ) {
			$response_settings['code_js'] = $definition['js'];
		}

		// Return flat fields in moduleData — no inlineModule wrapper
		$response_data = [
			'label'    => $definition['label'] ?? '',
			'template' => $definition['template'] ?? '',
			'css'      => $definition['css'] ?? '',
			'js'       => $definition['js'] ?? '',
			'settings' => $response_settings,
			'bindings' => isset( $module_data['bindings'] ) && is_array( $module_data['bindings'] )
				? $module_data['bindings']
				: (object) [],
		];

		return [
			'moduleData' => $response_data,
			'module'     => [
				'label'    => $definition['label'] ?? '',
				'form'     => $definition['form'] ?? [],
				'template' => $definition['template'] ?? '',
				'css'      => $definition['css'] ?? '',
				'js'       => $definition['js'] ?? '',
			],
		];
	}

	/**
	 * Read up to BATCH_NODE_CAP nodes in a single REST call.
	 *
	 * Permission + post validation runs once; `FLBuilderModel::get_layout_data`
	 * runs once. Per-node errors are returned in the results array so a
	 * partial-success response is still HTTP 200 — mirrors the JS-side
	 * `read_blocks` per-entry contract.
	 *
	 * Body shape: `{ post_id: int, nodes: [ { node_id: string }, ... ] }`.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_layout_batch( \WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$post_id = $body['post_id'] ?? null;
		$nodes   = $body['nodes'] ?? null;

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( ! is_array( $nodes ) || empty( $nodes ) ) {
			return new \WP_Error(
				'invalid_nodes',
				'nodes must be a non-empty array of { node_id } entries.',
				[ 'status' => 400 ],
			);
		}

		if ( count( $nodes ) > self::BATCH_NODE_CAP ) {
			return new \WP_Error(
				'too_many_nodes',
				sprintf( 'At most %d nodes per batch.', self::BATCH_NODE_CAP ),
				[ 'status' => 400 ],
			);
		}

		foreach ( $nodes as $i => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['node_id'] ) || ! is_string( $entry['node_id'] ) || '' === $entry['node_id'] ) {
				return new \WP_Error(
					'invalid_node_entry',
					sprintf( 'nodes[%d].node_id is required and must be a non-empty string.', $i ),
					[ 'status' => 400 ],
				);
			}
		}

		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$results = [];
		foreach ( $nodes as $entry ) {
			$node_id = $entry['node_id'];
			$payload = $this->build_node_detail_payload( $data, $node_id );

			if ( is_wp_error( $payload ) ) {
				$status_data = $payload->get_error_data();
				$results[]   = [
					'nodeId' => $node_id,
					'error'  => [
						'code'    => $payload->get_error_code(),
						'message' => $payload->get_error_message(),
						'status'  => is_array( $status_data ) && isset( $status_data['status'] ) ? $status_data['status'] : 404,
					],
				];
				continue;
			}

			$results[] = array_merge( [ 'nodeId' => $node_id ], $payload );
		}

		return new \WP_REST_Response( [ 'results' => $results ], 200 );
	}

	/**
	 * Return the descendant tree of a given parent node.
	 *
	 * @param  array                       $data      Layout data.
	 * @param  string                      $parent_id Parent node ID.
	 * @param  bool                        $recurse   When true (default), include the full
	 *                                                recursive subtree; when false, only direct
	 *                                                children with no `children` arrays.
	 * @return \WP_Error|\WP_REST_Response
	 */
	private function get_children_overview( array $data, string $parent_id, bool $recurse = true ) {
		if ( ! isset( $data[ $parent_id ] ) ) {
			return new \WP_Error( 'node_not_found', 'Parent node not found.', [ 'status' => 404 ] );
		}

		$tree  = $this->layout->collect_descendant_tree( $data, $parent_id );
		$nodes = $this->summarize_tree( $data, $tree, $recurse );

		return new \WP_REST_Response( [ 'nodes' => $nodes ], 200 );
	}

	/**
	 * Summarize a descendant tree into the API response format.
	 *
	 * When $recurse is true, each node carries a `children` array with nested
	 * summaries. When false, only direct children are returned and no
	 * `children` arrays are emitted.
	 *
	 * @param  array $data    Full layout data (for module lookups).
	 * @param  array $tree    Nested tree from LayoutManager::collect_descendant_tree().
	 * @param  bool  $recurse Whether to include nested descendants.
	 * @return array Summarized nodes.
	 */
	private function summarize_tree( array $data, array $tree, bool $recurse = true ): array {
		$nodes = [];

		foreach ( $tree as $entry ) {
			$child      = $entry['node'];
			$child_type = $child->type ?? '';
			$summary    = null;

			if ( 'module' === $child_type ) {
				$summary = $this->summarize_node( $child );
			} elseif ( in_array( $child_type, [ 'row', 'column-group', 'column' ], true ) ) {
				$module = $this->layout->find_module_in_row( $data, $child->node );
				if ( $module ) {
					$summary = $this->summarize_node( $module );
				} else {
					$global_label = $this->is_global_node( $child ) ? ' (global)' : '';
					$summary      = [
						'nodeId'     => $child->node,
						'type'       => 'native',
						'moduleType' => $child_type . $global_label,
					];
				}
			} else {
				$global_label = $this->is_global_node( $child ) ? ' (global)' : '';
				$summary      = [
					'nodeId'     => $child->node,
					'type'       => 'native',
					'moduleType' => ( $child_type ?: 'unknown' ) . $global_label,
				];
			}

			if ( $recurse ) {
				$summary['children'] = $this->summarize_tree( $data, $entry['children'], true );
			}
			$nodes[] = $summary;
		}

		return $nodes;
	}

	/**
	 * Summarize a layout node for the overview response.
	 *
	 * @param  object $node The layout node.
	 * @return array  Summary with nodeId, type, and module-specific details.
	 */
	private function summarize_node( $node ) {
		$module_type  = $node->settings->type ?? '';
		$global_label = $this->is_global_node( $node ) ? ' (global)' : '';

		if ( ! $this->block_service->is_ds_module( $module_type ) ) {
			$bb_type = $node->type ?? '';
			if ( 'row' === $bb_type ) {
				$detected_type = 'row';
			} elseif ( 'column' === $bb_type ) {
				$detected_type = 'column';
			} else {
				$detected_type = 'native';
			}

			$summary = [
				'nodeId'     => $node->node,
				'type'       => 'native',
				'nodeType'   => $detected_type,
				'moduleType' => $module_type . $global_label,
			];

			if ( 'native' === $detected_type ) {
				$preview = $this->get_native_content_preview( $node );
				if ( $preview ) {
					$summary['contentPreview'] = $preview;
				}
			}

			return $summary;
		}

		$module_data = $this->layout->get_module_data( $node );

		$resolved = $module_data;

		$template_type = $resolved['type'] ?? ( $resolved['label'] ?? 'section' );
		$settings      = $module_data['settings'] ?? [];
		$has_css       = ! empty( $resolved['css'] );

		// Build a compact settings summary (first 4 non-empty string values)
		$summary = [];
		$count   = 0;
		foreach ( $settings as $key => $value ) {
			if ( $count >= 4 ) {
				break;
			}
			if ( ! is_string( $value ) || empty( $value ) ) {
				continue;
			}
			$clean = wp_strip_all_tags( $value );
			if ( mb_strlen( $clean ) > 60 ) {
				$clean = mb_substr( $clean, 0, 57 ) . '...';
			}
			$summary[ $key ] = $clean;
			$count++;
		}

		return [
			'nodeId'       => $node->node,
			'type'         => $module_type,
			'nodeType'     => 'ds-block-inline',
			'templateType' => $template_type . $global_label,
			'settings'     => $summary,
			'hasCss'       => $has_css,
		];
	}

	/**
	 * Extracts a content preview from a native BB module's settings.
	 *
	 * Looks for common text fields (heading, text, html, etc.) and returns
	 * the first non-empty value, truncated to 60 characters.
	 *
	 * @param  object $node Layout node object.
	 * @return string|null  Preview text or null.
	 */
	private function get_native_content_preview( $node ) {
		$settings = $node->settings ?? null;
		if ( ! $settings ) {
			return null;
		}

		$text_fields = [ 'heading', 'text', 'title', 'html', 'caption', 'label', 'content' ];
		foreach ( $text_fields as $field ) {
			$value = $settings->$field ?? '';
			if ( ! empty( $value ) && is_string( $value ) ) {
				$clean = wp_strip_all_tags( $value );
				if ( '' === $clean ) {
					continue;
				}
				if ( mb_strlen( $clean ) > 60 ) {
					$clean = mb_substr( $clean, 0, 57 ) . '...';
				}
				return $clean;
			}
		}
		return null;
	}

	/**
	 * Check if a layout node is a global node (linked to a master template).
	 *
	 * Returns false for the root node when editing its master template directly,
	 * since that node IS the template being edited and should remain editable.
	 * Nested globals inside a master template are still labeled.
	 *
	 * @param  object $node Layout node object from FLBuilderModel::get_layout_data().
	 * @return bool
	 */
	private function is_global_node( $node ): bool {
		if ( ! \FLBuilderModel::is_node_global( $node ) ) {
			return false;
		}

		// When editing a node template, the root node is the template itself — not a nested global.
		if ( \FLBuilderModel::is_post_node_template() && \FLBuilderModel::is_node_template_root( $node ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve a row node to its first column for container insertion.
	 *
	 * When the parent is a row, finds the first column-group child (by position),
	 * then the first column child of that group. Returns the column node ID.
	 * For non-row nodes, returns the parent_id unchanged.
	 *
	 * @param  array             $data      Layout data.
	 * @param  string            $parent_id The target parent node ID.
	 * @return string|\WP_Error  Resolved parent node ID, or WP_Error on failure.
	 */
	private function resolve_row_to_column( array $data, string $parent_id ) {
		if ( ! isset( $data[ $parent_id ] ) ) {
			return new \WP_Error(
				'parent_not_found',
				sprintf( 'Parent node "%s" not found in layout.', $parent_id ),
				[ 'status' => 404 ],
			);
		}

		$parent_type = $data[ $parent_id ]->type ?? '';
		if ( 'row' !== $parent_type ) {
			return $parent_id;
		}

		// Find first column-group child of the row
		$col_group = $this->find_first_child_by_type( $data, $parent_id, 'column-group' );
		if ( ! $col_group ) {
			return new \WP_Error(
				'no_column_found',
				'Row has no column-group — cannot insert into this row.',
				[ 'status' => 400 ],
			);
		}

		// Find first column child of the column-group
		$column = $this->find_first_child_by_type( $data, $col_group->node, 'column' );
		if ( ! $column ) {
			return new \WP_Error(
				'no_column_found',
				'Column-group has no column — cannot insert into this row.',
				[ 'status' => 400 ],
			);
		}

		return $column->node;
	}

	/**
	 * Find the first child node of a given type (by lowest position).
	 *
	 * @param  array       $data      Layout data.
	 * @param  string      $parent_id Parent node ID.
	 * @param  string      $type      Node type to match.
	 * @return object|null The first matching child, or null.
	 */
	private function find_first_child_by_type( array $data, string $parent_id, string $type ) {
		$best     = null;
		$best_pos = PHP_INT_MAX;

		foreach ( $data as $node ) {
			if ( ( $node->parent ?? '' ) !== $parent_id ) {
				continue;
			}
			if ( ( $node->type ?? '' ) !== $type ) {
				continue;
			}
			$pos = $node->position ?? 0;
			if ( $pos < $best_pos ) {
				$best     = $node;
				$best_pos = $pos;
			}
		}

		return $best;
	}

	/**
	 * Resolve the BB module type slug.
	 *
	 * All DS blocks use the single 'ds-block' module type.
	 *
	 * @return string The module type slug.
	 */
	private function resolve_module_type(): string {
		return $this->module_namespace . '-block';
	}

	/**
	 * Validate post_id exists and user has edit permission.
	 *
	 * @param  int|null       $post_id
	 * @return \WP_Error|null Null on success, WP_Error on failure.
	 */
	private function validate_post( $post_id ) {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'invalid_post',
				'Invalid or missing post_id.',
				[ 'status' => 400 ],
			);
		}

		if ( ! $this->auth->can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'forbidden',
				'You do not have permission to edit this post.',
				[ 'status' => 403 ],
			);
		}

		return null;
	}
}
