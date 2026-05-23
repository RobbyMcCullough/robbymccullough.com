<?php

namespace FL\DesignSystem\Mcp\Abilities\DesignSystems;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Mcp\BaseAbility;

/**
 * MCP ability: list-design-systems.
 *
 * Surfaces design systems available to the current user. Truncates to the
 * default plus 9 others when more than 10 exist; agents can pass show_all
 * to retrieve everything.
 */
class ListDesignSystems extends BaseAbility {

	public function name(): string {
		return 'beaver-builder-ai/list-design-systems';
	}

	public function definition(): array {
		return [
			'label'               => 'List Design Systems',
			'description'         => 'Returns Beaver Builder AI design systems on this site. Call this before generating a page to check what design systems are available, or when the user wants to choose an existing one. Present the results and ask which one to use. When more than 10 exist, only the default and 9 others are returned by default. If truncated, ask the user if they would like to see more. Pass page (1-indexed, page size 10) to step through the rest, or show_all to retrieve every design system at once. Response shape differs by mode: the default and show_all responses include items, total_count, truncated; an explicit page response also includes page, page_size, has_more (the default is not pinned in page mode, so page 1 is the first slice of natural ordering).',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				// Top-level default for empty-parameters resilience -- mcp-adapter's
				// ExecuteAbilityAbility nullifies `parameters: {}` via empty(), and
				// WP_Ability::normalize_input() substitutes this default when input
				// arrives as null so type: object validation still passes.
				'default'    => [],
				'properties' => [
					'show_all' => [
						'type'        => 'boolean',
						'description' => 'Return all design systems without truncation. Cannot be combined with page.',
						'default'     => false,
					],
					'page'     => [
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => '1-indexed page number for paginated results (page size 10, ordered by post date as returned by WP_Query). Omit (or pass with show_all) for the default-pinned, truncated view; pass an explicit page to step through the rest. Cannot be combined with show_all.',
					],
				],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true ],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'design-systems',
				'summary'     => 'Lists available design systems on the site',
			],
		];
	}

	public function execute( array $input = [] ): array {
		$query_args = [
			'post_type'      => DesignSystemPostType::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		];

		if ( ! current_user_can( 'edit_others_design_systems' ) ) {
			$query_args['author'] = get_current_user_id();
		}

		$posts = get_posts( $query_args );

		if ( empty( $posts ) ) {
			return [];
		}

		$default_uuid = DesignSystemPostType::get_default_uuid();
		$items        = [];

		foreach ( $posts as $post ) {
			$uuid    = get_post_meta( $post->ID, DesignSystemPostType::META_UUID, true ) ?: '';
			$items[] = [
				'uuid'       => $uuid,
				'label'      => $post->post_title,
				'is_default' => $uuid === $default_uuid && '' !== $uuid,
			];
		}

		$show_all  = ! empty( $input['show_all'] );
		$page      = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : null;
		$max_items = 10;
		$total     = count( $items );

		// Explicit page beats default-pinning. We page through the natural sort
		// rather than re-pinning the default on every page, so subsequent pages
		// are a clean continuation of page 1's tail.
		if ( ! $show_all && null !== $page ) {
			$offset   = ( $page - 1 ) * $max_items;
			$slice    = array_slice( $items, $offset, $max_items );
			$returned = count( $slice );

			return [
				'items'       => array_values( $slice ),
				'total_count' => $total,
				'page'        => $page,
				'page_size'   => $max_items,
				'has_more'    => ( $offset + $returned ) < $total,
				'truncated'   => $total > $returned,
			];
		}

		if ( ! $show_all && $total > $max_items ) {
			$default = array_filter( $items, fn( $item ) => $item['is_default'] );
			$others  = array_filter( $items, fn( $item ) => ! $item['is_default'] );

			$truncated = array_merge(
				array_values( $default ),
				array_slice( array_values( $others ), 0, $max_items - count( $default ) )
			);

			return [
				'items'       => $truncated,
				'total_count' => $total,
				'truncated'   => true,
			];
		}

		return [
			'items'       => $items,
			'total_count' => $total,
			'truncated'   => false,
		];
	}
}
