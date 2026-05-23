<?php

namespace FL\DesignSystem\BlockEditor;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\Settings\SettingsSanitizer;

/**
 * Reconciles fl-ds/custom block definitions at save time.
 *
 * Does two things, both gated on the filter firing after kses:
 *
 * 1. **Restoration (restricted users only).** WordPress's kses filtering strips
 *    <template>, <style>, and <script> tags from post_content when the current
 *    user lacks the `unfiltered_html` capability. fl-ds/custom blocks store their
 *    entire definition (Mustache template, CSS, JS) as raw HTML in innerHTML, so
 *    kses would permanently destroy AI-generated layouts. Two paths are tried
 *    per block:
 *
 *    - **Same-post lookup** — read the previously-saved block definitions from the
 *      database and match by blockId. Covers the common case of a restricted user
 *      editing settings / reordering / deleting blocks on a post that was originally
 *      saved by a privileged user.
 *    - **Source-pattern lookup** — when the block has a positive-integer `patternId`
 *      attribute and the same-post lookup missed (e.g. a freshly-inserted unsynced
 *      pattern), load the referenced wp_block post and match by blockId or
 *      sourceBlockId there.
 *
 * 2. **Hint cleanup (all users).** Once a block has a non-empty innerHTML in the
 *    content being saved, the `patternId` and `sourceBlockId` attributes are no
 *    longer load-bearing: same-post lookup will resolve by blockId for every
 *    future save, and the pattern-lookup path is never consulted. The attributes
 *    are stripped so post_content stays clean after a privileged user's save
 *    too. When restoration runs and succeeds, this naturally covers the
 *    restricted-user first-save path as well.
 *
 * User changes to block settings (stored in the comment delimiter) are preserved;
 * only innerHTML/innerContent is touched by restoration.
 *
 * Uses the `wp_insert_post_data` filter which fires after kses filtering and
 * provides both the filtered content and the post ID.
 */
class KsesFallback {

	private const CUSTOM_BLOCK = 'fl-ds/custom';

	/**
	 * Field-type-aware sanitizer applied to each `fl-ds/custom` block's
	 * `settings` attribute for restricted users. Closes the block-editor
	 * side of the same stored-XSS gap that {@see \FL\DesignSystem\BeaverBuilder\SaveGuard}
	 * closes on the Beaver Builder save path.
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
	 * Register the content preservation filter and the REST save-refusal hook.
	 *
	 * `wp_insert_post_data` covers every save path (REST, classic, programmatic
	 * `wp_insert_post`/`wp_update_post` callers). It's where restoration and
	 * settings sanitization run.
	 *
	 * `rest_pre_insert_{$post_type}` is the REST-only refusal hook for M-5:
	 * unlike `wp_insert_post_data`, returning a `WP_Error` here aborts the
	 * save atomically before any DB write occurs, leaving `bindings` and
	 * `settings` exactly as they were on the prior save. The hook is dynamic
	 * per post type, so it's wired up on `init` (priority 100) once all
	 * post types have been registered.
	 */
	public function boot(): void {
		add_filter( 'wp_insert_post_data', [ $this, 'restore_block_definitions' ], 10, 2 );
		add_action( 'init', [ $this, 'register_rest_save_refusal' ], 100 );
	}

	/**
	 * Register the REST save-refusal hook for every post type that exposes
	 * editing through the REST API. Called once on `init` priority 100.
	 *
	 * @internal
	 */
	public function register_rest_save_refusal(): void {
		$post_types = get_post_types( [ 'show_in_rest' => true ], 'names' );
		if ( ! is_array( $post_types ) ) {
			return;
		}
		foreach ( $post_types as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", [ $this, 'refuse_rest_save_on_empty_definition' ], 10, 2 );
		}
	}

	/**
	 * Reconcile fl-ds/custom block definitions after kses filtering.
	 *
	 * Restores innerHTML for restricted users, strips reconstruction hints
	 * once a block is self-contained. See class docblock for the full flow.
	 *
	 * @param array $data    Slashed, sanitized post data (content has been kses-filtered).
	 * @param array $postarr Raw post data array including the post ID.
	 * @return array Post data with block definitions restored.
	 */
	public function restore_block_definitions( array $data, array $postarr ): array {
		$content = wp_unslash( $data['post_content'] ?? '' );
		if ( '' === $content ) {
			return $data;
		}

		$is_privileged = WordPressAuth::user_can_create_content();

		// Fast path: privileged user saving content that carries no
		// reconstruction hints AND no fl-ds/custom block at all (so we have
		// no hashes to record). Hints OR the presence of fl-ds/custom force
		// us through the parse so the M-4 hash recording can update the
		// trusted baseline.
		if ( $is_privileged
			&& false === strpos( $content, '"patternId"' )
			&& false === strpos( $content, '"sourceBlockId"' )
			&& false === strpos( $content, 'wp:fl-ds/custom' )
		) {
			return $data;
		}

		$blocks = parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return $data;
		}

