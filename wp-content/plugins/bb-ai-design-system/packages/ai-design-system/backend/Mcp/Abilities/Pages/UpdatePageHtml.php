<?php

namespace FL\DesignSystem\Mcp\Abilities\Pages;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\HashVerifier;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Mcp\Support\StagingService;
use FL\DesignSystem\Page\PageImporter;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * MCP ability: update-page-html.
 *
 * Rewrites a page's content from a complete HTML document. For published,
 * private, and pending pages, mutations are routed to a staging draft.
 * Hash-gated; rejects writes when external modifications are unacknowledged.
 */
class UpdatePageHtml extends BaseAbility {

	private PageResolver $page_resolver;
	private HashVerifier $hash_verifier;
	private StagingService $staging_service;

	public function __construct( PageResolver $page_resolver, HashVerifier $hash_verifier, StagingService $staging_service ) {
		$this->page_resolver   = $page_resolver;
		$this->hash_verifier   = $hash_verifier;
		$this->staging_service = $staging_service;
	}

	public function name(): string {
		return 'beaver-builder-ai/update-page-html';
	}

	public function definition(): array {
		return [
			'label'               => 'Update Page HTML',
			'description'         => 'Pass a complete HTML document via `html`. All CSS must be embedded inside `<style>` blocks in `<head>` and all JavaScript inside `<script>` blocks. There are no separate `css` or `js` parameters; unknown top-level keys are rejected. Saves changes to a page\'s content using HTML that follows the format spec. WARNING: this rewrites the entire page and is expensive in tokens. For targeted edits -- even edits across multiple sections -- strongly prefer update-page-blocks, which applies update/add/remove/move operations in a single batch against specific blocks. Use update-page-html only when you are genuinely rewriting the whole page. For published, private, and pending pages, changes are saved to a staging draft -- the original page is never modified directly. For draft pages, updates in place. Requires the content_hash from get-page-outline, get-page-blocks, or get-page-html to prevent conflicting edits. Before calling this, call get-design-system with the page\'s design system UUID to load format spec and design tokens. If get-page-html, get-page-outline, or get-page-blocks returned externally_modified: true, you MUST get explicit user confirmation and pass acknowledge_external_changes: true -- otherwise the update will be rejected. Always share the preview URL with the user after updating so they can review the result. Page CSS and page JS are preserved when the document omits @page or its @page block is empty; to clear page CSS or page JS, call update-page-assets with empty strings.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'                      => [
						'type'        => 'integer',
						'description' => 'WordPress post ID to update.',
					],
					'html'                         => [
						'type'        => 'string',
						'description' => 'Complete HTML document following the format spec. All CSS belongs inside `<style>` blocks in `<head>` and all JavaScript inside `<script>` blocks; there is no separate `css` or `js` parameter and unknown top-level keys are rejected.',
					],
					'content_hash'                 => [
						'type'        => 'string',
						'description' => 'Hash from get-page-outline, get-page-blocks, or get-page-html response. Required to prevent overwriting changes made since the page was last fetched.',
					],
					'acknowledge_external_changes' => [
						'type'        => 'boolean',
						'description' => 'Set to true only after the user has explicitly confirmed they want to overwrite content that was modified outside of MCP. Required when get-page-html, get-page-outline, or get-page-blocks returned externally_modified: true.',
					],
				],
				'required'   => [ 'post_id', 'html', 'content_hash' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [
					'destructive' => true,
					'idempotent'  => true,
				],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'pages',
				'summary'     => 'Saves changes to a page using updated HTML',
			],
		];
	}

	public function execute( array $input ) {
		$post_id      = (int) ( $input['post_id'] ?? 0 );
		$html         = $input['html'] ?? '';
		$content_hash = $input['content_hash'] ?? '';
		$acknowledged = ! empty( $input['acknowledge_external_changes'] );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( '' === $html ) {
			return new \WP_Error(
				'missing_html',
				'The html field is required.',
				[ 'status' => 400 ]
			);
		}

		if ( '' === $content_hash ) {
			return new \WP_Error(
				'missing_content_hash',
				'The content_hash field is required. Call get-page-html first to obtain the current content hash.',
				[ 'status' => 400 ]
			);
		}

		$adapter = $this->page_resolver->resolve_adapter( $post_id );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$drift_error = $this->hash_verifier->check_external_modifications(
			$post_id,
			$acknowledged,
			$adapter,
			'Call update-page-html again with acknowledge_external_changes: true after getting user confirmation.'
		);
		if ( is_wp_error( $drift_error ) ) {
			return $drift_error;
		}

		$ds_uuid = get_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, true ) ?: null;

		// Resolve staging target (creates draft for non-draft posts).
		$staging = $this->staging_service->resolve_staging_target( $post_id, $adapter );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}

		$post_id        = $staging['post_id'];
		$adapter        = $staging['adapter'];
		$source_post_id = $staging['source_post_id'];

		$hash_error = $this->hash_verifier->verify_content_hash( $post_id, $content_hash, $adapter, $staging['draft_is_new'] );
		if ( is_wp_error( $hash_error ) ) {
			return $hash_error;
		}

		// Clear existing layout.
		$adapter->clear_layout( $post_id );

		// Use the existing DS — never create a new one on update.
		$options = [
			'design_system_uuid'   => $ds_uuid,
			'create_design_system' => false,
		];

		$result = PageImporter::import( $html, $post_id, $adapter, $options );

		$new_hash = $this->hash_verifier->store_mcp_hash( $post_id, $adapter );

		$response = [
			'post_id'      => $post_id,
			'sections'     => $result['sections'],
			'errors'       => $result['errors'],
			'url'          => $this->page_resolver->get_post_url( $post_id ),
			'content_hash' => $new_hash,
		];

		if ( ! empty( $result['warnings'] ) ) {
			$response['warnings'] = $result['warnings'];
		}

		if ( $source_post_id ) {
			$response['staging_source'] = $source_post_id;
			$response['message']        = 'Changes saved to a staging draft. The original page has not been modified. Share the preview URL with the user and ask them to publish when ready.';
		}

		return $response;
	}
}
