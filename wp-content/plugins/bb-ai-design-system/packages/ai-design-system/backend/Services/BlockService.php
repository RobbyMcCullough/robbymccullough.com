<?php

namespace FL\DesignSystem\Services;

use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\Services\Parser\AnnotationReconstructor;

/**
 * Shared service for block type detection, reading, and writing.
 *
 * Used by both McpProvider and BeaverBuilderRestController to avoid
 * duplicating type detection, validation, and update logic.
 */
class BlockService {

	/**
	 * Static map of field capabilities per block type, surfaced verbatim on every
	 * block read so agents can discover what is readable and writable without parsing
	 * tool descriptions.
	 *
	 * Vocabulary:
	 *   'read-write' -- field is included in get-page-blocks responses and accepted
	 *                   by update-page-blocks operations.
	 *   'read'       -- field is included in get-page-blocks responses but rejected
	 *                   by update-page-blocks (e.g. native module HTML, which is
	 *                   rendered output rather than editable source).
	 *   'none'       -- field is neither readable nor writable for this block type.
	 *
	 * This is documentation, not access control -- the write-side rejection in
	 * update_native_block / update_structural_node is the enforcement boundary
	 * for read-only fields, and SaveGuard remains the authoritative auth boundary.
	 */
	private const CAPABILITIES_BY_TYPE = [
		'ds-custom' => [
			'html'     => 'read-write',
			'css'      => 'read-write',
			'js'       => 'read-write',
			'settings' => 'read-write',
		],
		'native'    => [
			'html'     => 'read',
			'css'      => 'read-write',
			'js'       => 'read-write',
			'settings' => 'read-write',
		],
		'row'       => [
			'html'     => 'read',
			'css'      => 'read-write',
			'js'       => 'read-write',
			'settings' => 'none',
		],
		'column'    => [
			'html'     => 'read',
			'css'      => 'read-write',
			'js'       => 'read-write',
			'settings' => 'none',
		],
	];

	/**
	 * Field keys that are filterable by the `include` parameter on get_block_data.
	 *
	 * Identity keys (block_type, label, module_type, is_global, capabilities) are
	 * never filtered out -- only these optional payload keys are.
	 */
	private const INCLUDE_FILTERABLE_KEYS = [ 'html', 'css', 'js', 'settings' ];

	private string $module_namespace;

	private BindingsNormalizer $bindings_normalizer;

	/**
	 * @param string $module_namespace Module namespace prefix (e.g. 'ds').
	 */
	public function __construct( string $module_namespace = 'ds' ) {
		$this->module_namespace    = $module_namespace;
		$this->bindings_normalizer = new BindingsNormalizer();
	}

	/**
	 * Check if a module type slug belongs to the design system.
	 *
	 * @param string $type Module type slug.
	 * @return bool
	 */
	public function is_ds_module( string $type ): bool {
		return str_starts_with( $type, $this->module_namespace . '-' );
	}

	/**
	 * Detect the block type category for a BB module node.
	 *
	 * @param object $module Layout node object.
	 * @return string One of 'ds-custom', 'native', or 'unknown'.
	 */
	public function detect_block_type( object $module ): string {
		$module_type = $module->settings->type ?? '';

		if ( ! $this->is_ds_module( $module_type ) ) {
			if ( ( $module->type ?? '' ) === 'module' ) {
				return 'native';
			}
			return 'unknown';
		}

		return 'ds-custom';
	}

	/**
	 * Check if a node is a BB global node (shared template).
	 *
	 * @param array  $data    Layout data array.
	 * @param string $node_id Node identifier.
	 * @return bool
	 */
	public function is_global_node( array $data, string $node_id ): bool {
		if ( ! isset( $data[ $node_id ] ) ) {
			return false;
		}

		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return false;
		}

