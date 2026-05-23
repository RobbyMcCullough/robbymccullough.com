<?php

namespace FL\DesignSystem\Contracts;

/**
 * Contract for an outbound HTTP client.
 *
 * Implementations wrap a platform-native client (e.g. wp_remote_request).
 */
interface HttpClientInterface {

	/**
	 * Send an HTTP request.
	 *
	 * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.).
	 * @param string $url    Target URL.
	 * @param array  $args   Optional request args:
	 *                       - 'headers' : array<string, string>
	 *                       - 'body'    : string|array (array is form-encoded for non-JSON, encoded upstream otherwise)
	 *                       - 'timeout' : int seconds (default 10)
	 * @return array Response shaped as [
	 *                 'status'  => int,   // 0 on transport failure
	 *                 'body'    => string,
	 *                 'headers' => array,
	 *                 'error'   => string|null,
	 *               ]
	 */
	public function request( string $method, string $url, array $args = [] ): array;
}
