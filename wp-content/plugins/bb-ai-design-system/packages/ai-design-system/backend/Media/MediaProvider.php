<?php

namespace FL\DesignSystem\Media;

use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\Contracts\SettingsStoreInterface;

class MediaProvider {

	private SettingsStoreInterface $settings;
	private AuthInterface $auth;

	public function __construct( SettingsStoreInterface $settings, AuthInterface $auth ) {
		$this->settings = $settings;
		$this->auth     = $auth;
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route('fl-design-system/v1', '/media/unsplash/search', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'search_unsplash' ],
			'permission_callback' => [ $this->auth, 'content_creator_permission_callback' ],
		]);
	}

	/**
	 * Search Unsplash for images matching a query.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function search_unsplash( \WP_REST_Request $request ) {
		$api_key = $this->settings->get( 'media.unsplash_api_key' );

		if ( ! $api_key ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'No Unsplash API key configured. Add one in Design System settings.', 'fl-design-system' ),
				[ 'status' => 400 ],
			);
		}

		$body   = $request->get_json_params();
		$query  = isset( $body['query'] ) ? sanitize_text_field( $body['query'] ) : '';
		$width  = isset( $body['width'] ) ? absint( $body['width'] ) : 0;
		$height = isset( $body['height'] ) ? absint( $body['height'] ) : 0;

		if ( empty( $query ) ) {
			return new \WP_Error(
				'missing_query',
				__( 'A search query is required.', 'fl-design-system' ),
				[ 'status' => 400 ],
			);
		}

		$orientation = $this->infer_orientation( $width, $height );

		$url = add_query_arg( [
			'query'          => $query,
			'per_page'       => 3,
			'orientation'    => $orientation,
			'content_filter' => 'high',
		], 'https://api.unsplash.com/search/photos' );

		$response = wp_remote_get( $url, [
			'timeout' => 10,
			'headers' => [
				'Authorization' => 'Client-ID ' . $api_key,
			],
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'api_error',
				$response->get_error_message(),
				[ 'status' => 502 ],
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 400 ) {
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body );
			$error_message = isset( $decoded->errors[0] ) ? $decoded->errors[0] : 'Unsplash API error.';
			return new \WP_Error(
				'api_error',
				$error_message,
				[ 'status' => $status_code ],
			);
		}

		$response_body = wp_remote_retrieve_body( $response );
		$decoded       = json_decode( $response_body, true );
		$results       = isset( $decoded['results'] ) ? $decoded['results'] : [];

		if ( empty( $results ) ) {
			return new \WP_Error(
				'no_results',
				__( 'No images found for this query.', 'fl-design-system' ),
				[ 'status' => 404 ],
			);
		}

		$photo     = $results[0];
		$image_url = $photo['urls']['raw'] . '&w=' . $width . '&h=' . $height . '&fit=crop&auto=format&q=80';

		return new \WP_REST_Response( [
			'url'               => $image_url,
			'photographer'      => [
				'name' => $photo['user']['name'],
				'url'  => $photo['user']['links']['html'],
			],
			'download_location' => $photo['links']['download_location'],
		], 200 );
	}

	/**
	 * Infer image orientation from dimensions.
	 *
	 * @param  int    $width  Image width.
	 * @param  int    $height Image height.
	 * @return string One of 'landscape', 'portrait', or 'squarish'.
	 */
	private function infer_orientation( int $width, int $height ): string {
		if ( $width > $height * 1.2 ) {
			return 'landscape';
		}
		if ( $height > $width * 1.2 ) {
			return 'portrait';
		}
		return 'squarish';
	}
}
