<?php

namespace FL\DesignSystem\Mcp\Abilities\Pages;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\HashVerifier;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Mcp\Support\StagingService;
use FL\DesignSystem\Page\PageExporter;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * MCP ability: publish-staged-page.
 *
 * Copies a staging draft's content (DS metadata + layout data) onto its
 * source post and deletes the draft. Refuses to publish when the source
 * has drifted since the draft was created so the user can reconcile.
 */
class PublishStagedPage extends BaseAbility {

	private PageResolver $page_resolver;
	private HashVerifier $hash_verifier;
	private StagingService $staging_service;

	public function __construct( PageResolver $page_resolver, HashVerifier $hash_verifier, StagingService $staging_service ) {
		$this->page_resolver   = $page_resolver;
		$this->hash_verifier   = $hash_verifier;
		$this->staging_service = $staging_service;
	}

	public function name(): string {
		return 'beaver-builder-ai/publish-staged-page';
	}

	public function definition(): array {
		return [
			'label'               => 'Publish Staged Page',
			'description'         => 'Publishes a staged edit by copying changes from the draft to the original page. The draft is deleted after publishing. If the original page was modified since the draft was created, publishing is blocked to prevent overwriting those changes -- inform the user so they can reconcile manually. Only call this when the user explicitly asks to publish, go live, or make changes permanent. Never publish automatically.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'The staging draft post ID returned by update-page-html.',
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
				'summary'     => 'Publishes a staged draft to the live page',
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

		$adapter = $this->page_resolver->resolve_adapter( $source_id );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		// Block if the source was modified since the draft was created.
		$stored_hash    = get_post_meta( $draft_id, PageOverrideProvider::STAGING_SOURCE_HASH_KEY, true );
		$current_export = PageExporter::export( $source_id, $adapter );
		$current_hash   = $this->hash_verifier->compute_content_hash( $current_export );

		if ( $stored_hash && $stored_hash !== $current_hash ) {
			return new \WP_Error(
				'source_modified',
				'The original page has been modified since this draft was created. Publishing would overwrite those changes. Let the user know so they can decide how to reconcile. The draft has not been deleted.',
				[ 'status' => 409 ]
			);
		}

		// Determine which editor the source uses (this drives rendering).
		$source_bb_enabled = get_post_meta( $source_id, '_fl_builder_enabled', true );

		// Copy DS metadata from draft to source.
		$ds_meta_keys = [
			PageOverrideProvider::DS_REF_META_KEY,
			PageOverrideProvider::PAGE_CSS_META_KEY,
			PageOverrideProvider::PAGE_JS_META_KEY,
		];
		foreach ( $ds_meta_keys as $key ) {
			$value = get_post_meta( $draft_id, $key, true );
			update_post_meta( $source_id, $key, $value ?: '' );
		}

		// Copy layout data based on the source's editor, not the draft's.
		if ( $source_bb_enabled && class_exists( 'FLBuilderModel' ) ) {
			// Use FLBuilderModel to read/write layout data so it goes through
			// BB's own pipeline (clean_layout_data, slash_settings, compat filters,
			// cache management). Raw get_post_meta/update_post_meta bypasses this
			// and can produce data BB's renderer doesn't recognize.
			foreach ( [ 'published', 'draft' ] as $status ) {
				$layout = \FLBuilderModel::get_layout_data( $status, $draft_id );
				\FLBuilderModel::update_layout_data( $layout, $status, $source_id );
			}

			// Copy layout-level settings (row width, spacing, etc.).
			foreach ( [ '_fl_builder_data_settings', '_fl_builder_draft_settings' ] as $key ) {
				$value = get_post_meta( $draft_id, $key, true );
				if ( $value ) {
					update_post_meta( $source_id, $key, $value );
				} else {
					delete_post_meta( $source_id, $key );
				}
			}
		} else {
			$draft_post = get_post( $draft_id );
			kses_remove_filters();
			wp_update_post( [
				'ID'           => $source_id,
				'post_content' => wp_slash( $draft_post->post_content ),
			] );
			kses_init_filters();
		}

		// Cleanup: delete staging draft and remove meta link.
		wp_delete_post( $draft_id, true );
		delete_post_meta( $source_id, PageOverrideProvider::STAGING_DRAFT_META_KEY );

		return [
			'post_id' => $source_id,
			'url'     => get_permalink( $source_id ),
			'message' => 'Changes published successfully. The staging draft has been removed.',
		];
	}
}
