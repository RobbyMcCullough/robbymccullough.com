<?php

namespace FL\DesignSystem\DesignSystem;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Page\BlankPageCreator;
use FL\DesignSystem\Services\PostTypeAccess;

/**
 * REST endpoints for design system CRUD and default management.
 */
class DesignSystemProvider {

	private AuthInterface $auth;
	private DesignSystemUsageQuery $usage_query;

	/**
	 * @param AuthInterface           $auth        Auth adapter for permission checks.
	 * @param DesignSystemUsageQuery  $usage_query Query service for posts referencing a design system.
	 */
	public function __construct( AuthInterface $auth, DesignSystemUsageQuery $usage_query ) {
		$this->auth        = $auth;
		$this->usage_query = $usage_query;
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		$namespace  = 'fl-design-system/v1';
		$uuid_regex = '[a-f0-9-]+';

		// List all design systems.
		register_rest_route(
			$namespace,
			'/design-systems',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_design_systems' ],
					'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_design_system' ],
					'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
				],
			]
		);

		// Single design system by UUID.
		register_rest_route(
			$namespace,
			'/design-systems/(?P<uuid>' . $uuid_regex . ')',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_design_system' ],
					'permission_callback' => [ $this->auth, 'read_permission_callback' ],
				],
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update_design_system' ],
					'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_design_system' ],
					'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
				],
			]
		);

		// Default design system (admin-only setter).
		register_rest_route(
			$namespace,
			'/default-design-system',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'set_default' ],
					'permission_callback' => [ $this->auth, 'admin_permission_callback' ],
				],
			]
		);

		// Create a blank page attached to an existing design system.
		register_rest_route(
			$namespace,
			'/design-systems/(?P<uuid>' . $uuid_regex . ')/pages',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_page' ],
					'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
					'args'                => [
						'uuid' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// List posts that reference a given design system.
		register_rest_route(
			$namespace,
			'/design-systems/(?P<uuid>' . $uuid_regex . ')/posts',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_posts' ],
					'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
					'args'                => [
						'uuid' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);
	}

	/**
	 * List all design systems.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_design_systems() {
		$query_args = [
			'post_type'      => DesignSystemPostType::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];

		if ( ! current_user_can( 'edit_others_design_systems' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$posts = get_posts( $query_args );

		$items = array_map(
			[ DesignSystemPostType::class, 'format_for_response' ],
			$posts
		);

		return new \WP_REST_Response(
			[
				'items'       => $items,
				'defaultUuid' => DesignSystemPostType::get_default_uuid(),
			],
			200
		);
	}

	/**
	 * Create a new design system.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_design_system( \WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) || ! is_array( $body ) ) {
			return new \WP_Error(
				'validation_error',
				__( 'Request body must be a JSON object.', 'fl-design-system' ),
				[ 'status' => 422 ],
			);
		}

		$result = DesignSystemPostType::create( $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			DesignSystemPostType::format_for_response( $result ),
			201
		);
	}

	/**
	 * Get a single design system by UUID.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_design_system( \WP_REST_Request $request ) {
		$uuid = $request->get_param( 'uuid' );
		$post = DesignSystemPostType::get_by_uuid( $uuid );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Design system not found.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		// M-14: the route is readable by any logged-in user because the
		// CSS, JS, and tokens it returns are public-bound through the
		// frontend render path anyway. The audit's substantive concern
		// was leaking `guidance` and `brief` (LLM prompt fragments with
		// no rendering purpose) to non-author readers, so strip those
		// from the response unless the caller has edit access.
		$payload = DesignSystemPostType::format_for_response( $post );
		if ( ! current_user_can( 'edit_others_design_systems' )
			&& get_current_user_id() !== (int) $post->post_author
		) {
			unset( $payload['guidance'], $payload['brief'] );
		}

		return new \WP_REST_Response( $payload, 200 );
	}

	/**
	 * Update a design system by UUID.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_design_system( \WP_REST_Request $request ) {
		$uuid = $request->get_param( 'uuid' );
		$post = DesignSystemPostType::get_by_uuid( $uuid );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Design system not found.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		if ( ! WordPressAuth::can_edit_design_system( $post ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'You do not have permission to edit this design system.',
				[ 'status' => 403 ],
			);
		}

		$body    = $request->get_json_params();
		$updates = [];

		if ( isset( $body['label'] ) ) {
			$updates['post_title'] = sanitize_text_field( $body['label'] );
		}

		// Accept structured { tokens, reset, base } or legacy raw CSS.
		$has_structured = isset( $body['tokens'] ) || isset( $body['reset'] ) || isset( $body['base'] );

		if ( $has_structured ) {
			// Merge with existing structured data so partial updates work.
			$existing                = DesignSystemPostType::get_structured_data( $post );
			$structured              = [
				'tokens' => isset( $body['tokens'] ) && is_array( $body['tokens'] )
					? array_map( 'sanitize_text_field', $body['tokens'] )
					: $existing['tokens'],
				'reset'  => isset( $body['reset'] )
					? DesignSystemPostType::sanitize_css( $body['reset'] )
					: $existing['reset'],
				'base'   => isset( $body['base'] )
					? DesignSystemPostType::sanitize_css( $body['base'] )
					: $existing['base'],
			];
			$updates['post_content'] = wp_slash( wp_json_encode( $structured ) );
		} elseif ( isset( $body['css'] ) ) {
			// Legacy: raw CSS → parse into structured.
			$structured              = DesignSystemPostType::resolve_structured_data( [ 'css' => $body['css'] ] );
			$updates['post_content'] = wp_slash( wp_json_encode( $structured ) );
		}

		if ( ! empty( $updates ) ) {
			$updates['ID'] = $post->ID;
			$result        = wp_update_post( $updates, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $body['js'] ) ) {
			update_post_meta( $post->ID, DesignSystemPostType::META_BASE_JS, DesignSystemPostType::sanitize_js( $body['js'] ) );
		}

		if ( isset( $body['fonts'] ) && is_array( $body['fonts'] ) ) {
			$safe_fonts = DesignSystemPostType::sanitize_font_entries( $body['fonts'] );
			update_post_meta( $post->ID, DesignSystemPostType::META_FONTS, wp_json_encode( $safe_fonts ) );
		}

		if ( isset( $body['guidance'] ) ) {
			update_post_meta( $post->ID, DesignSystemPostType::META_GUIDANCE, DesignSystemPostType::sanitize_guidance( $body['guidance'] ) );
		}

		if ( isset( $body['brief'] ) ) {
			update_post_meta( $post->ID, DesignSystemPostType::META_BRIEF, DesignSystemPostType::sanitize_guidance( $body['brief'] ) );
		}

		// Re-fetch to get updated data.
		$updated_post = get_post( $post->ID );

		return new \WP_REST_Response(
			DesignSystemPostType::format_for_response( $updated_post ),
			200
		);
	}

	/**
	 * Delete a design system by UUID.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_design_system( \WP_REST_Request $request ) {
		$uuid = $request->get_param( 'uuid' );
		$post = DesignSystemPostType::get_by_uuid( $uuid );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Design system not found.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		if ( ! WordPressAuth::can_edit_design_system( $post ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'You do not have permission to delete this design system.',
				[ 'status' => 403 ],
			);
		}

		// If this was the default, clear it.
		$default_uuid = DesignSystemPostType::get_default_uuid();
		if ( $default_uuid === $uuid ) {
			delete_option( DesignSystemPostType::OPTION_DEFAULT );
		}

		wp_delete_post( $post->ID, true );

		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Set the default design system.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_default( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$uuid = isset( $body['uuid'] ) ? sanitize_text_field( $body['uuid'] ) : null;

		if ( ! $uuid ) {
			return new \WP_Error(
				'validation_error',
				__( 'A uuid is required.', 'fl-design-system' ),
				[ 'status' => 422 ],
			);
		}

		// Validate the UUID exists.
		$post = DesignSystemPostType::get_by_uuid( $uuid );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Design system not found.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		DesignSystemPostType::set_default( $uuid );

		return new \WP_REST_Response(
			DesignSystemPostType::format_for_response( $post ),
			200
		);
	}

	/**
	 * Create a blank draft page linked to an existing design system.
	 *
	 * Mirrors the kit-driven blank-page response shape so the frontend can
	 * use a single navigation handler regardless of entry point.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_page( \WP_REST_Request $request ) {
		$uuid = (string) $request->get_param( 'uuid' );
		$post = DesignSystemPostType::get_by_uuid( $uuid );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Design system not found.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		$post_type = ( new PostTypeAccess() )->get_default_creatable_post_type();

		if ( null === $post_type ) {
			return new \WP_Error(
				'cannot_create_post_type',
				'You do not have permission to create any post type.',
				[ 'status' => 403 ],
			);
		}

		$editor = class_exists( 'FLBuilderModel' ) ? 'beaver-builder' : 'block-editor';

		$result = ( new BlankPageCreator() )->create( $post_type, $uuid, $editor );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'postId'       => $result['postId'],
				'postType'     => $post_type,
				'editUrl'      => $result['editUrl'],
				'designSystem' => [
					'uuid'  => $uuid,
					'label' => (string) $post->post_title,
				],
			],
			200
		);
	}

	/**
	 * List posts that reference a given design system.
	 *
	 * Mirrors the scoping rule on `list_design_systems`: non-editors can
	 * only enumerate posts inside design systems they authored. Within an
	 * accessible DS, the post counts/lists include posts by all authors,
	 * matching the `/design-system-usage` count endpoint.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_posts( \WP_REST_Request $request ) {
		$uuid = (string) $request->get_param( 'uuid' );
		$post = DesignSystemPostType::get_by_uuid( $uuid );

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				__( 'Design system not found.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		if ( ! current_user_can( 'edit_others_design_systems' )
			&& get_current_user_id() !== (int) $post->post_author
		) {
			return new \WP_Error(
				'rest_forbidden',
				'You do not have permission to view posts for this design system.',
				[ 'status' => 403 ],
			);
		}

		$post_type_param = $request->get_param( 'post_type' );
		$result          = $this->usage_query->find_posts_using( $uuid, [
			'page'      => (int) ( $request->get_param( 'page' ) ?? 1 ),
			'per_page'  => (int) ( $request->get_param( 'per_page' ) ?? 20 ),
			'post_type' => is_string( $post_type_param ) && '' !== $post_type_param ? $post_type_param : null,
		] );

		return new \WP_REST_Response( $result, 200 );
	}
}
