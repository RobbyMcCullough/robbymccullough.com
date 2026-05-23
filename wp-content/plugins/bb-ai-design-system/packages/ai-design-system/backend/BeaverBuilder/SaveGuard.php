<?php

namespace FL\DesignSystem\BeaverBuilder;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\Settings\SettingsSanitizer;

/**
 * Preserves the code-bearing fields of a DS block when a restricted user saves.
 *
 * DS blocks store their full definition (template, css, js, form, type) plus
 * user-editable {label, settings} in a single hidden `ds_block_data` JSON
 * field. BB's default save pipeline runs `verify_settings_kses` on the whole
 * settings object and rejects the save if wp_kses would strip anything — an
 * all-or-nothing gate that makes it impossible for users without
 * `unfiltered_html` to save *any* setting change on a DS block whose stored
 * template contains tags kses doesn't allow.
 *
 * This class bridges that gap. For users who don't pass
 * {@see WordPressAuth::user_can_create_content()}, the incoming
 * `ds_block_data` payload is merged with the previously-stored version:
 * the code-bearing fields come from the DB, and only `label` / `settings`
 * are accepted from the user. The result has no new code for kses to strip,
 * so the save proceeds without the modal rejection and the user's settings
 * changes are persisted while the definition is left untouched.
 *
 * Two hooks are registered. `fl_builder_pre_verify_node_settings` is the
 * primary path and handles the AJAX save and its pre-flight verify. The
 * `fl_builder_before_update_layout_data` filter is defense-in-depth for
 * write paths that bypass `save_settings` (history restore, template apply,
 * save-as-node-template, dynamic globals).
 *
 * Intentionally not merged with the block-editor's {@see \FL\DesignSystem\BlockEditor\KsesFallback}
 * — both classes solve the same conceptual problem but operate on entirely
 * different data shapes (flat innerHTML string vs. JSON-blob field), and
 * sharing a "field-split" abstraction would leak more than it would save.
 */
class SaveGuard {

	/**
	 * Fields the user is allowed to edit on a restricted save.
	 *
	 * Every other key in `ds_block_data` (template, css, js, form, type,
	 * and anything added later) is preserved verbatim from the DB copy.
	 *
	 * `bindings` is editable: it carries the dynamic-data wiring for repeater
	 * fields (and, in future, flat-field connections). Restricting it would
	 * mean restricted users could never connect or disconnect a data source.
	 * The shape itself doesn't carry executable HTML — only ids, query config,
	 * and per-field connection maps — so it isn't a kses concern.
	 */
	private const USER_EDITABLE_KEYS = [ 'label', 'settings', 'bindings' ];

	/**
	 * Tracks whether the pre-verify filter has already restored the stored
	 * `ds_block_data` on the current request. When true, `verify_settings_kses`
	 * should skip that key: the blob is safe-by-construction (the stored
	 * definition was previously written by a capable user), and iterating
	 * it through kses would spuriously detect diffs from `<script>` or
	 * `<template>` tags that are legitimate parts of the DS block.
	 */
	private static bool $ds_block_data_verified = false;

	/**
	 * Field-type-aware sanitizer applied to the restricted-user `settings`
	 * sub-tree after {@see merge_preserving_code()}. Closes the stored-XSS
	 * gap on values that templates render via Mustache triple-stache.
	 */
	private SettingsSanitizer $sanitizer;

	/**
	 * @param SettingsSanitizer|null $sanitizer Inject for testability; defaults
	 *                                          to a fresh instance when null.
	 */
	public function __construct( ?SettingsSanitizer $sanitizer = null ) {
		$this->sanitizer = $sanitizer ?? new SettingsSanitizer();
	}

	/**
	 * Register the two filters.
	 */
	public function boot(): void {
		add_filter( 'fl_builder_pre_verify_node_settings', [ $this, 'preserve_code_fields' ], 10, 4 );
		add_filter( 'fl_builder_before_update_layout_data', [ $this, 'preserve_code_fields_in_layout' ], 10, 3 );
		add_filter( 'fl_builder_verify_settings_kses_skip_keys', [ $this, 'kses_skip_keys' ], 10, 1 );
	}

