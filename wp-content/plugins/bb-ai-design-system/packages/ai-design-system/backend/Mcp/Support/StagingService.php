<?php

namespace FL\DesignSystem\Mcp\Support;

use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\Page\PageExporter;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\Services\AdapterResolver;

/**
 * Manage staging-draft lifecycle for non-draft pages.
 *
 * Update tools never mutate a published, private, or pending post directly:
 * they clone it into a draft, edit there, and the publish-staged-page
 * ability copies the draft back when the user is ready. This service owns
 * the clone, the bidirectional meta links, and the source-hash bookkeeping.
 */
class StagingService {

	private AdapterResolver $adapter_resolver;
	private HashVerifier $hash_verifier;

	public function __construct( AdapterResolver $adapter_resolver, HashVerifier $hash_verifier ) {
		$this->adapter_resolver = $adapter_resolver;
		$this->hash_verifier    = $hash_verifier;
	}

	/**
	 * Resolve the staging target for a write operation.
	 *
	 * For published, private, and pending posts this creates (or reuses) a
	 * draft and returns the draft's id and adapter. Drafts and existing
	 * staging drafts are returned unchanged.
	 *
	 * @return array|\WP_Error Array with keys: post_id, adapter, source_post_id, draft_is_new.
	 */
	public function resolve_staging_target( int $post_id, PageEditorAdapterInterface $adapter ) {
		$source_post_id   = null;
		$draft_is_new     = false;
		$post             = get_post( $post_id );
		$is_staging_draft = (bool) get_post_meta( $post_id, PageOverrideProvider::STAGING_SOURCE_META_KEY, true );
		$needs_staging    = 'draft' !== $post->post_status && ! $is_staging_draft;

		if ( $is_staging_draft ) {
			$source_post_id = (int) get_post_meta( $post_id, PageOverrideProvider::STAGING_SOURCE_META_KEY, true );
		}

		if ( $needs_staging ) {
			$source_post_id = $post_id;
			$existing_draft = (int) get_post_meta( $post_id, PageOverrideProvider::STAGING_DRAFT_META_KEY, true );
			$draft_is_new   = ! $existing_draft || ! get_post( $existing_draft ) || 'trash' === get_post( $existing_draft )->post_status;
			$draft_id       = $this->get_or_create_staging_draft( $post_id, $adapter );

			if ( is_wp_error( $draft_id ) ) {
				return $draft_id;
			}

			$post_id = $draft_id;

			$adapter = $this->adapter_resolver->for_post( $post_id );
			if ( is_wp_error( $adapter ) ) {
				return $adapter;
			}
		}

		return [
			'post_id'        => $post_id,
			'adapter'        => $adapter,
			'source_post_id' => $source_post_id,
			'draft_is_new'   => $draft_is_new,
		];
	}

	/**
	 * Get an existing staging draft or create one for a source post.
	 *
	 * @return int|\WP_Error Staging draft post ID.
	 */
	public function get_or_create_staging_draft( int $source_post_id, PageEditorAdapterInterface $adapter ): int|\WP_Error {
		$existing_draft_id = (int) get_post_meta( $source_post_id, PageOverrideProvider::STAGING_DRAFT_META_KEY, true );

		if ( $existing_draft_id ) {
			$draft = get_post( $existing_draft_id );
			if ( $draft && 'trash' !== $draft->post_status ) {
				return $existing_draft_id;
			}
			delete_post_meta( $source_post_id, PageOverrideProvider::STAGING_DRAFT_META_KEY );
		}

		return $this->create_staging_draft( $source_post_id, $adapter );
	}