		// Same-post cache is only needed for the restoration path.
		$same_post_cache = [];
		if ( ! $is_privileged ) {
			$post_id = (int) ( $postarr['ID'] ?? 0 );
			if ( $post_id > 0 ) {
				$existing_post = get_post( $post_id );
				if ( $existing_post && ! empty( $existing_post->post_content ) ) {
					$same_post_cache = self::build_definition_map( $existing_post->post_content );
				}
			}
		}

		// Per-invocation cache of pattern definition maps to avoid re-parsing
		// when multiple blocks on the page reference the same patternId.
		$pattern_cache = [];
		$changed       = false;

		// Total leaves sanitized across every block on the page; logged once
		// at the end so a single save produces one structured log line.
		$total_altered_count = 0;

		$post_id = (int) ( $postarr['ID'] ?? 0 );

		foreach ( $blocks as &$block ) {
			if ( self::CUSTOM_BLOCK !== ( $block['blockName'] ?? '' ) ) {
				continue;
			}

			$block_id = $block['attrs']['blockId'] ?? '';
			if ( empty( $block_id ) ) {
				continue;
			}

			// Restoration (restricted users only).
			if ( ! $is_privileged ) {
				// Path 1: same-post lookup (primary, preserves divergence).
				if ( isset( $same_post_cache[ $block_id ] ) ) {
					$block['innerHTML']    = $same_post_cache[ $block_id ]['innerHTML'];
					$block['innerContent'] = $same_post_cache[ $block_id ]['innerContent'];
					$changed               = true;

					// M-4: hash-divergence detector. Observation only.
					self::detect_hash_divergence( $post_id, (string) $block_id, $block['innerHTML'], 'same-post' );
				} else {
					// Path 2: source-pattern lookup (fresh inserts).
					$pattern_id = (int) ( $block['attrs']['patternId'] ?? 0 );
					if ( $pattern_id > 0 ) {
						if ( ! array_key_exists( $pattern_id, $pattern_cache ) ) {
							$pattern_cache[ $pattern_id ] = self::load_pattern_definition_map( $pattern_id );
						}

						$pattern_map = $pattern_cache[ $pattern_id ];

						// Prefer blockId; fall back to sourceBlockId when the
						// dedup subscription regenerated the id at insert time.
						$lookup_key = null;
						if ( isset( $pattern_map[ $block_id ] ) ) {
							$lookup_key = $block_id;
						} else {
							$source_block_id = $block['attrs']['sourceBlockId'] ?? '';
							if ( '' !== $source_block_id && isset( $pattern_map[ $source_block_id ] ) ) {
								$lookup_key = $source_block_id;
							}
						}

						if ( null !== $lookup_key ) {
							$block['innerHTML']    = $pattern_map[ $lookup_key ]['innerHTML'];
							$block['innerContent'] = $pattern_map[ $lookup_key ]['innerContent'];
							$changed               = true;

							// M-4: hash-divergence detector for the pattern path.
							self::detect_hash_divergence( $post_id, (string) $block_id, $block['innerHTML'], 'pattern' );
						}
					}
				}
			} else {
				// Privileged save: record the canonical (template, css, js)
				// hash so future restricted-user restorations can be checked
				// against the trusted snapshot. Idempotent — same hash leaves
				// the meta untouched. (M-4)
				if ( ! empty( $block['innerHTML'] ) && $post_id > 0 ) {
					self::record_block_hash( $post_id, (string) $block_id, (string) $block['innerHTML'] );
				}
			}

			// Strip reconstruction hints once the block is self-contained.
			// Runs for every user: a privileged save is the first chance to
			// clean hints that were stamped by dedup or pattern-insert flows.
			// A restricted save with a restoration miss keeps the hints so
			// a future attempt can succeed (tested by the deleted-pattern
			// case).
			if ( ! empty( $block['innerHTML'] ) ) {
				if ( isset( $block['attrs']['patternId'] ) ) {
					unset( $block['attrs']['patternId'] );
					$changed = true;
				}
				if ( isset( $block['attrs']['sourceBlockId'] ) ) {
					unset( $block['attrs']['sourceBlockId'] );
					$changed = true;
				}
			}

			// M-5: a restricted-user save where the block has settings or
			// bindings but innerHTML is empty (restoration missed entirely)
			// would otherwise persist those values without any sanitizer
			// run, because the form schema lives inside the (missing)
			// innerHTML.
			//
			// REST saves are refused atomically by
			// {@see refuse_rest_save_on_empty_definition} before any DB
			// write occurs, so for those, this branch never gets reached
			// (the request short-circuits at `rest_pre_insert_{$post_type}`).
			//
			// Non-REST saves (legacy `wp_insert_post`/`wp_update_post`
			// programmatic callers) reach here. We deliberately do NOT
			// mutate `attrs.settings` or `attrs.bindings`: the previous
			// implementation cleared them, but bindings carry data-source
			// connections beyond their `defaults` field and silently
			// destroying them caused data loss in legitimate edge cases
			// (deleted patterns, freshly-inserted unsynced patterns). We
			// fire the observability action so operators can subscribe and
			// log at WP_DEBUG so support can debug. Skip the rest of the
			// per-block processing: there's no innerHTML to drive form
			// schema lookup, so settings sanitization can't run anyway.
			if ( ! $is_privileged && empty( $block['innerHTML'] ) ) {
				$has_settings = ! empty( $block['attrs']['settings'] );
				$has_bindings = ! empty( $block['attrs']['bindings'] );
				if ( $has_settings || $has_bindings ) {
					self::observe_empty_inner_html_block( $post_id, (string) $block_id );
					continue;
				}
			}

			// Sanitize the `settings` attribute for restricted users. The
			// block comment delimiter's JSON payload is never kses-filtered
			// by core, so a restricted user can otherwise inject arbitrary
			// HTML into any string field that templates render via Mustache
			// triple-stache. Parity with SaveGuard's settings sanitization
			// on the BB save path.
			if ( ! $is_privileged ) {
				$settings_changed = $this->sanitize_block_settings( $block, $total_altered_count );
				if ( $settings_changed ) {
					$changed = true;
				}
			}
		}
		unset( $block );

