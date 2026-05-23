<?php

namespace FL\DesignSystem\DesignKit;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Page\BlankPageCreator;
use FL\DesignSystem\Services\PostTypeAccess;

/**
 * Creates a draft page from a bundled design kit template.
 *
 * Wraps KitImporter for the "from a bundled kit" case so the user's
 * single action creates (or links) the DS and the page in one call.
 *
 * Dedup: if a DS already exists with the kit's UUID, we link the page
 * to it; otherwise we create a new DS from the kit's contents. The kit
 * is never mutated and the DS is a snapshot (no live sync).
 */
class TemplatePageController {

	private AuthInterface $auth;

	private BuiltInKitRegistry $registry;

	/**
	 * @param AuthInterface      $auth     Auth adapter.
	 * @param BuiltInKitRegistry $registry Registry of bundled kits.
	 */
	public function __construct( AuthInterface $auth, BuiltInKitRegistry $registry ) {
		$this->auth     = $auth;
		$this->registry = $registry;
	}

	/**
	 * Register REST routes on rest_api_init.
	 */
	public function boot(): void {
		\add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the page creation route.
	 */
	public function register_routes(): void {
		\register_rest_route(
			'fl-design-system/v1',
			'/design-kits/(?P<id>[a-z0-9][a-z0-9\-]*)/pages',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_page' ],
				'permission_callback' => [ $this, 'permission_callback' ],
				'args'                => [
					'id'     => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'pageId' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'blank'  => [
						'type' => 'boolean',
					],
				],
			]
		);
	}

