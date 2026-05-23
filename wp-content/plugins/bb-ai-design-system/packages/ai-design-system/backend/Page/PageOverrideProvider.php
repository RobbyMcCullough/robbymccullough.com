<?php

namespace FL\DesignSystem\Page;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;

/**
 * REST endpoints for page-level design system overrides.
 *
 * Stores a per-page DS reference in post meta so individual pages can
 * reference a shared design system.
 */
class PageOverrideProvider {

	public const DS_REF_META_KEY   = '_fl_ds_ref';
	public const PAGE_CSS_META_KEY = '_fl_ds_page_css';
	public const PAGE_JS_META_KEY  = '_fl_ds_page_js';

	public const STAGING_SOURCE_META_KEY = '_fl_ds_staging_source';
	public const STAGING_DRAFT_META_KEY  = '_fl_ds_staging_draft';
	public const STAGING_SOURCE_HASH_KEY = '_fl_ds_staging_source_hash';
	public const LAST_MCP_HASH_KEY       = '_fl_ds_last_mcp_hash';

	private AuthInterface $auth;

	/**
	 * @param AuthInterface $auth Auth adapter for permission checks.
	 */
	public function __construct( AuthInterface $auth ) {
		$this->auth = $auth;
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		$this->register_meta();
	}

	/**
	 * Register post meta so it is available via the REST API.
	 */
	private function register_meta() {
		$meta_keys = [
			self::DS_REF_META_KEY,
			self::PAGE_CSS_META_KEY,
			self::PAGE_JS_META_KEY,
		];

		foreach ( $meta_keys as $key ) {
			register_meta(
				'post',
				$key,
				[
					'single'       => true,
					'type'         => 'string',
					'default'      => '',
					'show_in_rest' => false,
				]
			);
		}
	}

