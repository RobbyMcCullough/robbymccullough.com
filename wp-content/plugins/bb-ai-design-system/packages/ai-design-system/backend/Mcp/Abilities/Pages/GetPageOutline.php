<?php

namespace FL\DesignSystem\Mcp\Abilities\Pages;

use FL\DesignSystem\BlockEditor\BlockEditorPageAdapter;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\HashVerifier;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Page\PageExporter;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * MCP ability: get-page-outline.
 *
 * Lightweight block tree plus content_hash, used as the starting point
 * for any page edit workflow. Returns staging-draft state when one
 * exists for the requested post.
 */
class GetPageOutline extends BaseAbility {

	private PageResolver $page_resolver;
	private HashVerifier $hash_verifier;

	public function __construct( PageResolver $page_resolver, HashVerifier $hash_verifier ) {
		$this->page_resolver = $page_resolver;
		$this->hash_verifier = $hash_verifier;
	}

	public function name(): string {
		return 'beaver-builder-ai/get-page-outline';
	}

	public function definition(): array {
		return [
			'label'               => 'Get Page Outline',
			'description'         => 'Returns a lightweight outline of all blocks on a page with their IDs, types, and labels. This is the starting point for any page edit workflow. From here, call get-page-blocks to read one or more blocks in detail, then update-page-blocks to apply changes (update/add/remove/move) in a single batch. The content_hash in the response is used for subsequent write operations. Only fall back to get-page-html when you need the full page as a single document (e.g., full page rewrites via update-page-html). For any targeted edit, even across multiple sections, the outline + get-page-blocks + update-page-blocks path is dramatically cheaper on tokens. Capability info per block (which fields are read-write) is returned by get-page-blocks, not by the outline.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'WordPress post ID.',
					],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true ],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'pages',
				'summary'     => 'Returns a lightweight block outline for a page',
			],
		];
	}

	public function execute( array $input ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		// If this post has a staging draft, read from the draft instead.
		$staging_draft_id = (int) get_post_meta( $post_id, PageOverrideProvider::STAGING_DRAFT_META_KEY, true );
		if ( $staging_draft_id ) {
			$draft = get_post( $staging_draft_id );
			if ( $draft && 'trash' !== $draft->post_status ) {
				$post_id = $staging_draft_id;
			} else {
				delete_post_meta( $post_id, PageOverrideProvider::STAGING_DRAFT_META_KEY );
			}
		}

		$adapter = $this->page_resolver->resolve_adapter( $post_id );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$blocks = $adapter->get_outline( $post_id );

		// On BlockEditor pages with no DS blocks, the outline contains only
		// synthetic `block-N` ids that get-page-blocks cannot resolve. Surface
		// an empty outline + note so agents pivot to get-page-html instead of
		// hitting node_not_found. The check is BlockEditor-specific because BB
		// outlines emit row/column/native types that BlockService resolves.
		$note = null;
		if ( $adapter instanceof BlockEditorPageAdapter && ! empty( $blocks ) && ! $this->outline_has_ds_blocks( $blocks ) ) {
			$blocks = [];
			$note   = 'This page has no editable blocks reachable via get-page-blocks. Use get-page-html to read the full page.';
		}

		// Compute content_hash via PageExporter so agents can use it for writes.
		$export       = PageExporter::export( $post_id, $adapter );
		$content_hash = $this->hash_verifier->compute_content_hash( $export );

		$post = get_post( $post_id );

		$response = [
			'post_id'      => $post_id,
			'title'        => $post ? $post->post_title : '',
			'url'          => $post ? get_permalink( $post_id ) : '',
			'content_hash' => $content_hash,
			'blocks'       => $blocks,
		];

		if ( $this->hash_verifier->detect_external_modification( $post_id, $content_hash ) ) {
			$response['externally_modified'] = true;
			$response['message']             = HashVerifier::EXTERNAL_MODIFICATION_READ_MESSAGE;
		}

		if ( null !== $note ) {
			$response['note'] = $note;
		}

		// Include design system info if assigned.
		$ds_uuid = get_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, true ) ?: null;
		if ( $ds_uuid ) {
			$ds_post                   = DesignSystemPostType::get_by_uuid( $ds_uuid );
			$response['design_system'] = [
				'uuid' => $ds_uuid,
				'name' => $ds_post ? $ds_post->post_title : '',
			];
		}

		return $response;
	}

	/**
	 * Recursively check whether an outline contains any addressable DS block.
	 *
	 * @param array $blocks Outline entries (with optional `children` arrays).
	 * @return bool True if any entry's type is `ds-custom`.
	 */
	private function outline_has_ds_blocks( array $blocks ): bool {
		foreach ( $blocks as $entry ) {
			if ( ( $entry['type'] ?? '' ) === 'ds-custom' ) {
				return true;
			}
			if ( ! empty( $entry['children'] ) && is_array( $entry['children'] ) ) {
				if ( $this->outline_has_ds_blocks( $entry['children'] ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
