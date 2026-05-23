<?php

namespace FL\DesignSystem\Admin;

use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;

/**
 * REST endpoint for design system usage counts across the site.
 */
class AdminRestController {

	private AuthInterface $auth;

	/**
	 * @param AuthInterface $auth Auth adapter for permission checks.
	 */
	public function __construct( AuthInterface $auth ) {
		$this->auth = $auth;
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route(
			'fl-design-system/v1',
			'/design-system-usage',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_usage' ],
				'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
			]
		);
	}

	/**
	 * Get usage counts for all design systems.
	 *
	 * Only counts posts with an explicit `_fl_ds_ref` meta assignment.
	 * Posts without this meta are unassigned — they happen to fall back
	 * to the site default at render time, but that's not an intentional
	 * assignment and shouldn't inflate the count.
	 *
	 * Includes all post statuses (publish, draft, pending, etc.) so
	 * authors can see the full picture before deleting a design system.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_usage() {
		global $wpdb;

		// Get design system UUIDs (scoped to own for non-editors).
		$ds_query = [
			'post_type'      => DesignSystemPostType::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		];

		if ( ! current_user_can( 'edit_others_design_systems' ) ) {
			$ds_query['author'] = get_current_user_id();
		}

		$ds_posts = get_posts( $ds_query );

		$usage = [];
		foreach ( $ds_posts as $ds_post ) {
			$uuid = get_post_meta( $ds_post->ID, DesignSystemPostType::META_UUID, true );
			if ( $uuid ) {
				$usage[ $uuid ] = [
					'total'          => 0,
					'by_type'        => [],
					'by_type_labels' => [],
				];
			}
		}

		// Count posts with explicit _fl_ds_ref meta (any post status).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$counts = $wpdb->get_results(
			"SELECT pm.meta_value AS ds_uuid, p.post_type, COUNT(*) AS cnt
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = '_fl_ds_ref'
				AND pm.meta_value != ''
				AND p.post_status != 'trash'
			GROUP BY pm.meta_value, p.post_type"
		);

		foreach ( $counts as $row ) {
			$uuid = $row->ds_uuid;
			if ( ! isset( $usage[ $uuid ] ) ) {
				continue;
			}
			$count = (int) $row->cnt;
			$usage[ $uuid ]['by_type'][ $row->post_type ]        = $count;
			$usage[ $uuid ]['by_type_labels'][ $row->post_type ] = $this->resolve_post_type_label( $row->post_type );
			$usage[ $uuid ]['total'] += $count;
		}

		return new \WP_REST_Response( $usage, 200 );
	}

	/**
	 * Resolve a human-readable plural label for a post type slug.
	 *
	 * Falls back to the slug when the post type isn't registered (e.g. a
	 * CPT plugin was deactivated but its postmeta rows linger).
	 *
	 * @param  string $slug Post type slug.
	 * @return string
	 */
	private function resolve_post_type_label( string $slug ): string {
		$obj = get_post_type_object( $slug );
		if ( $obj && isset( $obj->labels->name ) && '' !== (string) $obj->labels->name ) {
			return (string) $obj->labels->name;
		}
		return $slug;
	}
}
