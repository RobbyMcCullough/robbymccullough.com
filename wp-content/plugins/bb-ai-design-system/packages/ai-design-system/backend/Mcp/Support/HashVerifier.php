<?php

namespace FL\DesignSystem\Mcp\Support;

use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Page\PageExporter;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * Optimistic-concurrency hashing for pages and design systems.
 *
 * Pages: the hash covers exported HTML so MCP edits can detect external
 * changes since the last fetch. Design systems: the hash covers tokens
 * (sorted), reset, base, JS, fonts, guidance, and brief — token order is
 * sorted so re-saving the same logical state always yields the same hash.
 *
 * Both hashes are content-addressable, not monotonic: a state cycle
 * A -> B -> A produces hashes H_a -> H_b -> H_a. Callers using the hash
 * as a "did the state change since the hash I hold" check are fine; using
 * it as "did anything change since arbitrary point T" across non-adjacent
 * operations is misuse.
 */
class HashVerifier {

	/**
	 * Read-path message surfaced when a page's stored MCP hash no longer
	 * matches the live export hash. Shared by get-page-html, get-page-outline,
	 * and get-page-blocks so the wording stays in lockstep.
	 */
	public const EXTERNAL_MODIFICATION_READ_MESSAGE = 'IMPORTANT: This content has been modified outside of MCP (e.g. in the WordPress editor) since the last AI edit. You cannot update this page until the user acknowledges these external changes. Tell the user what you see and ask how they would like to proceed. To update, you must pass acknowledge_external_changes: true to update-page-html.';

	/**
	 * Compute a hash of exported page content.
	 *
	 * @param array $export_result Result from {@see PageExporter::export()}.
	 */
	public function compute_content_hash( array $export_result ): string {
		return wp_hash( $export_result['html'] ?? '' );
	}

	/**
	 * Detect whether a page has been modified outside of MCP since the
	 * last AI edit, given an already-computed content hash.
	 *
	 * Read-path counterpart to {@see check_external_modifications()}.
	 * Callers on the read path have already exported the page to compute
	 * `$content_hash`; passing it in avoids a redundant re-export.
	 *
	 * Returns false when no `last_mcp_hash` post meta is stored (page has
	 * never been MCP-edited) or when the stored hash equals `$content_hash`.
	 */
	public function detect_external_modification( int $post_id, string $content_hash ): bool {
		$last_mcp_hash = get_post_meta( $post_id, PageOverrideProvider::LAST_MCP_HASH_KEY, true );
		return ! empty( $last_mcp_hash ) && $last_mcp_hash !== $content_hash;
	}

	/**
	 * Verify that the supplied hash still matches live page state.
	 *
	 * @param  int  $post_id Post ID.
	 * @param  bool $skip    Skip verification (used for newly created staging drafts).
	 * @return \WP_Error|null Null on match, WP_Error on mismatch.
	 */
	public function verify_content_hash( int $post_id, string $content_hash, PageEditorAdapterInterface $adapter, bool $skip = false ): ?\WP_Error {
		if ( $skip ) {
			return null;
		}

		$current      = PageExporter::export( $post_id, $adapter );
		$current_hash = $this->compute_content_hash( $current );
		if ( $content_hash !== $current_hash ) {
			return new \WP_Error(
				'content_modified',
				'This page has been modified since you last fetched it. Call get-page-outline (or get-page-blocks / get-page-html) again to get the latest content_hash.',
				[ 'status' => 409 ]
			);
		}

		return null;
	}

