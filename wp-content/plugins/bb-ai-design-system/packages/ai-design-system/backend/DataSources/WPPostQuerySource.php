<?php

namespace FL\DesignSystem\DataSources;

use FL\DesignSystem\DataSources\DataSourceRegistry;

/**
 * WordPress post query data source.
 *
 * Provides repeater items from a WP_Query, mapping standard post
 * fields to a flat property array for field connections.
 */
class WPPostQuerySource {

	/**
	 * Register the wp-post-query data source in the registry.
	 */
	public static function register(): void {
		DataSourceRegistry::register( 'wp-post-query', [
			'label'    => 'WordPress Posts',
			'resolver' => [ self::class, 'resolve' ],
		] );
	}

	/**
	 * Resolve post query data into an array of items.
	 *
	 * @param  array $config  Query configuration (post_type, posts_per_page, orderby, order).
	 * @param  array $context Render context (e.g., ['post_id' => 123]).
	 * @return array Array of items, each mapping property keys to values.
	 */
	public static function resolve( array $config, array $context ): array {
		$args = [
			'post_type'           => $config['post_type'] ?? 'post',
			'posts_per_page'      => $config['posts_per_page'] ?? 3,
			'orderby'             => $config['orderby'] ?? 'date',
			'order'               => $config['order'] ?? 'DESC',
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
		];

		$query = new \WP_Query( $args );
		$items = [];

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post = get_post();

				$items[] = [
					'post_title'     => get_the_title( $post ),
					'post_excerpt'   => get_the_excerpt( $post ),
					'post_content'   => apply_filters( 'the_content', $post->post_content ),
					'featured_image' => self::get_featured_image( $post ),
					'permalink'      => get_permalink( $post ),
					'post_date'      => get_the_date( '', $post ),
				];
			}
			wp_reset_postdata();
		}

		return $items;
	}

	/**
	 * Get featured image data for a post.
	 *
	 * @param  \WP_Post $post The post object.
	 * @return array Image data with url, alt, and id keys.
	 */
	private static function get_featured_image( \WP_Post $post ): array {
		$thumb_id = get_post_thumbnail_id( $post );

		if ( ! $thumb_id ) {
			return [ 'url' => '', 'alt' => '', 'id' => 0 ];
		}

		$url = wp_get_attachment_image_url( $thumb_id, 'large' );
		$alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );

		return [
			'url' => $url ?: '',
			'alt' => $alt ?: '',
			'id'  => (int) $thumb_id,
		];
	}
}