	/**
	 * Route permission: any logged-in user who can author design-system
	 * content (aligned with the rest of the design-kit REST surface).
	 *
	 * The handler resolves the specific post type to create via
	 * PostTypeAccess and returns 403 if no writable type is available.
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return \is_user_logged_in() && WordPressAuth::user_can_create_content();
	}

	/**
	 * Create a page from a template kit.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_page( \WP_REST_Request $request ) {
		$kit = $this->registry->get( (string) $request->get_param( 'id' ) );

		if ( null === $kit ) {
			return new \WP_Error( 'unknown_kit', 'Unknown design kit id.', [ 'status' => 404 ] );
		}

		$page_id = trim( (string) $request->get_param( 'pageId' ) );
		$blank   = (bool) $request->get_param( 'blank' );

		if ( '' !== $page_id && $blank ) {
			return new \WP_Error(
				'invalid_payload',
				'pageId and blank are mutually exclusive.',
				[ 'status' => 422 ]
			);
		}

		if ( '' === $page_id && ! $blank ) {
			return new \WP_Error(
				'invalid_payload',
				'Either pageId or blank must be provided.',
				[ 'status' => 422 ]
			);
		}

		$post_type = ( new PostTypeAccess() )->get_default_creatable_post_type();

		if ( null === $post_type ) {
			return new \WP_Error(
				'cannot_create_post_type',
				'You do not have permission to create any post type.',
				[ 'status' => 403 ]
			);
		}

		$kit_dir   = (string) $kit['directory_path'];
		$kit_json  = $this->read_kit_identity( $kit_dir );
		$kit_uuid  = $kit_json['uuid'];
		$kit_label = '' !== $kit_json['name'] ? $kit_json['name'] : (string) $kit['name'];

		if ( '' === $kit_uuid ) {
			return new \WP_Error(
				'invalid_kit',
				'This kit is missing a uuid in kit.json.',
				[ 'status' => 500 ]
			);
		}

		$ds_result = $this->resolve_design_system( $kit_dir, $kit_uuid, $kit_label );

		if ( \is_wp_error( $ds_result ) ) {
			return $ds_result;
		}

		$ds_uuid    = $ds_result['uuid'];
		$ds_created = $ds_result['created'];

		// On first DS for the site, set it as default (cosmetic, drives list
		// ordering). Gated on `edit_others_design_systems` so a non-privileged
		// user creating their first DS doesn't silently set a site-wide default
		// on behalf of admins.
		if (
			$ds_created
			&& null === DesignSystemPostType::get_default_uuid()
			&& \current_user_can( 'edit_others_design_systems' )
		) {
			DesignSystemPostType::set_default( $ds_uuid );
		}

		$editor = class_exists( 'FLBuilderModel' ) ? 'beaver-builder' : 'block-editor';

		$page_result = $blank
			? $this->create_blank_page( $post_type, $ds_uuid, $editor )
			: $this->create_page_from_template( $kit_dir, $page_id, $post_type, $ds_uuid, $editor, $kit_label );

		if ( \is_wp_error( $page_result ) ) {
			return $page_result;
		}

		return new \WP_REST_Response(
			[
				'postId'       => $page_result['postId'],
				'postType'     => $post_type,
				'editUrl'      => $page_result['editUrl'],
				'designSystem' => [
					'uuid'    => $ds_uuid,
					'created' => $ds_created,
					'label'   => $kit_label,
				],
			],
			200
		);
	}

	/**
	 * Ensure a DS exists for this kit. Link if the kit UUID already has a DS,
	 * otherwise create a fresh DS by running the existing kit import pipeline
	 * with no pages or globals.
	 *
	 * @param string $kit_dir   Resolved kit directory path.
	 * @param string $kit_uuid  UUID from kit.json.
	 * @param string $kit_label Label to assign to a newly-created DS.
	 * @return array{ uuid: string, created: bool }|\WP_Error
	 */
	private function resolve_design_system( string $kit_dir, string $kit_uuid, string $kit_label ) {
		$existing = DesignSystemPostType::get_by_uuid( $kit_uuid );

		if ( $existing ) {
			return [
				'uuid'    => $kit_uuid,
				'created' => false,
			];
		}

		$result = KitImporter::import(
			$kit_dir,
			[
				'designSystem' => [
					'action'  => 'create',
					'kitUuid' => $kit_uuid,
					'label'   => '' !== $kit_label ? $kit_label : 'Imported Design System',
				],
				'pages'        => [],
				'globals'      => [],
			]
		);

		$uuid = $result['designSystem']['uuid'] ?? '';

		if ( '' === $uuid ) {
			$reason = ! empty( $result['errors'] ) ? implode( '; ', $result['errors'] ) : 'unknown error';
			return new \WP_Error(
				'ds_create_failed',
				'Failed to create design system from kit: ' . $reason,
				[ 'status' => 500 ]
			);
		}

		return [
			'uuid'    => $uuid,
			'created' => true,
		];
	}

	/**
	 * Create a draft page with the template page's content imported into it.
	 *
	 * @param string $kit_dir   Kit root.
	 * @param string $page_id   Page slug (from the kit's pages/*.html).
	 * @param string $post_type Target post type.
	 * @param string $ds_uuid   DS UUID to attach to the page.
	 * @param string $editor    Editor identifier (beaver-builder | block-editor).
	 * @param string $kit_label Kit display label for fallback titles.
	 * @return array{ postId: int, editUrl: string }|\WP_Error
	 */
	private function create_page_from_template( string $kit_dir, string $page_id, string $post_type, string $ds_uuid, string $editor, string $kit_label ) {
		$page_info = $this->locate_page_file( $kit_dir, $page_id );

		if ( \is_wp_error( $page_info ) ) {
			return $page_info;
		}

		$html  = (string) file_get_contents( $page_info['path'] );
		$title = self::extract_title( $html );

		if ( '' === $title ) {
			$title = ucfirst( str_replace( [ '-', '_' ], ' ', $page_id ) );
		}

		$import_result = KitImporter::import(
			$kit_dir,
			[
				'designSystem' => [
					'action'       => 'use_existing',
					'existingUuid' => $ds_uuid,
				],
				'editor'       => $editor,
				'pages'        => [
					[
						'import'   => true,
						'slug'     => $page_id,
						'title'    => $title,
						'file'     => $page_info['file'],
						'postType' => $post_type,
					],
				],
				'globals'      => [],
			]
		);

		$pages = $import_result['pages'] ?? [];
		$page  = $pages[0] ?? null;

		if ( null === $page || 'imported' !== ( $page['status'] ?? '' ) || empty( $page['postId'] ) ) {
			$reason = implode( '; ', $import_result['errors'] ?? [] );
			return new \WP_Error(
				'page_create_failed',
				'Failed to create page from template' . ( '' !== $reason ? ': ' . $reason : '.' ),
				[
					'status'    => 500,
					'kit_label' => $kit_label,
				]
			);
		}

		return [
			'postId'  => (int) $page['postId'],
			'editUrl' => (string) ( $page['editUrl'] ?? '' ),
		];
	}

