<?php

namespace FL\DesignSystem\Mcp\Abilities\Discovery;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * MCP ability: list-pages.
 *
 * Search/filter for pages and posts the current user can edit, plus a
 * URL-resolution path that maps a known URL back to its post.
 *
 * The two ability-private helpers (`format_page_result` and
 * `get_editable_post_types`) live here because they have no other
 * consumers — keeping them on the ability rather than on a service.
 */
class ListPages extends BaseAbility {

	private PageResolver $page_resolver;

	public function __construct( PageResolver $page_resolver ) {
		$this->page_resolver = $page_resolver;
	}

	public function name(): string {
		return 'beaver-builder-ai/list-pages';
	}

	public function definition(): array {
		return [
			'label'               => 'List Pages',
			'description'         => 'Find existing pages and posts on the site. Call this when the user wants to edit, update, or review existing content -- or whenever they mention a page by name or URL. Returns pages the current user can edit, with design system status. Use the results to call get-page-html for reading content or update-page-html for making changes.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'default'    => [],
				'properties' => [
					'search'    => [
						'type'        => 'string',
						'description' => 'Search by title.',
					],
					'url'       => [
						'type'        => 'string',
						'description' => 'Resolve a specific page URL to find its post.',
					],
					'post_type' => [
						'type'        => 'string',
						'description' => 'Filter by post type slug.',
					],
					'status'    => [
						'type'        => 'string',
						'description' => 'Filter by post status (e.g., draft, publish).',
						'enum'        => [ 'draft', 'publish', 'pending', 'private' ],
					],
					'per_page'  => [
						'type'        => 'integer',
						'description' => 'Results per page. Default: 20. Max: 50.',
					],
					'page'      => [
						'type'        => 'integer',
						'description' => 'Page number for pagination. Default: 1.',
					],
				],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'discovery',
				'summary'     => 'Searches for existing pages and posts',
			],
		];
	}

	public function execute( array $input = [] ) {
		// URL resolution path — resolve a single URL to its post.
		if ( ! empty( $input['url'] ) ) {
			$post_id = $this->page_resolver->resolve_url_to_post_id( $input['url'] );
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				return [
					'pages' => [],
					'total' => 0,
				];
			}
			$post = get_post( $post_id );
			if ( ! $post ) {
				return [
					'pages' => [],
					'total' => 0,
				];
			}
			return [
				'pages' => [ $this->format_page_result( $post ) ],
				'total' => 1,
			];
		}

		// Search/filter path using WP_Query.
		$per_page = min( (int) ( $input['per_page'] ?? 20 ), 50 );
		$page     = max( (int) ( $input['page'] ?? 1 ), 1 );

		$args = [
			'post_status'    => $input['status'] ?? [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'perm'           => 'editable',
		];

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		if ( ! empty( $input['post_type'] ) ) {
			$type_obj = get_post_type_object( $input['post_type'] );
			if ( ! $type_obj ) {
				return new \WP_Error( 'invalid_post_type', 'Post type does not exist.', [ 'status' => 400 ] );
			}
			$args['post_type'] = $input['post_type'];
		} else {
			$args['post_type'] = $this->get_editable_post_types();
		}

		$query = new \WP_Query( $args );

		return [
			'pages'       => array_map( [ $this, 'format_page_result' ], $query->posts ),
			'total'       => (int) $query->found_posts,
			'pages_total' => (int) $query->max_num_pages,
		];
	}

	/**
	 * Format a WP_Post for the response.
	 */
	private function format_page_result( \WP_Post $post ): array {
		return [
			'post_id'           => $post->ID,
			'title'             => $post->post_title,
			'url'               => $this->page_resolver->get_post_url( $post->ID ),
			'post_type'         => $post->post_type,
			'status'            => $post->post_status,
			'modified'          => $post->post_modified,
			'has_design_system' => (bool) get_post_meta( $post->ID, PageOverrideProvider::DS_REF_META_KEY, true ),
		];
	}

	/**
	 * Post type slugs the current user can edit.
	 */
	private function get_editable_post_types(): array {
		$types    = get_post_types( [ 'public' => true ], 'objects' );
		$editable = [];
		foreach ( $types as $type ) {
			if ( current_user_can( $type->cap->edit_posts ) ) {
				$editable[] = $type->name;
			}
		}
		return $editable;
	}
}
