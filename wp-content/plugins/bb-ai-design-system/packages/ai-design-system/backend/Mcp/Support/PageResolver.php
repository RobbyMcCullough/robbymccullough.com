<?php

namespace FL\DesignSystem\Mcp\Support;

use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\Services\AdapterResolver;

/**
 * Resolve posts and the right editor adapter for them.
 *
 * Wraps {@see AdapterResolver} for ability use, and owns the
 * URL-to-post lookup and the viewable-URL helper that pages-related
 * abilities reach for. Lives on its own (rather than on BaseAbility)
 * so service classes like PageGenerator can use it without inheriting
 * the ability base.
 */
class PageResolver {

	private AdapterResolver $adapter_resolver;

	public function __construct( AdapterResolver $adapter_resolver ) {
		$this->adapter_resolver = $adapter_resolver;
	}

	/**
	 * Resolve the editor adapter for a post (or a new post of a type).
	 */
	public function resolve_adapter( ?int $post_id = null, ?string $post_type = null ): PageEditorAdapterInterface {
		return $this->adapter_resolver->for_post( $post_id, $post_type );
	}

	/**
	 * Resolve a URL to a WordPress post ID.
	 *
	 * Tries url_to_postid() first (works for published posts with pretty
	 * permalinks), then falls back to parsing query parameters for
	 * draft/preview URLs.
	 *
	 * @return int Post ID, or 0 if unresolvable.
	 */
	public function resolve_url_to_post_id( string $url ): int {
		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			return $post_id;
		}

		$parsed = wp_parse_url( $url );
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $params );
			foreach ( [ 'p', 'page_id', 'post' ] as $key ) {
				if ( ! empty( $params[ $key ] ) ) {
					return absint( $params[ $key ] );
				}
			}
		}

		return 0;
	}

	/**
	 * Get the viewable URL for a post.
	 *
	 * Returns the preview URL for non-published posts (drafts, pending,
	 * etc.) since they are not publicly accessible at their permalink.
	 */
	public function get_post_url( int $post_id ): string {
		$post = get_post( $post_id );
		if ( $post && 'publish' !== $post->post_status ) {
			return get_preview_post_link( $post_id );
		}
		return get_permalink( $post_id );
	}
}