	/**
	 * Create a staging draft by cloning a source post.
	 *
	 * @return int|\WP_Error New draft post ID.
	 */
	public function create_staging_draft( int $source_post_id, PageEditorAdapterInterface $adapter ): int|\WP_Error {
		$source = get_post( $source_post_id );

		$draft_id = wp_insert_post( [
			'post_title'  => $source->post_title . ' [AI Draft]',
			'post_type'   => $source->post_type,
			'post_status' => 'draft',
		], true );

		if ( is_wp_error( $draft_id ) ) {
			return $draft_id;
		}

		$ds_meta_keys = [
			PageOverrideProvider::DS_REF_META_KEY,
			PageOverrideProvider::PAGE_CSS_META_KEY,
			PageOverrideProvider::PAGE_JS_META_KEY,
		];
		foreach ( $ds_meta_keys as $key ) {
			$value = get_post_meta( $source_post_id, $key, true );
			if ( '' !== $value && false !== $value ) {
				update_post_meta( $draft_id, $key, $value );
			}
		}

		$bb_enabled = get_post_meta( $source_post_id, '_fl_builder_enabled', true );
		if ( $bb_enabled && class_exists( 'FLBuilderModel' ) ) {
			update_post_meta( $draft_id, '_fl_builder_enabled', true );

			foreach ( [ 'published', 'draft' ] as $status ) {
				$layout = \FLBuilderModel::get_layout_data( $status, $source_post_id );
				\FLBuilderModel::update_layout_data( $layout, $status, $draft_id );
			}

			foreach ( [ '_fl_builder_data_settings', '_fl_builder_draft_settings' ] as $key ) {
				$value = get_post_meta( $source_post_id, $key, true );
				if ( $value ) {
					update_post_meta( $draft_id, $key, $value );
				}
			}
		}

		if ( ! $bb_enabled || ! class_exists( 'FLBuilderModel' ) ) {
			wp_update_post( [
				'ID'           => $draft_id,
				'post_content' => wp_slash( $source->post_content ),
			] );
		}

		update_post_meta( $draft_id, PageOverrideProvider::STAGING_SOURCE_META_KEY, $source_post_id );
		update_post_meta( $source_post_id, PageOverrideProvider::STAGING_DRAFT_META_KEY, $draft_id );

		$source_export = PageExporter::export( $source_post_id, $adapter );
		$source_hash   = $this->hash_verifier->compute_content_hash( $source_export );
		update_post_meta( $draft_id, PageOverrideProvider::STAGING_SOURCE_HASH_KEY, $source_hash );

		return $draft_id;
	}

	/**
	 * Validate that a post is a valid staging draft with bidirectional meta links.
	 *
	 * @return \WP_Error|null Null on success, WP_Error on failure.
	 */
	public function validate_staging_draft( int $draft_id ): ?\WP_Error {
		if ( ! $draft_id || ! get_post( $draft_id ) ) {
			return new \WP_Error(
				'invalid_draft',
				'Invalid or missing draft ID.',
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $draft_id ) ) {
			return new \WP_Error(
				'forbidden',
				'You do not have permission to edit this post.',
				[ 'status' => 403 ]
			);
		}

		$source_id = (int) get_post_meta( $draft_id, PageOverrideProvider::STAGING_SOURCE_META_KEY, true );
		if ( ! $source_id ) {
			return new \WP_Error(
				'not_staging_draft',
				'This post is not a staging draft. Only drafts created by the update-page-html staging workflow can be published or discarded.',
				[ 'status' => 422 ]
			);
		}

		$source = get_post( $source_id );
		if ( ! $source ) {
			return new \WP_Error(
				'source_not_found',
				'The original page this draft was created from no longer exists.',
				[ 'status' => 404 ]
			);
		}

		// Drafts persist across role changes and ownership reassignment, so
		// the draft-side check above is not sufficient on its own.
		if ( ! current_user_can( 'edit_post', $source_id ) ) {
			return new \WP_Error(
				'forbidden',
				'You do not have permission to edit the original page.',
				[ 'status' => 403 ]
			);
		}

		$linked_draft_id = (int) get_post_meta( $source_id, PageOverrideProvider::STAGING_DRAFT_META_KEY, true );
		if ( $linked_draft_id !== $draft_id ) {
			return new \WP_Error(
				'staging_link_mismatch',
				'The staging relationship is inconsistent. The original page does not reference this draft.',
				[ 'status' => 409 ]
			);
		}

		return null;
	}
}