	/**
	 * Reject a write when the page has been modified outside of MCP since
	 * the last AI edit, unless the agent has explicitly acknowledged.
	 *
	 * Returns null when no last-MCP hash is stored (page has never been
	 * MCP-edited), when the live state still matches, or when the agent
	 * set `acknowledge_external_changes: true`.
	 *
	 * @param  string $retry_hint Tool-specific retry suffix appended to the
	 *                            error message (e.g. "Call update-page-html
	 *                            again with acknowledge_external_changes: true
	 *                            after getting user confirmation.").
	 * @return \WP_Error|null Null on pass-through, 409 on unacknowledged drift.
	 */
	public function check_external_modifications( int $post_id, bool $acknowledged, PageEditorAdapterInterface $adapter, string $retry_hint ): ?\WP_Error {
		$last_mcp_hash = get_post_meta( $post_id, PageOverrideProvider::LAST_MCP_HASH_KEY, true );
		if ( ! $last_mcp_hash ) {
			return null;
		}

		$current_export = PageExporter::export( $post_id, $adapter );
		$current_hash   = $this->compute_content_hash( $current_export );
		if ( $last_mcp_hash === $current_hash || $acknowledged ) {
			return null;
		}

		$message = 'This page has been modified outside of MCP since the last AI edit. The user must confirm they want to overwrite these changes. ' . $retry_hint;

		return new \WP_Error(
			'externally_modified',
			$message,
			[ 'status' => 409 ]
		);
	}

	/**
	 * Persist the hash of the current page state so future fetches
	 * can detect external edits.
	 *
	 * @return string The computed and stored hash.
	 */
	public function store_mcp_hash( int $post_id, PageEditorAdapterInterface $adapter ): string {
		$post_export = PageExporter::export( $post_id, $adapter );
		$post_hash   = $this->compute_content_hash( $post_export );
		update_post_meta( $post_id, PageOverrideProvider::LAST_MCP_HASH_KEY, $post_hash );
		return $post_hash;
	}

	/**
	 * Compute a deterministic hash covering the full DS payload.
	 *
	 * Tokens are sorted by key so re-saving the same logical state
	 * always yields the same hash. The payload spans both storage
	 * locations: structured data in post_content (tokens, reset, base)
	 * and the meta-backed fields (js, fonts, guidance, brief).
	 */
	public function compute_ds_content_hash( \WP_Post $ds_post ): string {
		$structured = DesignSystemPostType::get_structured_data( $ds_post );
		$tokens     = isset( $structured['tokens'] ) && is_array( $structured['tokens'] ) ? $structured['tokens'] : [];
		ksort( $tokens );

		$payload = [
			'tokens'   => $tokens,
			'reset'    => isset( $structured['reset'] ) ? (string) $structured['reset'] : '',
			'base'     => isset( $structured['base'] ) ? (string) $structured['base'] : '',
			'js'       => (string) ( get_post_meta( $ds_post->ID, DesignSystemPostType::META_BASE_JS, true ) ?: '' ),
			'fonts'    => (string) ( get_post_meta( $ds_post->ID, DesignSystemPostType::META_FONTS, true ) ?: '' ),
			'guidance' => (string) ( get_post_meta( $ds_post->ID, DesignSystemPostType::META_GUIDANCE, true ) ?: '' ),
			'brief'    => (string) ( get_post_meta( $ds_post->ID, DesignSystemPostType::META_BRIEF, true ) ?: '' ),
		];

		return wp_hash( wp_json_encode( $payload ) );
	}

	/**
	 * Reject a DS write when the supplied hash no longer matches live state.
	 *
	 * @return \WP_Error|null Null on match, WP_Error 409 on mismatch.
	 */
	public function verify_ds_content_hash( \WP_Post $ds_post, string $content_hash ): ?\WP_Error {
		if ( $content_hash !== $this->compute_ds_content_hash( $ds_post ) ) {
			return new \WP_Error(
				'content_modified',
				'This design system has been modified since you last fetched it. Call get-design-system again to get the latest content_hash.',
				[ 'status' => 409 ]
			);
		}

		return null;
	}

	/**
	 * Persist the post-write DS hash so the next agent call sees a fresh value.
	 *
	 * The caller must mutate the in-memory post to reflect the just-written
	 * state (e.g. by setting `$ds_post->post_content`) so the hash is computed
	 * against fresh data without a re-fetch.
	 *
	 * @return string The stored hash.
	 */
	public function store_ds_mcp_hash( \WP_Post $ds_post ): string {
		$hash = $this->compute_ds_content_hash( $ds_post );
		update_post_meta( $ds_post->ID, DesignSystemPostType::LAST_MCP_DS_HASH_KEY, $hash );
		return $hash;
	}
}
