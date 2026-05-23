<?php

namespace FL\DesignSystem\Adapters\WordPress;

use FL\DesignSystem\Contracts\HttpClientInterface;

/**
 * WordPress wp_remote_request() wrapper.
 */
class WordPressHttpClient implements HttpClientInterface {

	/**
	 * Send an HTTP request via wp_remote_request().
	 *
	 * @param  string $method HTTP method.
	 * @param  string $url    Target URL.
	 * @param  array  $args   Optional args (headers, body, timeout).
	 * @return array
	 */
	public function request( string $method, string $url, array $args = [] ): array {
		$request_args = [
			'method'  => $method,
			'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : 10,
			'headers' => isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [],
		];

		if ( array_key_exists( 'body', $args ) ) {
			$request_args['body'] = $args['body'];
		}

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return [
				'status'  => 0,
				'body'    => '',
				'headers' => [],
				'error'   => $response->get_error_message(),
			];
		}

		return [
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'headers' => is_array( $response['headers'] ?? null ) ? $response['headers'] : [],
			'error'   => null,
		];
	}
}
