<?php

namespace FL\DesignSystem\Generation;

/**
 * Debug data storage and viewer for page generation.
 *
 * Stores the raw LLM output and brief as post meta so the team can
 * inspect what the LLM produced vs. what the parsers imported.
 *
 * Gated behind WP_DEBUG — only registers routes and the viewer when active.
 */
class DebugProvider {

	public const META_RAW_HTML   = '_fl_ds_debug_raw_html';
	public const META_BRIEF      = '_fl_ds_debug_brief';
	public const META_DEBUG_META = '_fl_ds_debug_meta';

	public function boot() {
		$this->register_meta();

		if ( ! $this->is_debug_enabled() ) {
			return;
		}

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'template_redirect', [ $this, 'render_debug_page' ] );
	}

	/**
	 * Register post meta keys for debug data.
	 */
	private function register_meta() {
		$meta_keys = [
			self::META_RAW_HTML,
			self::META_BRIEF,
			self::META_DEBUG_META,
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
		register_rest_route(
			'fl-design-system/v1',
			'/debug/(?P<post_id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'store_debug' ],
				'permission_callback' => function ( \WP_REST_Request $request ) {
					$post_id = absint( $request['post_id'] );
					return $post_id && current_user_can( 'edit_post', $post_id );
				},
				'args'                => [
					'post_id' => [
						'required'          => true,
						'validate_callback' => function ( $value ) {
							$post_id = absint( $value );
							if ( ! $post_id || ! get_post( $post_id ) ) {
								return new \WP_Error(
									'invalid_post_id',
									'Post not found.',
									[ 'status' => 404 ]
								);
							}
							return true;
						},
					],
				],
			]
		);
	}

	/**
	 * Store debug data as post meta.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function store_debug( \WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$body    = $request->get_json_params();

		$raw_html = $body['raw_html'] ?? '';
		$brief    = $body['brief'] ?? '';
		$meta     = $body['meta'] ?? [];

		update_post_meta( $post_id, self::META_RAW_HTML, $raw_html );
		update_post_meta( $post_id, self::META_BRIEF, $brief );
		update_post_meta( $post_id, self::META_DEBUG_META, wp_json_encode( $meta ) );

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Read debug data from post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array { raw_html: string, brief: string, meta: array }
	 */
	public static function get_debug( int $post_id ): array {
		$raw_html = get_post_meta( $post_id, self::META_RAW_HTML, true );
		$brief    = get_post_meta( $post_id, self::META_BRIEF, true );
		$meta_raw = get_post_meta( $post_id, self::META_DEBUG_META, true );
		$meta     = $meta_raw ? json_decode( $meta_raw, true ) : [];

		return [
			'raw_html' => ! empty( $raw_html ) ? $raw_html : '',
			'brief'    => ! empty( $brief ) ? $brief : '',
			'meta'     => is_array( $meta ) ? $meta : [],
		];
	}

	/**
	 * Render the debug viewer page via template_redirect.
	 *
	 * URL patterns:
	 *   ?bb_ds_debug=1&p={id}   — full viewer (brief panel + rendered HTML iframe)
	 *   ?bb_ds_debug=raw&p={id} — raw HTML only (for iframe src or direct viewing)
	 */
	public function render_debug_page() {
		if ( ! isset( $_GET['bb_ds_debug'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to view this page.', 'Forbidden', [ 'response' => 403 ] );
		}

		// Accept post ID from ?p= param or from the current queried post (pretty permalinks).
		$post_id = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : get_queried_object_id();
		$mode    = sanitize_text_field( $_GET['bb_ds_debug'] );

		if ( ! $post_id ) {
			wp_die( 'No post ID found. Use ?bb_ds_debug=1&p={id} or append to a page URL.', 'Missing Post', [ 'response' => 400 ] );
		}
		$debug   = self::get_debug( $post_id );

		if ( empty( $debug['raw_html'] ) ) {
			wp_die( 'No debug data found for this page.', 'Not Found', [ 'response' => 404 ] );
		}

		if ( 'raw' === $mode ) {
			$this->render_raw_html( $debug['raw_html'] );
		}

		if ( '1' === $mode ) {
			$this->render_debug_viewer( $post_id, $debug );
		}
	}

	/**
	 * Output just the raw HTML document.
	 */
	private function render_raw_html( string $html ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- intentionally rendering raw LLM output for debug inspection
		exit;
	}

	/**
	 * Render the debug viewer shell with brief panel and HTML iframe.
	 */
	private function render_debug_viewer( int $post_id, array $debug ) {
		$brief      = esc_html( $debug['brief'] );
		$meta       = $debug['meta'];
		$timestamp  = isset( $meta['timestamp'] ) ? esc_html( $meta['timestamp'] ) : 'N/A';
		$ds_uuid    = isset( $meta['design_system_uuid'] ) ? esc_html( $meta['design_system_uuid'] ) : 'None';
		$raw_url    = esc_url( add_query_arg( 'bb_ds_debug', 'raw', get_permalink( $post_id ) ) );
		$post_title = esc_html( get_the_title( $post_id ) );

		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		echo <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Debug: {$post_title} (#{$post_id})</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1e1e1e; color: #e0e0e0; }
.debug-header { position: fixed; top: 0; left: 0; right: 0; z-index: 100; background: #2d2d2d; border-bottom: 1px solid #444; padding: 16px 24px; overflow-y: auto; max-height: 40vh; }
.debug-header h1 { font-size: 14px; font-weight: 600; color: #fff; margin-bottom: 12px; }
.debug-meta { display: flex; gap: 24px; margin-bottom: 12px; font-size: 12px; color: #999; }
.debug-meta span { display: inline-flex; align-items: center; gap: 4px; }
.debug-meta strong { color: #ccc; font-weight: 500; }
.debug-brief { background: #1e1e1e; border: 1px solid #444; border-radius: 6px; padding: 12px 16px; font-size: 13px; line-height: 1.6; white-space: pre-wrap; word-wrap: break-word; color: #d4d4d4; max-height: 200px; overflow-y: auto; }
.debug-brief-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 6px; }
.debug-actions { margin-top: 12px; display: flex; gap: 8px; }
.debug-actions a { font-size: 12px; color: #6ea8fe; text-decoration: none; padding: 4px 10px; border: 1px solid #444; border-radius: 4px; }
.debug-actions a:hover { background: #333; }
.debug-iframe { position: fixed; top: 0; left: 0; right: 0; bottom: 0; border: none; width: 100%; height: 100%; }
.header-visible .debug-iframe { top: var(--header-height, 200px); height: calc(100vh - var(--header-height, 200px)); }
.toggle-header { position: fixed; top: 8px; right: 8px; z-index: 200; background: #444; color: #fff; border: none; padding: 4px 10px; border-radius: 4px; font-size: 12px; cursor: pointer; }
.toggle-header:hover { background: #555; }
</style>
</head>
<body class="header-visible">
<button class="toggle-header" onclick="document.body.classList.toggle('header-visible'); updateIframePosition()">Toggle Brief</button>
<div class="debug-header" id="debugHeader">
	<h1>Generation Debug: {$post_title} (#{$post_id})</h1>
	<div class="debug-meta">
		<span><strong>Generated:</strong> {$timestamp}</span>
		<span><strong>Design System:</strong> {$ds_uuid}</span>
	</div>
	<div class="debug-brief-label">Brief</div>
	<div class="debug-brief">{$brief}</div>
	<div class="debug-actions">
		<a href="{$raw_url}" target="_blank">Open raw HTML</a>
		<a href="#" onclick="copyRawUrl(); return false;" id="copyBtn">Copy raw URL</a>
	</div>
</div>
<iframe class="debug-iframe" id="debugIframe" src="{$raw_url}"></iframe>
<script>
function updateIframePosition() {
	const header = document.getElementById('debugHeader');
	const visible = document.body.classList.contains('header-visible');
	if (visible) {
		requestAnimationFrame(() => {
			document.body.style.setProperty('--header-height', header.offsetHeight + 'px');
		});
	}
}
function copyRawUrl() {
	navigator.clipboard.writeText("{$raw_url}").then(() => {
		const btn = document.getElementById('copyBtn');
		btn.textContent = 'Copied!';
		setTimeout(() => btn.textContent = 'Copy raw URL', 2000);
	});
}
updateIframePosition();
window.addEventListener('resize', updateIframePosition);
</script>
</body>
</html>
HTML;
		exit;
	}

	/**
	 * @return bool
	 */
	/**
	 * Check if debug is enabled via FL_DS_DEBUG or WP_DEBUG.
	 */
	private function is_debug_enabled(): bool {
		if ( defined( 'FL_DS_DEBUG' ) && FL_DS_DEBUG ) {
			return true;
		}
		return defined( 'WP_DEBUG' ) && WP_DEBUG;
	}
}