	public function register_routes() {
		$namespace = 'fl-design-system/v1';

		register_rest_route(
			$namespace,
			'/page-overrides/(?P<post_id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_overrides' ],
					'permission_callback' => [ $this, 'read_overrides_permission_callback' ],
					'args'                => [
						'post_id' => [
							'required'          => true,
							'validate_callback' => [ $this, 'validate_post_id' ],
						],
					],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_overrides' ],
					'permission_callback' => [ $this, 'write_overrides_permission_callback' ],
					'args'                => [
						'post_id' => [
							'required'          => true,
							'validate_callback' => [ $this, 'validate_post_id' ],
						],
					],
				],
			]
		);
	}

	/**
	 * Permission callback for reading page overrides.
	 *
	 * Any logged-in user who can edit the target post can read its overrides,
	 * since DS assets already ship to the public frontend. Write access to
	 * overrides still requires `unfiltered_html` via content_creator.
	 *
	 * @param  \WP_REST_Request $request
	 * @return bool
	 */
	public function read_overrides_permission_callback( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Permission callback for writing page overrides.
	 *
	 * A `dsRef`-only write is a UUID pointer with no script-injection surface,
	 * so any user who can edit the target post may write it. The field-level
	 * `unfiltered_html` gate on `pageCss` / `pageJs` is enforced inside
	 * `update_overrides` — this keeps the dsRef-only path (used by pattern
	 * inheritance and the chat's DS-selection action) open to lower-cap roles
	 * while still blocking raw CSS/JS from those same users.
	 *
	 * @param  \WP_REST_Request $request
	 * @return bool
	 */
	public function write_overrides_permission_callback( \WP_REST_Request $request ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );

		return $post_id > 0 && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Validate that the post ID refers to an existing post the user can edit.
	 *
	 * @param  mixed $value   The post_id parameter value.
	 * @return bool|\WP_Error
	 */
	public function validate_post_id( $value ) {
		$post_id = absint( $value );

		if ( ! $post_id ) {
			return new \WP_Error(
				'invalid_post_id',
				__( 'Invalid post ID.', 'fl-design-system' ),
				[ 'status' => 400 ],
			);
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'forbidden',
				__( 'You do not have permission to edit this post.', 'fl-design-system' ),
				[ 'status' => 403 ],
			);
		}

		return true;
	}

	/**
	 * Return page-level overrides for a post.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_overrides( \WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$data    = self::get_page_override_data( $post_id );

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Update page-level overrides for a post.
	 *
	 * @param  \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_overrides( \WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$body    = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new \WP_Error(
				'validation_error',
				__( 'Request body must be a JSON object.', 'fl-design-system' ),
				[ 'status' => 422 ],
			);
		}

		// Raw CSS/JS writes require the content-creator gate. dsRef writes
		// stay open to any user who can edit the post so pattern inheritance
		// works for low-cap roles.
		$writes_raw_assets = array_key_exists( 'pageCss', $body ) || array_key_exists( 'pageJs', $body );

		if ( $writes_raw_assets && ! WordPressAuth::user_can_create_content() ) {
			return new \WP_Error(
				'missing_unfiltered_html',
				__( 'You do not have permission to write page CSS or JS.', 'fl-design-system' ),
				[ 'status' => 403 ],
			);
		}

		$save_data = [];

		// Update dsRef if provided.
		if ( array_key_exists( 'dsRef', $body ) ) {
			$save_data['dsRef'] = null === $body['dsRef']
				? null
				: sanitize_text_field( $body['dsRef'] );
		}

		// Update pageCss if provided.
		if ( array_key_exists( 'pageCss', $body ) ) {
			$save_data['pageCss'] = is_string( $body['pageCss'] )
				? DesignSystemPostType::sanitize_css( $body['pageCss'] )
				: '';
		}

		// Update pageJs if provided.
		if ( array_key_exists( 'pageJs', $body ) ) {
			$save_data['pageJs'] = is_string( $body['pageJs'] )
				? DesignSystemPostType::sanitize_js( $body['pageJs'] )
				: '';
		}

		if ( ! empty( $save_data ) ) {
			self::save_page_override_data( $post_id, $save_data );
		}

		$data = self::get_page_override_data( $post_id );

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Read page override data from post meta.
	 *
	 * @param  int $post_id Post ID.
	 * @return array { dsRef: string|null, pageCss: string|null, pageJs: string|null }
	 */
	public static function get_page_override_data( int $post_id ): array {
		$data = [
			'dsRef'   => null,
			'pageCss' => null,
			'pageJs'  => null,
		];

		$ds_ref = get_post_meta( $post_id, self::DS_REF_META_KEY, true );

		if ( ! empty( $ds_ref ) && is_string( $ds_ref ) ) {
			$data['dsRef'] = $ds_ref;
		}

		$page_css = get_post_meta( $post_id, self::PAGE_CSS_META_KEY, true );

		if ( ! empty( $page_css ) && is_string( $page_css ) ) {
			$data['pageCss'] = $page_css;
		}

		$page_js = get_post_meta( $post_id, self::PAGE_JS_META_KEY, true );

		if ( ! empty( $page_js ) && is_string( $page_js ) ) {
			$data['pageJs'] = $page_js;
		}

		return $data;
	}

	/**
	 * Save page override data to post meta.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Shape: { dsRef: string|null, pageCss?: string, pageJs?: string }
	 */
	public static function save_page_override_data( int $post_id, array $data ): void {
		if ( array_key_exists( 'dsRef', $data ) ) {
			$ds_ref = isset( $data['dsRef'] ) && is_string( $data['dsRef'] )
				? sanitize_text_field( $data['dsRef'] )
				: '';

			update_post_meta( $post_id, self::DS_REF_META_KEY, $ds_ref );
		}

		if ( array_key_exists( 'pageCss', $data ) ) {
			$css = is_string( $data['pageCss'] )
				? DesignSystemPostType::sanitize_css( $data['pageCss'] )
				: '';

			update_post_meta( $post_id, self::PAGE_CSS_META_KEY, $css );
		}

		if ( array_key_exists( 'pageJs', $data ) ) {
			$js = is_string( $data['pageJs'] )
				? DesignSystemPostType::sanitize_js( $data['pageJs'] )
				: '';

			update_post_meta( $post_id, self::PAGE_JS_META_KEY, $js );
		}
	}
}
