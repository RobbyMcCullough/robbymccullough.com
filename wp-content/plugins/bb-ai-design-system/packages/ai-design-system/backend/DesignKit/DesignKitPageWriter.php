<?php

namespace FL\DesignSystem\DesignKit;

use FL\DesignSystem\Services\AdapterResolver;
use FL\DesignSystem\Page\PageExporter;

/**
 * Writes WordPress posts as kit-format HTML files into a Design Kit directory.
 *
 * Each post is rendered through {@see PageExporter} in kit format and written
 * to `pages/{slug}.html` (or `pages/index.html` for the WordPress front page).
 */
class DesignKitPageWriter {

	private AdapterResolver $adapter_resolver;

	public function __construct( AdapterResolver $adapter_resolver ) {
		$this->adapter_resolver = $adapter_resolver;
	}

	/**
	 * Render and write one or more posts into the kit's pages/ directory.
	 *
	 * @param string $kit_dir  Kit root directory (with trailing slash).
	 * @param int[]  $post_ids Post IDs to export.
	 * @return true|\WP_Error True on success.
	 */
	public function write( string $kit_dir, array $post_ids ) {
		if ( empty( $post_ids ) ) {
			return true;
		}

		$pages_dir = \trailingslashit( $kit_dir ) . 'pages/';
		\wp_mkdir_p( $pages_dir );

		$front_page_id  = (int) get_option( 'page_on_front' );
		$used_filenames = [];

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! $post_id ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post ) {
				return new \WP_Error(
					'page_not_found',
					"Post {$post_id} not found.",
					[ 'status' => 404 ]
				);
			}

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new \WP_Error(
					'page_forbidden',
					"You do not have permission to read post {$post_id}.",
					[ 'status' => 403 ]
				);
			}

			$adapter = $this->adapter_resolver->for_post( $post_id );
			$export  = PageExporter::export( $post_id, $adapter, [
				'format' => PageExporter::FORMAT_KIT,
			] );

			$filename                    = $this->resolve_filename( $post, $front_page_id, $used_filenames );
			$used_filenames[ $filename ] = true;

			file_put_contents( $pages_dir . $filename, $export['html'] );
		}

		return true;
	}

	/**
	 * Pick a filename for an exported page.
	 *
	 * The WordPress front page becomes index.html (per the kit format spec).
	 * Other pages use their post slug. Collisions are disambiguated with a
	 * numeric suffix; an empty slug falls back to "page-{id}".
	 *
	 * @param \WP_Post           $post           Post being exported.
	 * @param int                $front_page_id  WordPress front page ID, or 0.
	 * @param array<string,bool> $used_filenames Filenames already written this run.
	 * @return string Filename including the .html extension.
	 */
	private function resolve_filename( \WP_Post $post, int $front_page_id, array $used_filenames ): string {
		if ( $front_page_id && $front_page_id === (int) $post->ID && ! isset( $used_filenames['index.html'] ) ) {
			return 'index.html';
		}

		$slug = $post->post_name;
		if ( '' === $slug ) {
			$slug = sanitize_title( $post->post_title );
		}
		if ( '' === $slug ) {
			$slug = 'page-' . $post->ID;
		}

		$filename = $slug . '.html';
		if ( ! isset( $used_filenames[ $filename ] ) ) {
			return $filename;
		}

		$i = 2;
		while ( isset( $used_filenames[ $slug . '-' . $i . '.html' ] ) ) {
			$i++;
		}
		return $slug . '-' . $i . '.html';
	}
}
