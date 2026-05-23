<?php

namespace FL\DesignSystem\Form;

use FL\DesignSystem\Contracts\HttpClientInterface;
use FL\DesignSystem\Services\UrlGuard;

/**
 * Webhook action handler.
 *
 * Config shape:
 *   [
 *     'url'     => string, // required, must be a valid http(s) URL.
 *     'method'  => 'POST'|'GET', // optional, defaults to POST.
 *     'headers' => array<string, string>, // optional extra headers.
 *   ]
 *
 * The submission payload is JSON-encoded for POST requests and sent as
 * query parameters for GET.
 */
class WebhookHandler implements FormActionInterface {

	private HttpClientInterface $client;

	public function __construct( HttpClientInterface $client ) {
		$this->client = $client;
	}

	/**
	 * Forward the submission to the configured webhook URL.
	 *
	 * @param  array $submission    Normalized submission payload.
	 * @param  array $action_config Action configuration.
	 * @return array
	 */
	public function handle( array $submission, array $action_config ): array {
		$url = isset( $action_config['url'] ) ? trim( (string) $action_config['url'] ) : '';
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return [
				'success'  => false,
				'redirect' => null,
				'error'    => 'Webhook action is missing a valid URL.',
			];
		}

		// M-11: validate the URL points at a public host before we hand
		// the request off to the HTTP client. The handler returns an
		// admin-facing error so site operators understand why the webhook
		// did not fire. The public submitter never sees this message.
		$validated = UrlGuard::resolve_public( $url );
		if ( is_wp_error( $validated ) ) {
			return [
				'success'  => false,
				'redirect' => null,
				'error'    => 'Webhook URL must point to a public Internet host.',
			];
		}

		$method = strtoupper( (string) ( $action_config['method'] ?? 'POST' ) );
		if ( 'POST' !== $method && 'GET' !== $method ) {
			$method = 'POST';
		}

		$headers = [];
		if ( isset( $action_config['headers'] ) && is_array( $action_config['headers'] ) ) {
			foreach ( $action_config['headers'] as $name => $value ) {
				if ( is_string( $name ) && '' !== $name ) {
					$headers[ $name ] = is_scalar( $value ) ? (string) $value : '';
				}
			}
		}

		$payload = [
			'block_id' => (string) ( $submission['block_id'] ?? '' ),
			'form_key' => (string) ( $submission['form_key'] ?? '' ),
			'form_id'  => (string) ( $submission['form_id'] ?? '' ),
			'fields'   => is_array( $submission['fields'] ?? null ) ? $submission['fields'] : [],
		];

		$args = [
			'headers' => $headers,
			'timeout' => 15,
		];

		if ( 'GET' === $method ) {
			$sep  = str_contains( $url, '?' ) ? '&' : '?';
			$url .= $sep . http_build_query( [ 'payload' => wp_json_encode( $payload ) ] );
		} else {
			$headers['Content-Type'] = 'application/json';
			$args['headers']         = $headers;
			$args['body']            = wp_json_encode( $payload );
		}

		$response = $this->client->request( $method, $url, $args );
		$status   = isset( $response['status'] ) ? (int) $response['status'] : 0;

		if ( $status < 200 || $status >= 300 ) {
			$error = isset( $response['error'] ) && '' !== $response['error']
				? (string) $response['error']
				: sprintf( 'Webhook returned HTTP %d.', $status );
			return [
				'success'  => false,
				'redirect' => null,
				'error'    => $error,
			];
		}

		return [
			'success'  => true,
			'redirect' => null,
			'error'    => null,
		];
	}
}