	/**
	 * Tell `verify_settings_kses` to bypass the `ds_block_data` key when
	 * this class has already merged a safe version into it.
	 *
	 * @param  string[] $keys
	 * @return string[]
	 */
	public function kses_skip_keys( $keys ): array {
		$keys = is_array( $keys ) ? $keys : [];
		if ( self::$ds_block_data_verified ) {
			$keys[] = 'ds_block_data';
		}
		return $keys;
	}

	/**
	 * Primary path — runs inside `FLBuilderModel::save_settings()` before
	 * `verify_settings_kses` and inside the verify pre-flight.
	 *
	 * @param  object       $settings         Incoming settings object.
	 * @param  object       $node             The node being saved (already loaded with stored settings; for global nodes BB pre-hydrates from the template post).
	 * @param  int          $post_id          Current post ID (ignored here; `$node` is the source of truth).
	 * @param  int|bool     $template_post_id Template post ID when the node is global.
	 * @return object       Filtered settings.
	 */
	public function preserve_code_fields( $settings, $node, $post_id, $template_post_id ) {
		if ( WordPressAuth::user_can_create_content() ) {
			return $settings;
		}

		if ( ! $this->is_ds_block_node( $node ) ) {
			return $settings;
		}

		$incoming_raw = is_object( $settings ) ? ( $settings->ds_block_data ?? null ) : null;
		$existing_raw = $node->settings->ds_block_data ?? null;

		$merged = $this->merge_preserving_code( $incoming_raw, $existing_raw );
		if ( null === $merged ) {
			return $settings;
		}

		$merged = $this->sanitize_settings_tree( $merged, $post_id, $node->node ?? '' );

		$settings->ds_block_data      = wp_json_encode( $merged );
		self::$ds_block_data_verified = true;
		return $settings;
	}

	/**
	 * Defense-in-depth — runs inside `FLBuilderModel::update_layout_data()`
	 * on every write (save_settings, history restore, template apply, etc.).
	 * For each DS block node whose incoming `ds_block_data` differs from the
	 * DB copy, restore the code-bearing fields.
	 *
	 * @param  array  $data    Layout data about to be written.
	 * @param  string $status  `'draft'` or `'published'` (and friends).
	 * @param  int    $post_id Target post ID.
	 * @return array  Filtered layout data.
	 */
	public function preserve_code_fields_in_layout( $data, $status, $post_id ) {
		if ( WordPressAuth::user_can_create_content() ) {
			return $data;
		}

		if ( ! is_array( $data ) || empty( $data ) ) {
			return $data;
		}

		$existing_layout = $this->load_existing_layout( $status, (int) $post_id );

		foreach ( $data as $node_id => $node ) {
			if ( ! $this->is_ds_block_node( $node ) ) {
				continue;
			}

			$incoming_raw  = $node->settings->ds_block_data ?? null;
			$existing_node = $existing_layout[ $node_id ] ?? null;
			$existing_raw  = is_object( $existing_node ) ? ( $existing_node->settings->ds_block_data ?? null ) : null;

			$merged = $this->merge_preserving_code( $incoming_raw, $existing_raw );
			if ( null === $merged ) {
				continue;
			}

			$merged = $this->sanitize_settings_tree( $merged, (int) $post_id, (string) $node_id );

			// BB's update_layout_data runs `slash_settings` on the entire layout
			// before firing this filter, and `update_metadata` then wp_unslashes
			// the whole blob a single time on its way into storage. Surrounding
			// strings are double-escaped at this point; we have to match that
			// state so our re-encoded JSON survives the final unslash and isn't
			// stripped of its own JSON escape characters.
			$node->settings->ds_block_data = wp_slash( wp_json_encode( $merged ) );
			$data[ $node_id ]              = $node;
		}

		return $data;
	}