		if ( $changed ) {
			$data['post_content'] = wp_slash( serialize_blocks( $blocks ) );
		}

		if ( $total_altered_count > 0 ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[fl-ds-kses-fallback] Sanitized %d block-settings leaf(s) for restricted-user save (post_id=%d).',
				$total_altered_count,
				(int) ( $postarr['ID'] ?? 0 )
			) );
		}

		return $data;
	}

	/**
	 * Run `settings` through {@see SettingsSanitizer} for a single block.
	 *
	 * Resolves the form schema from the block's (possibly just-restored)
	 * innerHTML via {@see ContentParser}. When the inner payload has no
	 * form JSON (e.g. restoration missed and nothing else defined one),
	 * the sanitizer has no type map and passes settings through — in that
	 * case restoration has already failed and there is no Mustache render
	 * sink on this save, so there is nothing to sanitize against.
	 *
	 * Also walks `bindings[<key>].defaults` against the same form schema
	 * (H-3). See {@see SaveGuard::sanitize_bindings_defaults} for rationale.
	 *
	 * @param  array $block              Block array (mutated in place).
	 * @param  int   $total_altered_count Accumulator for the per-save log line.
	 * @return bool  Whether the block's settings were changed.
	 */
	private function sanitize_block_settings( array &$block, int &$total_altered_count ): bool {
		$settings = $block['attrs']['settings'] ?? null;
		$bindings = $block['attrs']['bindings'] ?? null;

		$has_settings = is_array( $settings ) && ! empty( $settings );
		$has_bindings = is_array( $bindings ) && ! empty( $bindings );

		if ( ! $has_settings && ! $has_bindings ) {
			return false;
		}

		$inner_html = (string) ( $block['innerHTML'] ?? '' );
		if ( '' === $inner_html ) {
			return false;
		}

		$parsed = ContentParser::parse( $inner_html );
		$form   = $parsed['form'] ?? [];
		if ( empty( $form ) ) {
			return false;
		}

		$changed = false;

		if ( $has_settings ) {
			$result = $this->sanitizer->sanitize( $settings, $form );
			if ( $result->altered_count > 0 ) {
				$block['attrs']['settings'] = $result->settings;
				$total_altered_count       += $result->altered_count;
				$changed                    = true;
			}
		}

		if ( $has_bindings ) {
			$bindings_altered = 0;
			$cleaned          = $this->sanitize_bindings_defaults( $bindings, $form, $bindings_altered );
			if ( $bindings_altered > 0 ) {
				$block['attrs']['bindings'] = $cleaned;
				$total_altered_count       += $bindings_altered;
				$changed                    = true;
			}
		}

		return $changed;
	}

	/**
	 * Walk `bindings[<key>].defaults` against the form schema's repeater
	 * child types. Mirror of
	 * {@see \FL\DesignSystem\BeaverBuilder\SaveGuard::sanitize_bindings_defaults}
	 * for the block-editor save path.
	 *
	 * @param  array $bindings
	 * @param  array $form
	 * @param  int   $altered_count
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
				continue;
			}
			[ $child_type_map ] = $repeater_map[ $key ];

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
	 * Parse serialized block content and return a map of
	 * blockId → ['innerHTML', 'innerContent'] for every fl-ds/custom block
	 * found anywhere in the tree.
	 *
	 * @param string $content Serialized block content.
	 * @return array<string, array{innerHTML: string, innerContent: array}>
	 */
	private static function build_definition_map( string $content ): array {
		$map    = [];
		$blocks = parse_blocks( $content );
		self::walk_definition_map( $blocks, $map );
		return $map;
	}

	/**
	 * Recursive walker for build_definition_map. Descends into innerBlocks
	 * so fl-ds/custom blocks inside wrappers (e.g. fl-ds/scope) are found.
	 */
	private static function walk_definition_map( array $blocks, array &$map ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			if ( self::CUSTOM_BLOCK === $name ) {
				$block_id = $block['attrs']['blockId'] ?? '';
				if ( ! empty( $block_id ) && ! isset( $map[ $block_id ] ) ) {
					$map[ $block_id ] = [
						'innerHTML'    => $block['innerHTML'] ?? '',
						'innerContent' => $block['innerContent'] ?? [],
					];
				}
			}

			$inner = $block['innerBlocks'] ?? [];
			if ( is_array( $inner ) && ! empty( $inner ) ) {
				self::walk_definition_map( $inner, $map );
			}
		}
	}

	/**
	 * Load a pattern's definition map by post ID. Returns an empty map if the
	 * post doesn't exist, isn't a wp_block, or has no content.
	 */
	private static function load_pattern_definition_map( int $pattern_id ): array {
		$post = get_post( $pattern_id );
		if ( ! $post || 'wp_block' !== $post->post_type || empty( $post->post_content ) ) {
			return [];
		}
		return self::build_definition_map( (string) $post->post_content );
	}

	/**
	 * Whether the current user can create DS content.
	 *
	 * @deprecated Use {@see WordPressAuth::user_can_create_content()}. Retained
	 * so any in-flight callers keep working; remove once the codebase is fully
	 * migrated.
	 *
	 * @return bool
	 */
	public static function user_can_create_content(): bool {
		return WordPressAuth::user_can_create_content();
	}

	/**
	 * Compute a SHA-256 hash of the canonical `(template, css, js)` triple
	 * for a fl-ds/custom block's innerHTML payload. Used by the M-4 hash
	 * divergence detector.
	 *
	 * @param string $inner_html The block's serialized innerHTML.
	 * @return string SHA-256 hex digest.
	 */
	private static function compute_definition_hash( string $inner_html ): string {
		$parsed     = ContentParser::parse( $inner_html );
		$canonical  = ( $parsed['template'] ?? '' );
		$canonical .= "\x1f"; // unit separator, will not appear in canonical content
		$canonical .= ( $parsed['css'] ?? '' );
		$canonical .= "\x1f";
		$canonical .= ( $parsed['js'] ?? '' );
		return hash( 'sha256', $canonical );
	}

	/**
	 * M-4: Record the canonical hash for a block on a privileged save.
	 *
	 * Stored in post meta as `_fl_ds_def_hash_<block_id>`. Idempotent on
	 * repeat saves with the same content. Single source of truth used by
	 * {@see detect_hash_divergence} on restricted-user restorations.
	 *
	 * @param int    $post_id
	 * @param string $block_id
	 * @param string $inner_html
	 */
	private static function record_block_hash( int $post_id, string $block_id, string $inner_html ): void {
		if ( $post_id <= 0 || '' === $block_id ) {
			return;
		}
		$hash = self::compute_definition_hash( $inner_html );
		$key  = '_fl_ds_def_hash_' . $block_id;
		$prev = get_post_meta( $post_id, $key, true );
		if ( $prev === $hash ) {
			return;
		}
		update_post_meta( $post_id, $key, $hash );
	}

	/**
	 * M-4: Detect when a restored block's `(template, css, js)` differs from
	 * the privileged-save hash recorded for that block_id. Observation only:
	 * fires `do_action( 'fl_ds_kses_fallback_restoration_diverged', ... )`
	 * and logs at WP_DEBUG. The save proceeds either way.
	 *
	 * Why observation rather than auto-resanitize: the restored content was
	 * authored by a privileged user and may contain `<script>`, `<style>`,
	 * `<template>`, or other tags that wp_kses_post would strip. Real defense
	 * for the M-4 amplifier concern lives at the AI-agent prompt-injection
	 * layer and at privileged-save review, not here. Operators who want to
	 * surface alerts can subscribe to the action.
	 *
	 * @param int    $post_id
	 * @param string $block_id
	 * @param string $inner_html The just-restored innerHTML.
	 * @param string $context     `'same-post'` or `'pattern'`.
	 */
	private static function detect_hash_divergence( int $post_id, string $block_id, string $inner_html, string $context ): void {
		if ( $post_id <= 0 || '' === $block_id ) {
			return;
		}
		$key      = '_fl_ds_def_hash_' . $block_id;
		$expected = get_post_meta( $post_id, $key, true );
		if ( ! is_string( $expected ) || '' === $expected ) {
			// No baseline yet — this is the first save for this block, or
			// the post predates the hash schema. Record now so future
			// restorations have a baseline.
			self::record_block_hash( $post_id, $block_id, $inner_html );
			return;
		}
		$actual = self::compute_definition_hash( $inner_html );
		if ( $expected === $actual ) {
			return;
		}

		do_action( 'fl_ds_kses_fallback_restoration_diverged', $block_id, [
			'post_id'  => $post_id,
			'context'  => $context,
			'expected' => $expected,
			'actual'   => $actual,
		] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[fl-ds-kses-fallback] Restoration hash diverged (post_id=%d, block_id=%s, context=%s): expected %s, got %s.',
				$post_id,
				$block_id,
				$context,
				$expected,
				$actual
			) );
		}
	}

	/**
	 * M-5: REST save refusal for fl-ds/custom blocks with empty innerHTML.
	 *
	 * Fires on `rest_pre_insert_{$post_type}` for every post type that
	 * exposes editing via the REST API. When the incoming `post_content`
	 * contains a fl-ds/custom block whose innerHTML is empty AND whose
	 * `settings` or `bindings` attribute is non-empty, returns a
	 * `WP_Error` (status 422) and the REST controller short-circuits the
	 * save before any database write occurs.
	 *
	 * Rationale: the audit's M-5 finding is "settings persist unsanitized
	 * when innerHTML is empty." The form schema lives inside innerHTML,
	 * so without it the sanitizer has no type information to drive the
	 * walk. Refusing the save (rather than mutating the block) keeps
	 * `bindings` exactly as they were on the prior save — bindings carry
	 * data-source connections beyond their `defaults` field, and silently
	 * destroying them caused data loss in legitimate edge cases (deleted
	 * patterns, freshly-inserted unsynced patterns). H-3 separately
	 * sanitizes binding `defaults` when innerHTML IS present.
	 *
	 * Whole-post atomic refusal matches the plan's preferred pattern.
	 * One bad block aborts the entire save; the editor surfaces the
	 * error to the user with a specific message naming the offending
	 * block ID so support can debug.
	 *
	 * Privileged users (those with `unfiltered_html` or
	 * `fl_ds_create_content`) bypass this check: same exemption as
	 * settings sanitization in the main `wp_insert_post_data` filter.
	 *
	 * @param mixed            $prepared Prepared post object (`stdClass`)
	 *                                   or already-set `WP_Error` from a
	 *                                   prior filter callback.
	 * @param \WP_REST_Request $request  The REST request.
	 * @return mixed `stdClass` to allow the save, `WP_Error` to refuse.
	 */
	public function refuse_rest_save_on_empty_definition( $prepared, $request ) {
		// Pass through earlier-WP_Error returns and anything that doesn't
		// carry a `post_content` we can inspect.
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		if ( ! is_object( $prepared ) ) {
			return $prepared;
		}
		if ( WordPressAuth::user_can_create_content() ) {
			return $prepared;
		}

		$content = isset( $prepared->post_content ) ? (string) $prepared->post_content : '';
		if ( '' === $content ) {
			return $prepared;
		}
		if ( false === strpos( $content, 'wp:' . self::CUSTOM_BLOCK ) ) {
			return $prepared;
		}

		$blocks = parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return $prepared;
		}

		$offender = self::find_first_empty_custom_block( $blocks );
		if ( null === $offender ) {
			return $prepared;
		}

		$post_id = 0;
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$post_id = (int) $request->get_param( 'id' );
		}

		do_action( 'fl_ds_kses_fallback_save_refused', $offender, [
			'post_id' => $post_id,
			'reason'  => 'empty-inner-html',
			'context' => 'rest',
		] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[fl-ds-kses-fallback] Refused REST save on empty innerHTML (post_id=%d, block_id=%s).',
				$post_id,
				$offender
			) );
		}

		return new \WP_Error(
			'fl_ds_block_empty_definition',
			sprintf(
				/* translators: %s: fl-ds/custom block ID. */
				__( 'Block %s could not be saved: definition is missing. Please reinsert the block from the design library.', 'fl-design-system' ),
				$offender
			),
			[ 'status' => 422 ]
		);
	}

	/**
	 * M-5: Non-REST observability fallback. Fired from the
	 * `wp_insert_post_data` filter when a fl-ds/custom block reaches the
	 * filter with empty innerHTML AND non-empty settings/bindings.
	 *
	 * Unlike the REST hook, `wp_insert_post_data` cannot return
	 * `WP_Error` to halt the write — and the alternate
	 * `wp_insert_post_empty_content` path produces a misleading "post
	 * is empty" error rather than naming the offending block. Rather
	 * than mutate `attrs.settings`/`attrs.bindings` (which destroys
	 * bindings data) we fire the observability hook and log at
	 * WP_DEBUG, then leave the block alone. The save proceeds with the
	 * unsanitized settings on this block.
	 *
	 * Documented limitation: the legacy non-REST path (programmatic
	 * `wp_insert_post`/`wp_update_post` callers) does NOT refuse the
	 * save. The dominant block-editor save path is REST, where the
	 * `rest_pre_insert_{$post_type}` hook does refuse atomically. This
	 * limitation is acceptable because (a) the non-REST path is rare
	 * in modern WP for block-content editing, (b) silently mutating
	 * the block to clear its settings/bindings caused data loss, and
	 * (c) operators who need stricter behavior can subscribe to the
	 * `fl_ds_kses_fallback_save_refused` action and reject the save
	 * higher in their own code.
	 *
	 * @param int    $post_id
	 * @param string $block_id
	 */
	private static function observe_empty_inner_html_block( int $post_id, string $block_id ): void {
		do_action( 'fl_ds_kses_fallback_save_refused', $block_id, [
			'post_id' => $post_id,
			'reason'  => 'empty-inner-html',
			'context' => 'non-rest',
		] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[fl-ds-kses-fallback] Observed empty innerHTML on non-REST save (post_id=%d, block_id=%s); save proceeds without refusal.',
				$post_id,
				$block_id
			) );
		}
	}

	/**
	 * Walk a parsed block tree and return the first fl-ds/custom block
	 * with empty `innerHTML` and a non-empty `settings` or `bindings`
	 * attribute. Returns the offending blockId, or null if none found.
	 *
	 * @param array $blocks
	 * @return string|null
	 */
	private static function find_first_empty_custom_block( array $blocks ): ?string {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			if ( self::CUSTOM_BLOCK === $name ) {
				$inner_html = (string) ( $block['innerHTML'] ?? '' );
				if ( '' === trim( $inner_html ) ) {
					$attrs        = $block['attrs'] ?? [];
					$has_settings = ! empty( $attrs['settings'] );
					$has_bindings = ! empty( $attrs['bindings'] );
					if ( $has_settings || $has_bindings ) {
						$block_id = (string) ( $attrs['blockId'] ?? '' );
						if ( '' !== $block_id ) {
							return $block_id;
						}
						return 'unknown';
					}
				}
			}

			$inner = $block['innerBlocks'] ?? [];
			if ( is_array( $inner ) && ! empty( $inner ) ) {
				$found = self::find_first_empty_custom_block( $inner );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}
