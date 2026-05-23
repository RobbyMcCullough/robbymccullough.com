<?php

namespace FL\DesignSystem\Middleware;

class ErrorFormatter {

	public const ROUTE_NAMESPACE = 'fl-design-system';

	public function boot() {
		add_filter( 'rest_request_after_callbacks', [ $this, 'format_error' ], 10, 3 );
	}

	/**
	 * Convert WP_Error responses to a standardized error shape.
	 *
	 * Only processes routes in the fl-design-system namespace.
	 * Output: { error: { code, message, status, details } }
	 *
	 * @param  \WP_Error|\WP_REST_Response $response Response from the callback.
	 * @param  array                       $handler  Route handler info.
	 * @param  \WP_REST_Request            $request  The original request.
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function format_error( $response, $handler, \WP_REST_Request $request ) {
		if ( ! $this->is_our_route( $request ) ) {
			return $response;
		}

		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		return $this->wp_error_to_response( $response );
	}

	/**
	 * Check whether the request targets a fl-design-system route.
	 *
	 * @param  \WP_REST_Request $request
	 * @return bool
	 */
	private function is_our_route( \WP_REST_Request $request ): bool {
		$route = $request->get_route();
		return strpos( $route, '/' . self::ROUTE_NAMESPACE ) === 0;
	}

	/**
	 * Convert a WP_Error into a standardized REST response.
	 *
	 * @param  \WP_Error         $error
	 * @return \WP_REST_Response
	 */
	private function wp_error_to_response( \WP_Error $error ): \WP_REST_Response {
		$code       = $error->get_error_code();
		$message    = $error->get_error_message();
		$error_data = $error->get_error_data();
		$status     = isset( $error_data['status'] ) ? (int) $error_data['status'] : 500;

		// Remove status from details since it's promoted to the top level
		$details = is_array( $error_data ) ? $error_data : [];
		unset( $details['status'] );

		return new \WP_REST_Response([
			'error' => [
				'code'    => $code,
				'message' => $message,
				'status'  => $status,
				'details' => (object) $details,
			],
		], $status);
	}
}
