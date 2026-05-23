<?php

namespace FL\DesignSystem\BlockEditor;

use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * Wraps a newly-saved WordPress pattern's content in an fl-ds/scope block so
 * the pattern carries its context (design system assignment and source post
 * ID) wherever it is rendered and wherever it is inserted.
 *
 * Gutenberg creates a pattern by POSTing to /wp/v2/blocks. A client-side
 * apiFetch middleware attaches the source page's ID as the X-FL-DS-Source-Post
 * header on these creation requests. This handler reads that header,
 * validates the caller's permission on the source, and wraps the new
 * pattern's `post_content` in
 * `<!-- wp:fl-ds/scope {"dsRef":"...","patternId":N} -->…<!-- /wp:fl-ds/scope -->`.
 *
 * The wrapper is added when the source page has a DS assigned, or when the
 * pattern contains any fl-ds/custom block. The `dsRef` attribute carries the
 * DS across insert contexts. The `patternId` attribute is the source pattern
 * post ID; on an unsynced insert, strip-on-insert copies it onto each
 * fl-ds/custom descendant so KsesFallback can restore the definitions on a
 * restricted user's first save of the target page.
 *
 * The `_fl_ds_ref` post meta is still updated as a derived cache so the
 * existing frontend enqueue path and REST read paths stay unchanged.
 *
 * Every validation miss is a silent no-op — the header is attacker-
 * controllable, so failures must not surface as errors.
 */
class PatternSaveHandler {

	public const SOURCE_POST_HEADER = 'x-fl-ds-source-post';
	public const SCOPE_BLOCK_NAME   = 'fl-ds/scope';
	public const CUSTOM_BLOCK_NAME  = 'fl-ds/custom';

	public function boot(): void {
		add_action( 'rest_after_insert_wp_block', [ $this, 'wrap_pattern' ], 10, 3 );
	}

	/**
	 * Wrap a newly created pattern's content in the DS-scope block and update
	 * the meta cache.
	 *
	 * @param \WP_Post         $post     The pattern post that was just saved.
	 * @param \WP_REST_Request $request  The REST request that triggered the save.
	 * @param bool             $creating True on create, false on update.
	 */
	public function wrap_pattern( \WP_Post $post, \WP_REST_Request $request, bool $creating ): void {
		if ( ! $creating ) {
			return;
		}

		$source_id = (int) $request->get_header( self::SOURCE_POST_HEADER );

		if ( ! $source_id || $source_id === (int) $post->ID ) {
			return;
		}

		if ( ! get_post( $source_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			return;
		}

		$ds_ref_raw = get_post_meta( $source_id, PageOverrideProvider::DS_REF_META_KEY, true );
		$ds_ref     = is_string( $ds_ref_raw ) ? $ds_ref_raw : '';

		$content         = (string) $post->post_content;
		$has_custom      = self::content_has_custom_block( $content );
		$should_wrap_for = '' !== $ds_ref || $has_custom;

		if ( ! $should_wrap_for ) {
			return;
		}

		$wrapped = self::wrap_content_in_scope( $content, $ds_ref, (int) $post->ID );

		if ( $wrapped !== $content ) {
			// wp_update_post expects slashed data and runs wp_unslash internally
			// before writing. $post->post_content is already unslashed, so pass
			// it back through wp_slash to preserve the backslashes Gutenberg
			// emits in serialized block attributes (e.g. < escapes in the
			// settings JSON).
			wp_update_post( [
				'ID'           => (int) $post->ID,
				'post_content' => wp_slash( $wrapped ),
			] );
		}

		// Derived cache — keeps frontend enqueue + REST read paths simple.
		if ( '' !== $ds_ref ) {
			update_post_meta( (int) $post->ID, PageOverrideProvider::DS_REF_META_KEY, $ds_ref );
		}
	}

	/**
	 * Wrap block markup in an fl-ds/scope block delimiter carrying the given
	 * dsRef and/or patternId attributes.
	 *
	 * If the content already begins with a top-level fl-ds/scope wrapper, the
	 * existing wrapper's attributes are merged with any missing values rather
	 * than nested inside another scope block. Existing attribute values are
	 * never overwritten.
	 *
	 * Exposed static for unit testing.
	 *
	 * @param  string $content    Serialized block content.
	 * @param  string $ds_ref     Design system UUID. Empty string to omit.
	 * @param  int    $pattern_id Source pattern post ID. Zero to omit.
	 * @return string Wrapped content.
	 */
	public static function wrap_content_in_scope( string $content, string $ds_ref, int $pattern_id = 0 ): string {
		$has_ref = '' !== $ds_ref;
		$has_pid = $pattern_id > 0;

		if ( ! $has_ref && ! $has_pid ) {
			return $content;
		}

		$trimmed = trim( $content );

		if ( self::starts_with_scope_block( $trimmed ) ) {
			return self::merge_scope_attrs( $content, $ds_ref, $pattern_id );
		}

		$attrs = [];
		if ( $has_ref ) {
			$attrs['dsRef'] = $ds_ref;
		}
		if ( $has_pid ) {
			$attrs['patternId'] = $pattern_id;
		}

		$attrs_json = wp_json_encode( $attrs );
		$open_tag   = sprintf( '<!-- wp:%s %s -->', self::SCOPE_BLOCK_NAME, $attrs_json );
		$close_tag  = sprintf( '<!-- /wp:%s -->', self::SCOPE_BLOCK_NAME );

		return $open_tag . "\n" . $content . "\n" . $close_tag;
	}

	/**
	 * Heuristic check: does the content begin with a top-level fl-ds/scope
	 * block? Avoids the full parser when the answer is usually "no".
	 */
	private static function starts_with_scope_block( string $trimmed ): bool {
		$prefix = '<!-- wp:' . self::SCOPE_BLOCK_NAME;
		return 0 === strpos( $trimmed, $prefix . ' ' ) || 0 === strpos( $trimmed, $prefix . "\n" );
	}

	/**
	 * Does the content contain any fl-ds/custom block comment? Simple string
	 * check — parse_blocks would be more accurate but overkill for this.
	 */
	private static function content_has_custom_block( string $content ): bool {
		return false !== strpos( $content, '<!-- wp:' . self::CUSTOM_BLOCK_NAME );
	}

	/**
	 * Merge missing attributes into an existing top-level fl-ds/scope opening
	 * tag. Existing attributes are preserved; only absent keys are filled in.
	 *
	 * Returns the content unchanged if nothing would be added.
	 */
	private static function merge_scope_attrs( string $content, string $ds_ref, int $pattern_id ): string {
		$pattern = '/(<!--\s+wp:' . preg_quote( self::SCOPE_BLOCK_NAME, '/' ) . ')(\s+(\{.*?\}))?(\s+-->)/s';

		return preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( $ds_ref, $pattern_id ) {
				$existing = [];
				if ( ! empty( $m[3] ) ) {
					$decoded = json_decode( $m[3], true );
					if ( is_array( $decoded ) ) {
						$existing = $decoded;
					}
				}

				$merged = $existing;
				if ( '' !== $ds_ref && empty( $merged['dsRef'] ) ) {
					$merged['dsRef'] = $ds_ref;
				}
				if ( $pattern_id > 0 && empty( $merged['patternId'] ) ) {
					$merged['patternId'] = $pattern_id;
				}

				if ( $merged === $existing ) {
					return $m[0];
				}

				return $m[1] . ' ' . wp_json_encode( $merged ) . $m[4];
			},
			$content,
			1
		);
	}
}
