<?php

namespace FL\DesignSystem\DesignSystem;

/**
 * Queries posts that reference a given design system.
 *
 * Owns the logic shared by the admin "Used By" tab today and any future
 * MCP/abilities integration that needs to enumerate posts by DS UUID.
 */
class DesignSystemUsageQuery {

	public const META_REF_KEY = '_fl_ds_ref';

	private const ALLOWED_STATUSES = [ 'publish', 'draft', 'pending', 'private', 'future' ];

	/**
	 * Find posts that reference the given design system UUID.
	 *
	 * @param  string $uuid Design system UUID.
	 * @param  array  $args {
	 *     @type int         $page      Page number (default 1).
	 *     @type int         $per_page  Per page (default 20, max 50).
	 *     @type string|null $post_type Filter to a single post type, or null for all.
	 * }
	 * @return array { items: array<int, array>, total: int, pages_total: int }
	 */
	public function find_posts_using( string $uuid, array $args = [] ): array {
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page  = max( 1, min( 50, (int) ( $args['per_page'] ?? 20 ) ) );
		$post_type = ! empty( $args['post_type'] ) ? (string) $args['post_type'] : null;

		$resolved_types = $post_type ? [ $post_type ] : $this->resolve_post_types( $uuid );

		if ( empty( $resolved_types ) ) {
			return [ 'items' => [], 'total' => 0, 'pages_total' => 0 ];
		}

		$query = new \WP_Query( [
			'post_type'      => $resolved_types,
			'post_status'    => self::ALLOWED_STATUSES,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'meta_query'     => [
				[
					'key'   => self::META_REF_KEY,
					'value' => $uuid,
				],
			],
		] );

		$items       = array_map( [ $this, 'format_post_row' ], $query->posts );
		$found_posts = (int) $query->found_posts;

		return [
			'items'       => $items,
			'total'       => $found_posts,
			'pages_total' => $found_posts > 0 ? (int) ceil( $found_posts / $per_page ) : 0,
		];
	}

	/**
	 * Resolve the explicit list of post types referencing the given UUID.
	 *
	 * `WP_Query`'s `post_type=any` silently drops types registered with
	 * `exclude_from_search=true` (notably `fl-builder-template`). We hit the
	 * postmeta table directly so the unfiltered list matches the chip totals.
	 *
	 * @param  string $uuid Design system UUID.
	 * @return string[] Distinct post type slugs.
	 */
	private function resolve_post_types( string $uuid ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.post_type
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
				 AND pm.meta_value = %s
				 AND p.post_status != 'trash'",
			self::META_REF_KEY,
			$uuid
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( $sql );

		if ( ! is_array( $results ) ) {
			return [];
		}

		return array_values( array_filter( array_map(
			static fn( $row ) => isset( $row->post_type ) ? (string) $row->post_type : '',
			$results
		) ) );
	}

	/**
	 * Convert a WP_Post into the response row shape.
	 *
	 * @param  \WP_Post $post Post.
	 * @return array
	 */
	private function format_post_row( \WP_Post $post ): array {
		$type_obj = get_post_type_object( $post->post_type );
		$label    = $type_obj && isset( $type_obj->labels->singular_name )
			? (string) $type_obj->labels->singular_name
			: $post->post_type;

		// Patterns (`wp_block`) and templates render through their host
		// post; their own permalinks are dead ends. Suppress view_url
		// on any post type WordPress treats as non-viewable.
		$view_url   = is_post_type_viewable( $type_obj ) ? get_permalink( $post ) : null;
		$edit_url   = get_edit_post_link( $post->ID, 'raw' );
		$bb_enabled = $this->is_bb_enabled( $post->ID );

		// Send the user straight into Beaver Builder when BB is enabled
		// for this post — skips the WP edit screen they'd otherwise have
		// to click "Launch Beaver Builder" from. Gated on edit_url so a
		// non-editor never gets a working link.
		if ( $edit_url && $bb_enabled ) {
			$edit_url = (string) \FLBuilderModel::get_edit_url( $post->ID );
		}

		return [
			'id'              => (int) $post->ID,
			'title'           => get_the_title( $post ),
			'post_type'       => $post->post_type,
			'post_type_label' => $label,
			'status'          => $post->post_status,
			'modified'        => mysql_to_rfc3339( $post->post_modified_gmt ),
			'edit_url'        => $edit_url,
			'view_url'        => $view_url,
			'bb_enabled'      => $bb_enabled,
		];
	}

	/**
	 * Whether Beaver Builder is enabled for the given post.
	 */
	private function is_bb_enabled( int $post_id ): bool {
		return class_exists( '\FLBuilderModel' )
			&& (bool) \FLBuilderModel::is_builder_enabled( $post_id );
	}
}
