<?php

namespace FL\DesignSystem\Mcp\Abilities\Pages;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\HashVerifier;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Mcp\Support\StagingService;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * MCP ability: update-page-assets.
 *
 * Updates page-level CSS and/or JavaScript without touching sections.
 * Routes to a staging draft for non-draft posts. Hash-gated; rejects
 * writes when external modifications are unacknowledged.
 */
class UpdatePageAssets extends BaseAbility {

	private PageResolver $page_resolver;
	private HashVerifier $hash_verifier;
	private StagingService $staging_service;

	public function __construct( PageResolver $page_resolver, HashVerifier $hash_verifier, StagingService $staging_service ) {
		$this->page_resolver   = $page_resolver;
		$this->hash_verifier   = $hash_verifier;
		$this->staging_service = $staging_service;
	}

	public function name(): string {
		return 'beaver-builder-ai/update-page-assets';
	}

	public function definition(): array {
		return [
			'label'               => 'Update Page Assets',
			'description'         => 'Updates a page\'s page-level CSS and/or JavaScript without modifying any sections. Pass page_css to replace ALL page-level CSS. Pass page_js to replace ALL page-level JavaScript. Only pass the assets you want to update -- omitted assets are left unchanged. Requires the content_hash from get-page-outline, get-page-blocks, or get-page-html to prevent conflicting edits. For published, private, and pending pages, changes are saved to a staging draft. Always share the preview URL with the user after updating.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'                      => [
						'type'        => 'integer',
						'description' => 'WordPress post ID.',
					],
					'content_hash'                 => [
						'type'        => 'string',
						'description' => 'Hash from get-page-outline, get-page-blocks, or get-page-html response. Required to prevent overwriting changes made since the page was last fetched.',
					],
					'page_css'                     => [
						'type'        => 'string',
						'description' => 'Complete page-level CSS. Replaces all existing page CSS.',
					],
					'page_js'                      => [
						'type'        => 'string',
						'description' => 'Complete page-level JavaScript. Replaces all existing page JS.',
					],
					'acknowledge_external_changes' => [
						'type'        => 'boolean',
						'description' => 'Set to true only after the user has explicitly confirmed they want to overwrite content that was modified outside of MCP. Required when get-page-html, get-page-outline, or get-page-blocks returned externally_modified: true.',
					],
				],
				'required'   => [ 'post_id', 'content_hash' ],
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
				'summary'     => "Updates a page's CSS and JavaScript assets",
			],
		];
	}

	public function execute( array $input ) {
		$post_id      = (int) ( $input['post_id'] ?? 0 );
		$content_hash = $input['content_hash'] ?? '';
		$acknowledged = ! empty( $input['acknowledge_external_changes'] );
		$has_page_css = array_key_exists( 'page_css', $input );
		$has_page_js  = array_key_exists( 'page_js', $input );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( '' === $content_hash ) {
			return new \WP_Error(
				'missing_content_hash',
				'The content_hash field is required. Call get-page-html first to obtain the current content hash.',
				[ 'status' => 400 ]
			);
		}

		if ( ! $has_page_css && ! $has_page_js ) {
			return new \WP_Error(
				'missing_assets',
				'At least one of page_css or page_js is required.',
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
			'Call update-page-assets again with acknowledge_external_changes: true after getting user confirmation.'
		);
		if ( is_wp_error( $drift_error ) ) {
			return $drift_error;
		}

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

		// Update page-level assets directly via post meta.
		// Sanitize matches the REST endpoint and PageImporter paths.
		if ( $has_page_css ) {
			update_post_meta( $post_id, PageOverrideProvider::PAGE_CSS_META_KEY, DesignSystemPostType::sanitize_css( (string) $input['page_css'] ) );
		}
		if ( $has_page_js ) {
			update_post_meta( $post_id, PageOverrideProvider::PAGE_JS_META_KEY, DesignSystemPostType::sanitize_js( (string) $input['page_js'] ) );
		}

		$new_hash = $this->hash_verifier->store_mcp_hash( $post_id, $adapter );

		$response = [
			'post_id'      => $post_id,
			'url'          => $this->page_resolver->get_post_url( $post_id ),
			'content_hash' => $new_hash,
			'updated'      => array_filter( [
				'page_css' => $has_page_css,
				'page_js'  => $has_page_js,
			] ),
		];

		if ( $source_post_id ) {
			$response['staging_source'] = $source_post_id;
			$response['message']        = 'Changes saved to a staging draft. The original page has not been modified. Share the preview URL with the user and ask them to publish when ready.';
		}

		return $response;
	}
}
