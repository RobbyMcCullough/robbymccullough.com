<?php

namespace FL\DesignSystem\Mcp\Abilities\Discovery;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\PostTypeService;

/**
 * MCP ability: list-post-types.
 *
 * Returns WordPress post types available for content creation, with the
 * BB-enabled flag included as informational metadata. Agents call this
 * before generate-page so they can pick a valid post type.
 */
class ListPostTypes extends BaseAbility {

	private PostTypeService $post_type_service;

	public function __construct( PostTypeService $post_type_service ) {
		$this->post_type_service = $post_type_service;
	}

	public function name(): string {
		return 'beaver-builder-ai/list-post-types';
	}

	public function definition(): array {
		return [
			'label'               => 'List Post Types',
			'description'         => 'Returns WordPress post types available for content creation and editing. Call this to know which post types are available before generating or searching for pages. The response includes a bb_enabled flag indicating whether Beaver Builder is active for each post type -- this is informational only and does not affect which tools you can use.',
			'category'            => 'beaver-builder-ai',
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
				'summary'     => 'Returns available post types for content creation',
			],
		];
	}

	public function execute( array $input = [] ): array {
		$slugs      = $this->post_type_service->get_creatable_post_type_slugs();
		$bb_enabled = class_exists( 'FLBuilderModel' )
			? \FLBuilderModel::get_post_types()
			: [];

		$result = [];
		foreach ( $slugs as $slug ) {
			$obj      = get_post_type_object( $slug );
			$result[] = [
				'slug'       => $slug,
				'label'      => $obj->label,
				'bb_enabled' => in_array( $slug, $bb_enabled, true ),
			];
		}

		return [ 'post_types' => $result ];
	}
}