	/**
	 * Run the merged `settings` sub-tree through {@see SettingsSanitizer}
	 * using the DB-preserved `form` schema. Restricted users' raw HTML in
	 * `text`/`editor`/`svg` fields is stripped to neutralize the stored-XSS
	 * gap in Mustache triple-stache renders.
	 *
	 * Also walks the `bindings.<key>.defaults` sub-tree (H-3): defaults are
	 * the literal fallback values used when a data-source row is missing a
	 * connected property, and they land in the same render sink as raw
	 * settings values. `config` and `source` are validated by the data
	 * source's own resolver and are not walked here.
	 *
	 * When sanitization altered any leaf, emit a single structured log line
	 * so operators can spot accidental regressions. The log carries counts
	 * only — never user content.
	 *
	 * @param  array  $merged   The post-merge ds_block_data array.
	 * @param  int    $post_id  Target post ID (for logging context only).
	 * @param  string $node_id  Target node ID (for logging context only).
	 * @return array  The sanitized ds_block_data array.
	 */
	private function sanitize_settings_tree( array $merged, int $post_id, string $node_id ): array {
		$settings = $merged['settings'] ?? null;
		$form     = $merged['form'] ?? null;

		if ( ! is_array( $settings ) || ! is_array( $form ) ) {
			return $merged;
		}

		$result = $this->sanitizer->sanitize( $settings, $form );

		$merged['settings'] = $result->settings;

		$total_altered = $result->altered_count;

		// H-3: walk bindings[<key>].defaults against the same per-key
		// repeater child schema the settings walk uses.
		if ( isset( $merged['bindings'] ) && is_array( $merged['bindings'] ) ) {
			$bindings_altered   = 0;
			$merged['bindings'] = $this->sanitize_bindings_defaults( $merged['bindings'], $form, $bindings_altered );
			$total_altered     += $bindings_altered;
		}

		if ( $total_altered > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[fl-ds-save-guard] Sanitized %d leaf(s) for restricted-user save (post_id=%d, node_id=%s).',
				$total_altered,
				$post_id,
				$node_id
			) );
		}

		return $merged;
	}

	/**
	 * H-3: walk `bindings[<key>].defaults` against the repeater child schema
	 * that the same `<key>` references in `form`. Sub-fields outside
	 * `defaults` are left alone:
	 *
	 *   - `source` and `config` feed the resolver query and never reach a
	 *     render sink directly (audited 2026 against
	 *     {@see \FL\DesignSystem\DataSources\DataSourceResolver}).
	 *   - `connections` is a property-name mapping; the property names are
	 *     identifiers, not rendered HTML.
	 *
	 * Sub-fields outside the schema's repeater children pass through.
	 *
	 * Why: H-3 audit finding — bindings.defaults.<child> values are emitted
	 * via Mustache triple-stache when the bound data-source row is missing
	 * the connected property, so the sanitizer needs to apply the same
	 * type-aware treatment it applies to direct settings values.
	 *
	 * @param  array $bindings
	 * @param  array $form
	 * @param  int   $altered_count Accumulator (by-ref).
	 * @return array
	 */
	private function sanitize_bindings_defaults( array $bindings, array $form, int &$altered_count ): array {
		$type_map     = [];
		$repeater_map = [];
		$this->sanitizer->build_schema_maps( $form, $type_map, $repeater_map );

		foreach ( $bindings as $key => $binding ) {
			if ( ! is_array( $binding ) || ! isset( $binding['defaults'] ) || ! is_array( $binding['defaults'] ) ) {
				continue;
			}
			if ( ! isset( $repeater_map[ $key ] ) ) {
				// `defaults` only makes sense for data-source-driven repeaters.
				// Non-repeater keys pass through; the resolver ignores them.
				continue;
			}
			[ $child_type_map, $child_repeater_map ] = $repeater_map[ $key ];

			foreach ( $binding['defaults'] as $child_key => $child_value ) {
				if ( ! isset( $child_type_map[ $child_key ] ) ) {
					continue;
				}
				$binding['defaults'][ $child_key ] = $this->sanitizer->sanitize_value(
					$child_value,
					$child_type_map[ $child_key ],
					$altered_count
				);
			}
			$bindings[ $key ] = $binding;
		}

		return $bindings;
	}

	/**
	 * Whether the node is a DS-registered block module.
	 *
	 * `ds_block_data` is registered only by {@see ModuleTypeRegistrar} and
	 * derivatives, so its presence is a reliable marker.
	 *
	 * @param  mixed $node
	 * @return bool
	 */
	private function is_ds_block_node( $node ): bool {
		if ( ! is_object( $node ) ) {
			return false;
		}
		if ( ( $node->type ?? '' ) !== 'module' ) {
			return false;
		}
		if ( ! isset( $node->settings ) || ! is_object( $node->settings ) ) {
			return false;
		}
		return isset( $node->settings->ds_block_data );
	}

	/**
	 * Build the merged block data: start from the DB copy, overlay the
	 * user-editable keys from the incoming payload. Returns null when
	 * there is no existing DB copy (nothing to restore from), when either
	 * side fails to decode, or when no overlay actually applies.
	 *
	 * @param  mixed $incoming_raw
	 * @param  mixed $existing_raw
	 * @return array|null
	 */
	private function merge_preserving_code( $incoming_raw, $existing_raw ): ?array {
		$existing = $this->decode_block_data( $existing_raw );
		if ( null === $existing ) {
			return null;
		}

		$incoming = $this->decode_block_data( $incoming_raw );
		if ( null === $incoming ) {
			// No incoming payload at all — just re-serializing the DB copy
			// would be a no-op; return null to let the caller skip.
			return null;
		}

		$merged  = $existing;
		$changed = false;
		foreach ( self::USER_EDITABLE_KEYS as $key ) {
			if ( array_key_exists( $key, $incoming ) ) {
				$merged[ $key ] = $incoming[ $key ];
				$changed        = true;
			}
		}

		if ( ! $changed && $this->block_data_equals( $incoming, $existing ) ) {
			return null;
		}

		return $merged;
	}

	/**
	 * Decode a `ds_block_data` value into an associative array.
	 *
	 * @param  mixed $raw
	 * @return array|null
	 */
	private function decode_block_data( $raw ): ?array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_object( $raw ) ) {
			// BB's AJAX deserializer can hand back nested JSON as stdClass
			// rather than a flat string. Round-trip through json_encode to
			// normalize to an associative array (preserving nested objects).
			$round_tripped = json_decode( wp_json_encode( $raw ), true );
			return is_array( $round_tripped ) ? $round_tripped : null;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			// BB's layout data cache can contain slashed strings when
			// update_layout_data and get_layout_data run in the same
			// PHP process (e.g. history restore).
			$decoded = json_decode( wp_unslash( $raw ), true );
		}
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Shallow equality check for two block-data arrays.
	 */
	private function block_data_equals( array $a, array $b ): bool {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * Load the target post's current layout data so we can compare incoming
	 * against stored. Tries `$status` first, falls back to `published`.
	 *
	 * @param  string $status
	 * @param  int    $post_id
	 * @return array
	 */
	private function load_existing_layout( string $status, int $post_id ): array {
		if ( $post_id <= 0 ) {
			return [];
		}
		$layout = \FLBuilderModel::get_layout_data( $status, $post_id );
		if ( is_array( $layout ) && ! empty( $layout ) ) {
			return $layout;
		}
		if ( 'published' !== $status ) {
			$layout = \FLBuilderModel::get_layout_data( 'published', $post_id );
			if ( is_array( $layout ) ) {
				return $layout;
			}
		}
		return [];
	}
}