	/**
	 * Create a blank draft page linked to the kit's DS.
	 *
	 * Delegates to the shared {@see BlankPageCreator} so the kit-driven and
	 * DS-driven endpoints stay byte-identical.
	 *
	 * @param string $post_type Target post type.
	 * @param string $ds_uuid   DS UUID to attach.
	 * @param string $editor    Editor identifier.
	 * @return array{ postId: int, editUrl: string }|\WP_Error
	 */
	private function create_blank_page( string $post_type, string $ds_uuid, string $editor ) {
		return ( new BlankPageCreator() )->create( $post_type, $ds_uuid, $editor );
	}

	/**
	 * Resolve a page slug to its HTML file path inside the kit.
	 *
	 * Checks pages/{slug}.html first, then falls back to {slug}.html at the
	 * kit root. Rejects attempted path traversal.
	 *
	 * @param string $kit_dir Kit root directory.
	 * @param string $page_id Page slug.
	 * @return array{ path: string, file: string }|\WP_Error
	 */
	private function locate_page_file( string $kit_dir, string $page_id ) {
		if ( '' === $page_id || str_contains( $page_id, '/' ) || str_contains( $page_id, '..' ) ) {
			return new \WP_Error( 'invalid_page_id', 'Invalid page id.', [ 'status' => 422 ] );
		}

		$candidates = [
			'pages/' . $page_id . '.html',
			$page_id . '.html',
		];

		$real_root = realpath( $kit_dir );
		if ( false === $real_root ) {
			return new \WP_Error( 'kit_missing', 'Kit directory is not readable.', [ 'status' => 500 ] );
		}

		foreach ( $candidates as $relative ) {
			$abs  = \trailingslashit( $kit_dir ) . $relative;
			$real = realpath( $abs );

			if ( false !== $real && str_starts_with( $real, $real_root ) && file_exists( $real ) ) {
				return [
					'path' => $real,
					'file' => $relative,
				];
			}
		}

		return new \WP_Error(
			'unknown_page',
			'Unknown template page: ' . $page_id,
			[ 'status' => 404 ]
		);
	}

	/**
	 * Read uuid/name from kit.json.
	 *
	 * @param string $kit_dir Kit root directory.
	 * @return array{ uuid: string, name: string }
	 */
	private function read_kit_identity( string $kit_dir ): array {
		$defaults = [
			'uuid' => '',
			'name' => '',
		];

		$path = \trailingslashit( $kit_dir ) . 'kit.json';
		if ( ! file_exists( $path ) ) {
			return $defaults;
		}

		$json = file_get_contents( $path );
		$data = is_string( $json ) ? json_decode( $json, true ) : null;

		if ( ! is_array( $data ) ) {
			return $defaults;
		}

		return [
			'uuid' => isset( $data['uuid'] ) ? \sanitize_text_field( $data['uuid'] ) : '',
			'name' => isset( $data['name'] ) ? \sanitize_text_field( $data['name'] ) : '',
		];
	}

	/**
	 * Extract a `<title>` tag value from HTML.
	 *
	 * @param string $html Document HTML.
	 * @return string
	 */
	private static function extract_title( string $html ): string {
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}
}
