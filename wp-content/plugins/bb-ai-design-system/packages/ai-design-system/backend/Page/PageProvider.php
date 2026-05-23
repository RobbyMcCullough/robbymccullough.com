<?php

namespace FL\DesignSystem\Page;

use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\Services\AdapterResolver;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Font\GoogleFontsUrl;

/**
 * REST controller for page import/export operations.
 *
 * Provides endpoints for creating pages from HTML, exporting pages
 * as HTML, and updating page content via the design system parsers.
 */
class PageProvider {

	private AuthInterface $auth;
	private AdapterResolver $adapter_resolver;

	/**
	 * @param AuthInterface   $auth             Auth adapter for permission checks.
	 * @param AdapterResolver $adapter_resolver Editor adapter resolver.
	 */
	public function __construct(
		AuthInterface $auth,
		AdapterResolver $adapter_resolver
	) {
		$this->auth             = $auth;
		$this->adapter_resolver = $adapter_resolver;
	}

	/**
	 * Register REST routes on rest_api_init.
	 */
	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the page import/export REST routes.
	 */
	public function register_routes() {
		$namespace        = 'fl-design-system/v1';
		$read_permission  = [ $this->auth, 'content_creator_permission_callback' ];
		$write_permission = [ $this->auth, 'content_creator_permission_callback' ];

		register_rest_route( $namespace, '/pages', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'create_page' ],
			'permission_callback' => $write_permission,
		] );

		register_rest_route( $namespace, '/pages/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export_page' ],
				'permission_callback' => $read_permission,
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_page' ],
				'permission_callback' => $write_permission,
			],
		] );
	}

	/**
	 * Create a new WordPress post and import HTML content.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_page( \WP_REST_Request $request ) {
		$body = $request->get_json_params();

		$title     = $body['title'] ?? '';
		$html      = $body['html'] ?? '';
		$post_type = $body['post_type'] ?? 'page';
		$status    = $body['status'] ?? 'draft';
		$ds_uuid   = $body['design_system_uuid'] ?? null;

		if ( '' === $title && '' === $html ) {
			return new \WP_Error(
				'missing_params',
				'Either title or html is required.',
				[ 'status' => 400 ],
			);
		}

		$post_type_obj = get_post_type_object( $post_type );
		if ( ! $post_type_obj ) {
			return new \WP_Error(
				'invalid_post_type',
				"The post type '{$post_type}' does not exist.",
				[ 'status' => 400 ],
			);
		}

		if ( ! current_user_can( $post_type_obj->cap->create_posts ) ) {
			return new \WP_Error(
				'forbidden',
				"You do not have permission to create {$post_type} posts.",
				[ 'status' => 403 ],
			);
		}

		$post_id = wp_insert_post( [
			'post_title'  => sanitize_text_field( $title ),
			'post_type'   => sanitize_text_field( $post_type ),
			'post_status' => sanitize_text_field( $status ),
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Enable BB for the post if BB is active.
		if ( class_exists( 'FLBuilderModel' ) ) {
			update_post_meta( $post_id, '_fl_builder_enabled', true );
		}

		$adapter = $this->resolve_adapter( $post_id );

		$options = [];
		if ( $ds_uuid ) {
			$options['design_system_uuid'] = $ds_uuid;
		}

		$result = PageImporter::import( $html, $post_id, $adapter, $options );

		$brief    = $body['brief'] ?? null;
		$guidance = $body['guidance'] ?? null;

		// Save brief and guidance when a new DS was auto-created.
		if ( $result['ds_uuid'] && ( $brief || $guidance ) ) {
			$ds_post = DesignSystemPostType::get_by_uuid( $result['ds_uuid'] );
			if ( $ds_post ) {
				if ( $brief ) {
					update_post_meta( $ds_post->ID, DesignSystemPostType::META_BRIEF, $brief );
				}
				if ( $guidance ) {
					update_post_meta( $ds_post->ID, DesignSystemPostType::META_GUIDANCE, $guidance );
				}
			}
		}

		$response = [
			'post_id'  => $post_id,
			'url'      => $this->get_post_url( $post_id ),
			'sections' => $result['sections'],
			'errors'   => $result['errors'],
		];

		if ( $result['ds_uuid'] ) {
			$response['design_system_uuid'] = $result['ds_uuid'];
		}

		return new \WP_REST_Response( $response, 201 );
	}

	/**
	 * Export a page as a spec-compliant HTML document.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function export_page( \WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$adapter = $this->resolve_adapter( $post_id );

		$result       = PageExporter::export( $post_id, $adapter );
		$post         = get_post( $post_id );
		$content_hash = $this->compute_content_hash( $result );

		return new \WP_REST_Response( [
			'post_id'             => $post_id,
			'title'               => $result['title'],
			'status'              => $result['status'],
			'post_type'           => $post ? $post->post_type : 'page',
			'design_system_uuid'  => $result['design_system_uuid'],
			'html'                => $result['html'],
			'content_hash'        => $content_hash,
		], 200 );
	}

	/**
	 * Update a page by clearing existing content and re-importing HTML.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_page( \WP_REST_Request $request ) {
		$post_id      = (int) $request->get_param( 'id' );
		$body         = $request->get_json_params();
		$html         = $body['html'] ?? '';
		$content_hash = $body['content_hash'] ?? '';

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( '' === $html ) {
			return new \WP_Error(
				'missing_html',
				'The html field is required.',
				[ 'status' => 400 ],
			);
		}

		if ( '' === $content_hash ) {
			return new \WP_Error(
				'missing_content_hash',
				'The content_hash field is required. Call the export endpoint first to obtain the current content hash.',
				[ 'status' => 400 ],
			);
		}

		$ds_uuid = get_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, true ) ?: null;

		if ( ! $ds_uuid ) {
			return new \WP_Error(
				'no_design_system',
				'This page doesn\'t have an associated design system. Only pages created with BB Design System can be updated via this endpoint.',
				[ 'status' => 422 ],
			);
		}

		$adapter = $this->resolve_adapter( $post_id );

		// Verify content hasn't changed since it was last fetched.
		$current      = PageExporter::export( $post_id, $adapter );
		$current_hash = $this->compute_content_hash( $current );
		if ( $content_hash !== $current_hash ) {
			return new \WP_Error(
				'content_modified',
				'This page has been modified since you last fetched it. Export the page again to get the latest version.',
				[ 'status' => 409 ],
			);
		}

		// Clear existing layout.
		$adapter->clear_layout( $post_id );

		$options = [
			'design_system_uuid'   => $ds_uuid,
			'create_design_system' => false,
		];

		$result = PageImporter::import( $html, $post_id, $adapter, $options );

		return new \WP_REST_Response( [
			'post_id'  => $post_id,
			'sections' => $result['sections'],
			'errors'   => $result['errors'],
		], 200 );
	}

	/**
	 * Resolve the appropriate editor adapter for a post.
	 *
	 * Thin wrapper over {@see AdapterResolver} preserving the local call shape.
	 *
	 * @param int|null $post_id Optional post ID; passed through for editor detection.
	 * @return PageEditorAdapterInterface
	 */
	private function resolve_adapter( ?int $post_id = null ): PageEditorAdapterInterface {
		return $this->adapter_resolver->for_post( $post_id );
	}

	/**
	 * Get the viewable URL for a post.
	 *
	 * Returns the preview URL for non-published posts (drafts, pending, etc.)
	 * since they are not publicly accessible at their permalink.
	 *
	 * @param int $post_id Post ID.
	 * @return string URL.
	 */
	private function get_post_url( int $post_id ): string {
		$post = get_post( $post_id );
		if ( $post && 'publish' !== $post->post_status ) {
			return get_preview_post_link( $post_id );
		}
		return get_permalink( $post_id );
	}

	/**
	 * Compute a hash of exported page content for optimistic concurrency control.
	 *
	 * Google Fonts link URLs are normalized (variant segment stripped) before
	 * hashing so hashes stay stable when the exporter starts emitting
	 * variants. Without this normalization every pre-upgrade page's stored
	 * hash would go stale on first edit and surface as "externally modified".
	 *
	 * @param array $export_result Result from PageExporter::export().
	 * @return string Content hash.
	 */
	private function compute_content_hash( array $export_result ): string {
		$html = $export_result['html'] ?? '';
		return wp_hash( GoogleFontsUrl::strip_variants_from_html( $html ) );
	}

	/**
	 * Validate that a post exists and the user can edit it.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Error|null Null on success, WP_Error on failure.
	 */
	private function validate_post( int $post_id ) {
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error(
				'invalid_post',
				'Invalid or missing post ID.',
				[ 'status' => 404 ],
			);
		}

		if ( ! $this->auth->can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'forbidden',
				'You do not have permission to edit this post.',
				[ 'status' => 403 ],
			);
		}

		return null;
	}
}