		return (bool) \FLBuilderModel::is_node_global( $data[ $node_id ] );
	}

	/**
	 * Read block data for any block type.
	 *
	 * Dispatches by detected type: DS blocks use the adapter's export_section(),
	 * native BB modules return a restricted settings view, CSS, JS, and global status.
	 *
	 * @param int                        $post_id Post ID.
	 * @param string                     $node_id Block/node identifier.
	 * @param PageEditorAdapterInterface $adapter Editor adapter.
	 * @param array|null                 $include Optional list of payload keys to return
	 *                                            (any of html/css/js/settings).
	 *                                            Null or empty array returns the full payload.
	 *                                            Identity fields and the capabilities band are
	 *                                            always returned regardless of this filter.
	 * @return array|\WP_Error Block data array, or WP_Error on failure.
	 */
	public function get_block_data( int $post_id, string $node_id, PageEditorAdapterInterface $adapter, ?array $include = null ): array|\WP_Error {
		// Try DS block export first.
		$section = $adapter->export_section( $post_id, $node_id );

		if ( $section ) {
			return $this->format_ds_block_data( $section, $include );
		}

		// Not a DS block -- try native BB module.
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return new \WP_Error(
				'node_not_found',
				'No block found with the given node_id.',
				[ 'status' => 404 ]
			);
		}

		$data = \FLBuilderModel::get_layout_data( 'published', $post_id );
		if ( ! is_array( $data ) || empty( $data ) ) {
			$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		}

		if ( ! is_array( $data ) || ! isset( $data[ $node_id ] ) ) {
			return new \WP_Error(
				'node_not_found',
				'No block found with the given node_id.',
				[ 'status' => 404 ]
			);
		}

		$module    = $data[ $node_id ];
		$node_type = $module->type ?? '';

		// Structural nodes: rows and columns support CSS/JS plus read-only HTML.
		if ( 'row' === $node_type || 'column' === $node_type ) {
			return $this->format_structural_node_data( $post_id, $module, $include );
		}

		// Reject column-groups — they're internal BB nodes.
		if ( 'column-group' === $node_type ) {
			return new \WP_Error(
				'not_targetable',
				'Column groups are internal BB nodes and cannot be targeted directly.',
				[ 'status' => 400 ]
			);
		}

		if ( 'module' !== $node_type ) {
			return new \WP_Error(
				'not_a_module',
				'The specified node is not a module.',
				[ 'status' => 400 ]
			);
		}

		$module_type = $module->settings->type ?? '';

		if ( $this->is_ds_module( $module_type ) ) {
			return new \WP_Error(
				'node_not_found',
				'No block found with the given node_id.',
				[ 'status' => 404 ]
			);
		}

		return $this->format_native_block_data( $post_id, $data, $module, $include );
	}

	/**
	 * Format DS block data for the get-page-blocks response.
	 *
	 * @param array      $section Exported section data from the adapter.
	 * @param array|null $include Optional include filter; see get_block_data().
	 * @return array Formatted block data.
	 */
	private function format_ds_block_data( array $section, ?array $include = null ): array {
		// Normalize legacy data-source object-shape settings into the
		// `settings + bindings` split. Idempotent on already-normalized data.
		$section = $this->bindings_normalizer->normalize( $section );

		$template = $section['template'] ?? '';
		$settings = $section['settings'] ?? [];
		$bindings = isset( $section['bindings'] ) && is_array( $section['bindings'] )
			? $section['bindings']
			: [];

		$html = AnnotationReconstructor::reconstruct( $template, $settings );

		$data = [
			'block_type'   => 'ds-custom',
			'label'        => $section['label'] ?? '',
			'html'         => $html ?? '',
			'css'          => $section['css'] ?? '',
			'js'           => $section['js'] ?? '',
			'settings'     => $settings,
			'bindings'     => $bindings,
			'capabilities' => $this->get_capabilities_for_type( 'ds-custom' ),
		];

		return $this->apply_include_filter( $data, $include );
	}

	/**
	 * Format native BB module data for the get-page-blocks response.
	 *
	 * @param int        $post_id Post ID providing layout context for HTML rendering.
	 * @param array      $data    Layout data array.
	 * @param object     $module  Module layout node.
	 * @param array|null $include Optional include filter; see get_block_data().
	 * @return array Formatted block data.
	 */
	private function format_native_block_data( int $post_id, array $data, object $module, ?array $include = null ): array {
		$module_type = $module->settings->type ?? '';
		$is_global   = $this->is_global_node( $data, $module->node );
		$settings    = $this->get_editable_text_fields( $module );

		$label = $module->settings->node_label ?? '';
		if ( '' === $label ) {
			$label = ucfirst( str_replace( [ '-', '_' ], ' ', $module_type ) );
		}

		$response = [
			'block_type'   => 'native',
			'module_type'  => $module_type,
			'label'        => $label,
			'settings'     => $settings,
			'css'          => $module->settings->bb_css_code ?? '',
			'js'           => $module->settings->bb_js_code ?? '',
			'is_global'    => $is_global,
			'capabilities' => $this->get_capabilities_for_type( 'native' ),
		];

		// HTML is read-only for native modules; populate it as rendered output
		// so agents can ground CSS edits in the actual markup.
		if ( $this->should_render_html( $include ) ) {
			$response['html'] = $this->render_node_html( $post_id, $module );
		}

		return $this->apply_include_filter( $response, $include );
	}

	/**
	 * Format structural node (row or column) data for the get-page-blocks response.
	 *
	 * @param int        $post_id Post ID providing layout context for HTML rendering.
	 * @param object     $node    Layout node object.
	 * @param array|null $include Optional include filter; see get_block_data().
	 * @return array Formatted block data.
	 */
	private function format_structural_node_data( int $post_id, object $node, ?array $include = null ): array {
		$node_type = $node->type ?? '';
		$label     = $node->settings->node_label ?? ucfirst( $node_type );

		$data = [
			'block_type'   => $node_type,
			'label'        => $label,
			'css'          => $node->settings->bb_css_code ?? '',
			'js'           => $node->settings->bb_js_code ?? '',
			'capabilities' => $this->get_capabilities_for_type( $node_type ),
		];

		// HTML is read-only for structural nodes; populate as rendered output
		// (rows include nested columns and modules; columns include their modules).
		if ( $this->should_render_html( $include ) ) {
			$data['html'] = $this->render_node_html( $post_id, $node );
		}

		return $this->apply_include_filter( $data, $include );
	}

	/**
	 * Look up the capabilities map for a given block type.
	 *
	 * Returns an empty map for unrecognized types so callers always get an array.
	 *
	 * @param string $block_type One of 'ds-custom', 'native', 'row', 'column'.
	 * @return array<string, string> Field name => 'read-write' or 'none'.
	 */
	private function get_capabilities_for_type( string $block_type ): array {
		return self::CAPABILITIES_BY_TYPE[ $block_type ] ?? [];
	}

	/**
	 * Strip filterable fields not named in $include.
	 *
	 * Identity fields (block_type, label, module_type, is_global, capabilities) and
	 * any keys outside INCLUDE_FILTERABLE_KEYS are always preserved. When $include
	 * is null or empty the original array is returned unchanged (default behavior).
	 * Unsupported fields per block type (e.g. html on a native) are silently dropped
	 * because they were never in the array to begin with.
	 *
	 * @param array      $data    Block data array.
	 * @param array|null $include Optional include filter.
	 * @return array Filtered data array.
	 */
	private function apply_include_filter( array $data, ?array $include ): array {
		if ( null === $include || empty( $include ) ) {
			return $data;
		}

		foreach ( self::INCLUDE_FILTERABLE_KEYS as $key ) {
			if ( ! in_array( $key, $include, true ) ) {
				unset( $data[ $key ] );
			}
		}

		return $data;
	}

	/**
	 * Decide whether html should be populated for a read response.
	 *
	 * The renderer is the most expensive part of the read path, so we skip it
	 * when html will be filtered out anyway. Default ($include null/empty)
	 * returns the full payload, which includes html.
	 *
	 * @param array|null $include Optional include filter from get_block_data().
	 * @return bool
	 */
	private function should_render_html( ?array $include ): bool {
		if ( null === $include || empty( $include ) ) {
			return true;
		}
		return in_array( 'html', $include, true );
	}

	/**
	 * Render a single layout node to isolated HTML for read-only inspection.
	 *
	 * Bypasses FLBuilderAJAXLayout::render() because (a) it caches partial-refresh
	 * data in a private static across the lifetime of the request, so a second
	 * call within the same get-page-blocks batch returns the first node's HTML;
	 * and (b) it silently falls back to rendering the full layout when a node
	 * doesn't support partial refresh (the base module class default). We call
	 * the underlying render functions directly with output buffering instead.
	 *
	 * Bootstrap requirements (mandatory, not optional):
	 *   - $wp_the_query must exist and have ->post->ID set; FLBuilder::render_module
	 *     and ::render_row dereference this on their first line. We shim a minimal
	 *     post stub when the global is missing or unset (typical for MCP REST callbacks).
	 *   - FLBuilderModel::set_post_id() establishes BB context; reset_post_id() pops it.
	 *   - The fl_builder_is_node_visible filter is forced to true for the duration
	 *     of the render so scheduled/conditionally-displayed nodes still return their
	 *     markup -- the agent is reading for editing context, not public display.
	 *
	 * @param int    $post_id Post ID providing layout context.
	 * @param object $node    Layout node object (module, row, or column).
	 * @return string Rendered HTML, or empty string if rendering failed or the node
	 *                type isn't renderable.
	 */
	private function render_node_html( int $post_id, object $node ): string {
		if ( ! class_exists( 'FLBuilder' ) || ! class_exists( 'FLBuilderModel' ) ) {
			return '';
		}

		$node_type = $node->type ?? '';
		if ( ! in_array( $node_type, [ 'module', 'row', 'column' ], true ) ) {
			return '';
		}

		// Save and shim $wp_the_query. FLBuilder::render_* dereferences
		// $wp_the_query->post->ID unconditionally, which would throw on null
		// from an MCP REST context where the main query has no post.
		global $wp_the_query;
		$query_backup      = $wp_the_query;
		$shimmed_the_query = false;
		if ( ! isset( $wp_the_query ) || ! is_object( $wp_the_query ) || ! isset( $wp_the_query->post ) || ! is_object( $wp_the_query->post ) ) {
			$wp_the_query      = (object) [ 'post' => (object) [ 'ID' => $post_id ] ];
			$shimmed_the_query = true;
		}

		\FLBuilderModel::set_post_id( $post_id );

		// Force is_node_visible to true so hidden/scheduled nodes still render
		// for AI inspection. Scoped to this render call only.
		$visibility_filter = static function () {
			return true;
		};
		add_filter( 'fl_builder_is_node_visible', $visibility_filter, PHP_INT_MAX );

		$html = '';
		try {
			ob_start();
			switch ( $node_type ) {
				case 'module':
					\FLBuilder::render_module( $node );
					break;
				case 'row':
					\FLBuilder::render_row( $node );
					break;
				case 'column':
					\FLBuilder::render_column( $node );
					break;
			}
			$html = ob_get_clean();
			if ( ! is_string( $html ) ) {
				$html = '';
			}
		} catch ( \Throwable $e ) {
			// Clean up the buffer if an exception unwound mid-render.
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			$html = '';
		} finally {
			remove_filter( 'fl_builder_is_node_visible', $visibility_filter, PHP_INT_MAX );
			\FLBuilderModel::reset_post_id();
			if ( $shimmed_the_query ) {
				$wp_the_query = $query_backup;
			}
		}

		// Surface real-world payload sizes in debug logs so we can decide later
		// whether a hard size cap is needed for very large rows.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			error_log( sprintf( '[BlockService] Rendered %s node %s: %d bytes', $node_type, $node->node ?? '?', strlen( $html ) ) );
		}

		return $html;
	}

	/**
	 * Discover editable text fields on a native BB module.
	 *
	 * Uses the same criteria as BB's prep_editables_for_js_config():
	 * field type is not 'code', preview.type is 'text', preview.selector
	 * exists, and inline_editor is not explicitly false.
	 *
	 * @param object $module Layout node object.
	 * @return array Associative array of field_name => field info.
	 */
	public function get_editable_text_fields( object $module ): array {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return [];
		}

		$module_type = $module->settings->type ?? '';
		$bb_modules  = \FLBuilderModel::$modules ?? [];

		if ( ! isset( $bb_modules[ $module_type ] ) ) {
			return [];
		}

		$bb_module = $bb_modules[ $module_type ];
		$fields    = \FLBuilderModel::get_settings_form_fields( $bb_module->form );
		$settings  = $module->settings;

		// Check for field connections.
		$connections = [];
		if ( isset( $settings->connections ) && is_object( $settings->connections ) ) {
			$connections = (array) $settings->connections;
		} elseif ( isset( $settings->connections ) && is_array( $settings->connections ) ) {
			$connections = $settings->connections;
		}

		$result = [];

		foreach ( $fields as $key => $field ) {
			if ( 'code' === ( $field['type'] ?? '' ) ) {
				continue;
			}

			$preview = $field['preview'] ?? [];

			if ( ! isset( $preview['type'] ) || 'text' !== $preview['type'] ) {
				continue;
			}

			if ( ! isset( $preview['selector'] ) ) {
				continue;
			}

			if ( isset( $field['inline_editor'] ) && false === $field['inline_editor'] ) {
				continue;
			}

			$value    = $settings->$key ?? '';
			$editable = true;

			// Check if this field has an active connection.
			$connection = null;
			if ( ! empty( $connections[ $key ] ) ) {
				$conn_data = $connections[ $key ];
				if ( is_object( $conn_data ) ) {
					$conn_data = (array) $conn_data;
				}
				if ( ! empty( $conn_data ) ) {
					$editable   = false;
					$connection = $conn_data['field'] ?? $conn_data['property'] ?? 'connected';
				}
			}

			$entry = [
				'value'    => is_string( $value ) ? $value : '',
				'type'     => ( 'editor' === ( $field['type'] ?? '' ) ) ? 'editor' : 'text',
				'editable' => $editable,
			];

			if ( null !== $connection ) {
				$entry['connection'] = $connection;
			}

			$result[ $key ] = $entry;
		}

		return $result;
	}

	/**
	 * Update block data for any block type.
	 *
	 * DS blocks delegate to the adapter's update_section().
	 * Native BB modules validate fields and write to layout data directly.
	 *
	 * @param int                        $post_id Post ID.
	 * @param string                     $node_id Block/node identifier.
	 * @param array                      $updates Update data (html, css, js, settings).
	 * @param PageEditorAdapterInterface $adapter Editor adapter.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function update_block_data( int $post_id, string $node_id, array $updates, PageEditorAdapterInterface $adapter ): true|\WP_Error {
		// Check if this is a DS block by attempting export.
		$section = $adapter->export_section( $post_id, $node_id );

		if ( $section ) {
			// DS block -- delegate to adapter.
			$adapter_updates = [];

			if ( array_key_exists( 'html', $updates ) ) {
				$adapter_updates['html'] = $updates['html'];
			}
			if ( array_key_exists( 'css', $updates ) ) {
				$adapter_updates['css'] = $updates['css'];
			}
			if ( array_key_exists( 'js', $updates ) ) {
				$adapter_updates['js'] = $updates['js'];
			}
			if ( array_key_exists( 'settings', $updates ) ) {
				$adapter_updates['settings'] = $updates['settings'];
			}

			return $adapter->update_section( $post_id, $node_id, $adapter_updates );
		}

		// Native BB module.
		return $this->update_native_block( $post_id, $node_id, $updates );
	}

	/**
	 * Update a native BB module's settings (restricted view), CSS, and JS.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $node_id Node identifier.
	 * @param array  $updates Update data (settings, css, js).
	 * @return true|\WP_Error
	 */
	private function update_native_block( int $post_id, string $node_id, array $updates ): true|\WP_Error {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return new \WP_Error(
				'not_supported',
				'Native block updates require Beaver Builder.',
				[ 'status' => 400 ]
			);
		}

		$data = \FLBuilderModel::get_layout_data( 'draft', $post_id );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$result = $this->update_native_block_in_data( $data, $node_id, $updates );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		\FLBuilderModel::update_layout_data( $data, 'draft', $post_id );
		\FLBuilderModel::update_layout_data( $data, 'published', $post_id );

		return true;
	}

	/**
	 * Apply native module / structural node updates to an in-memory layout array.
	 *
	 * Pure mutation: validates the node + updates and mutates `$data` in place.
	 * Does NOT call `FLBuilderModel::get_layout_data` or `update_layout_data` —
	 * the caller owns layout I/O. Sister to {@see update_native_block}, which
	 * is the layout-I/O-bearing wrapper used by single-node REST callers; the
	 * batch route loads the layout once, calls this per row, and writes once.
	 *
	 * Dispatches internally on node type:
	 *   - row / column → structural-node CSS/JS apply (settings rejected).
	 *   - module       → native-module CSS/JS/settings apply (with the
	 *                    editable-text-fields allow-list).
	 *   - column-group → rejected (internal BB node).
	 *
	 * @param  array  &$data   Layout data array, mutated in place on success.
	 * @param  string  $node_id Node identifier.
	 * @param  array   $updates Update data (`css`, `js`, `settings`). Already
	 *                          translated from any wire-format keys
	 *                          (e.g. `bb_css_code` → `css`) by the caller.
	 * @return true|\WP_Error  True on success; WP_Error preserves the per-row
	 *                         status code so the batch route can render it as
	 *                         a per-row error.
	 */
	public function update_native_block_in_data( array &$data, string $node_id, array $updates ): true|\WP_Error {
		if ( ! isset( $data[ $node_id ] ) ) {
			return new \WP_Error(
				'node_not_found',
				'No block found with the given node_id.',
				[ 'status' => 404 ]
			);
		}

		$module    = $data[ $node_id ];
		$node_type = $module->type ?? '';

		// Structural nodes: rows and columns support CSS/JS only.
		if ( 'row' === $node_type || 'column' === $node_type ) {
			return $this->update_structural_node_in_data( $module, $updates );
		}

		// Reject column-groups — they're internal BB nodes.
		if ( 'column-group' === $node_type ) {
			return new \WP_Error(
				'not_targetable',
				'Column groups are internal BB nodes and cannot be targeted directly.',
				[ 'status' => 400 ]
			);
		}

		if ( 'module' !== $node_type ) {
			return new \WP_Error(
				'not_a_module',
				'The specified node is not a module.',
				[ 'status' => 400 ]
			);
		}

		$module_type = $module->settings->type ?? '';

		if ( $this->is_ds_module( $module_type ) ) {
			return new \WP_Error(
				'not_native',
				'This is a DS block. Use html/css/js/settings parameters for DS blocks.',
				[ 'status' => 400 ]
			);
		}

		// HTML is read-only on native modules. Reject explicitly so agents that
		// see html in get-page-blocks responses don't mistake readability for
		// writability. The capabilities object on every read reports html as 'read'.
		if ( array_key_exists( 'html', $updates ) ) {
			return new \WP_Error(
				'html_not_writable',
				'HTML is read-only on native BB modules. Use css, js, or settings to edit. The capabilities object on this block reports html as "read".',
				[ 'status' => 400 ]
			);
		}

		// Validate the updates.
		$validation = $this->validate_native_updates( $module, $updates );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Check global node -- warn but allow.
		$is_global = $this->is_global_node( $data, $node_id );

		$this->apply_css_js_updates( $module->settings, $updates );

		// Apply settings updates (restricted to editable text fields).
		$settings = $updates['settings'] ?? [];
		if ( is_array( $settings ) ) {
			foreach ( $settings as $field_name => $value ) {
				$module->settings->$field_name = $value;
			}
		}

		return true;
	}

	/**
	 * Apply structural-node (row/column) CSS/JS updates in memory.
	 *
	 * Rejects settings (rows and columns have no editable settings) and HTML
	 * (rendered HTML on these nodes is for grounding CSS edits, not editing).
	 * Does NOT touch layout I/O — the caller persists.
	 *
	 * @param  object $node    The row/column layout node, mutated in place.
	 * @param  array  $updates Update data (`css`, `js`).
	 * @return true|\WP_Error
	 */
	private function update_structural_node_in_data( object $node, array $updates ): true|\WP_Error {
		// HTML is read-only on rows and columns. Reject explicitly -- the rendered
		// HTML returned by get-page-blocks is for grounding CSS edits, not editing.
		// The capabilities object on every read reports html as 'read'.
		if ( array_key_exists( 'html', $updates ) ) {
			return new \WP_Error(
				'html_not_writable',
				'HTML is read-only on rows and columns. Use css or js to edit. The capabilities object on this block reports html as "read".',
				[ 'status' => 400 ]
			);
		}

		if ( ! empty( $updates['settings'] ) ) {
			return new \WP_Error(
				'invalid_update',
				'Rows and columns do not support settings updates. Only css and js can be updated.',
				[ 'status' => 400 ]
			);
		}

		if ( ! array_key_exists( 'css', $updates ) && ! array_key_exists( 'js', $updates ) ) {
			return new \WP_Error(
				'missing_updates',
				'At least one of css or js is required for row/column updates.',
				[ 'status' => 400 ]
			);
		}

		$this->apply_css_js_updates( $node->settings, $updates );

		return true;
	}

	/**
	 * Apply CSS and JS updates to a node's settings object.
	 *
	 * @param object $settings Node settings object.
	 * @param array  $updates  Update data containing optional 'css' and 'js' keys.
	 */
	private function apply_css_js_updates( object $settings, array $updates ): void {
		if ( array_key_exists( 'css', $updates ) ) {
			$settings->bb_css_code = $updates['css'];
		}
		if ( array_key_exists( 'js', $updates ) ) {
			$settings->bb_js_code = $updates['js'];
		}
	}

	/**
	 * Validate native module updates before applying them.
	 *
	 * Restricts settings keys to the editable-text-fields allow-list, rejects
	 * connected fields, and ensures at least one writable surface is provided.
	 *
	 * @param object $module  Layout node object.
	 * @param array  $updates Proposed updates.
	 * @return true|\WP_Error
	 */
	public function validate_native_updates( object $module, array $updates ): true|\WP_Error {
		$settings = $updates['settings'] ?? [];

		if ( ! is_array( $settings ) || empty( $settings ) ) {
			// Only CSS/JS -- always valid.
			if ( ! array_key_exists( 'css', $updates ) && ! array_key_exists( 'js', $updates ) ) {
				return new \WP_Error(
					'missing_updates',
					'At least one of settings, css, or js is required for native blocks.',
					[ 'status' => 400 ]
				);
			}
			return true;
		}

		$editable_fields = $this->get_editable_text_fields( $module );
		$rejected        = [];

		foreach ( $settings as $field_name => $value ) {
			// Restrict to the editable allow-list (preview.type === 'text', selector,
			// not a code field, inline_editor !== false). Anything else is silently
			// not exposed as writable; reject explicitly here.
			if ( ! array_key_exists( $field_name, $editable_fields ) ) {
				$rejected[] = sprintf( '"%s" is not an editable settings field on this module', $field_name );
				continue;
			}

			// Reject connected fields (the editable-fields filter marks these editable=false).
			if ( empty( $editable_fields[ $field_name ]['editable'] ) ) {
				$rejected[] = sprintf( '"%s" is connected to a dynamic data source and cannot be edited', $field_name );
			}
		}

		if ( ! empty( $rejected ) ) {
			return new \WP_Error(
				'invalid_settings',
				'Some settings could not be updated: ' . implode( '; ', $rejected ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}
}
