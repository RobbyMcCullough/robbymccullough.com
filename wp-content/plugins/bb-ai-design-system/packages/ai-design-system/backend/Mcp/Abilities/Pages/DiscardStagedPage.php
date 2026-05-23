<?php

namespace FL\DesignSystem\Mcp\Abilities\Pages;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Mcp\Support\StagingService;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * MCP ability: discard-staged-page.
 *
 * Permanently deletes a staging draft. The original page is left
 * unchanged. Only acts on validated staging drafts (bidirectional
 * meta links must be intact).
 */
class DiscardStagedPage extends BaseAbility {

	private PageResolver $page_resolver;
	private StagingService $staging_service;

	public function __construct( PageResolver $page_resolver, StagingService $staging_service ) {
		$this->page_resolver   = $page_resolver;
		$this->staging_service = $staging_service;
	}

	public function name(): string {
		return 'beaver-builder-ai/discard-staged-page';
	}

	public function definition(): array {
		return [
			'label'               => 'Discard Staged Page',
			'description'         => 'Discards a staged edit by permanently deleting the draft. The original page is left unchanged. WARNING: This destroys any work saved in the draft. Only call this if the user has clearly and explicitly asked to delete or discard the draft. Never suggest discarding a draft -- if the user does not want the draft, tell them they can delete it manually in WordPress.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'The staging draft post ID to discard.',
					],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [
					'destructive' => true,
					'idempotent'  => false,
				],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'pages',
				'summary'     => 'Discards a staged draft without publishing',
			],
		];
	}

	public function execute( array $input ) {
		$draft_id = (int) ( $input['post_id'] ?? 0 );

		$error = $this->staging_service->validate_staging_draft( $draft_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$source_id = (int) get_post_meta( $draft_id, PageOverrideProvider::STAGING_SOURCE_META_KEY, true );

		// Delete staging draft and clean up meta.
		wp_delete_post( $draft_id, true );
		delete_post_meta( $source_id, PageOverrideProvider::STAGING_DRAFT_META_KEY );

		return [
			'post_id' => $source_id,
			'url'     => $this->page_resolver->get_post_url( $source_id ),
			'message' => 'Staging draft discarded. The original page is unchanged.',
		];
	}
}
