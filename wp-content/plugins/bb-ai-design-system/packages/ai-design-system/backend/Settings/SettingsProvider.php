<?php

namespace FL\DesignSystem\Settings;

use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\Contracts\SettingsStoreInterface;
use FL\DesignSystem\Updater\UpdateApiClient;

class SettingsProvider {

	public const API_URL     = 'https://api.anthropic.com/v1/messages';
	public const API_VERSION = '2023-06-01';

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
		$namespace = 'fl-design-system/v1';

		register_rest_route($namespace, '/settings', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_settings' ],
			'permission_callback' => function () {
				return is_user_logged_in() && $this->auth->can( 'edit_design_systems' );
			},
		]);

		register_rest_route($namespace, '/settings', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'update_settings' ],
			'permission_callback' => [ $this->auth, 'admin_permission_callback' ],
		]);

		register_rest_route($namespace, '/settings/test-ai', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'test_ai_connection' ],
			'permission_callback' => [ $this->auth, 'admin_permission_callback' ],
		]);

		register_rest_route($namespace, '/settings/test-unsplash', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'test_unsplash_connection' ],
			'permission_callback' => [ $this->auth, 'admin_permission_callback' ],
		]);

		register_rest_route($namespace, '/settings/activate-license', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'activate_license' ],
			'permission_callback' => [ $this->auth, 'admin_permission_callback' ],
		]);
	}

	/**
	 * Return all settings with sensitive values masked.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		return new \WP_REST_Response( $this->settings->all(), 200 );
	}

	/**
	 * Update settings from request body.
	 *
	 * Expects a nested structure matching the settings shape, e.g.:
	 * { "ai": { "apiKey": "sk-ant-...", "model": "claude-sonnet-4-6" } }
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function update_settings( \WP_REST_Request $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) || ! is_array( $body ) ) {
			return new \WP_Error(
				'validation_error',
				__( 'Request body must be a JSON object.', 'fl-design-system' ),
				[ 'status' => 422 ],
			);
		}

		$values = $this->flatten_settings( $body );
		if ( empty( $values ) ) {
			return new \WP_Error(
				'validation_error',
				__( 'No valid settings provided.', 'fl-design-system' ),
				[ 'status' => 422 ],
			);
		}

		$this->settings->set( $values );

		return new \WP_REST_Response( $this->settings->all(), 200 );
	}

	/**
	 * Test the AI connection using a stored or provided key.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function test_ai_connection( \WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$api_key = $body['apiKey'] ?? null;

		if ( ! $api_key ) {
			$api_key = $this->settings->get( 'ai.api_key' );
		}

		if ( ! $api_key ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'No API key provided.', 'fl-design-system' ),
				[ 'status' => 400 ],
			);
		}

		$test_body = wp_json_encode([
			'model'      => 'claude-sonnet-4-6',
			'max_tokens' => 10,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => 'Hi',
				],
			],
		]);

		return $this->send_ai_request( $test_body, $api_key );
	}

	/**
	 * Test the Unsplash connection using a stored or provided key.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function test_unsplash_connection( \WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$api_key = isset( $body['apiKey'] ) ? sanitize_text_field( $body['apiKey'] ) : null;

		if ( ! $api_key ) {
			$api_key = $this->settings->get( 'media.unsplash_api_key' );
		}

		if ( ! $api_key ) {
			return new \WP_Error(
				'missing_api_key',
				__( 'No Unsplash API key provided.', 'fl-design-system' ),
				[ 'status' => 400 ],
			);
		}

		$response = wp_remote_get( 'https://api.unsplash.com/photos/random?count=1', [
			'timeout' => 15,
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
			$error_message = isset( $decoded->errors[0] ) ? $decoded->errors[0] : 'Invalid API key or Unsplash API error.';
			return new \WP_Error(
				'api_error',
				$error_message,
				[ 'status' => $status_code ],
			);
		}

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Activate a license key with the BB update server.
	 *
	 * @param  \WP_REST_Request            $request
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function activate_license( \WP_REST_Request $request ) {
		$body        = $request->get_json_params();
		$license_key = isset( $body['licenseKey'] ) ? sanitize_text_field( $body['licenseKey'] ) : '';

		if ( empty( $license_key ) ) {
			return new \WP_Error(
				'missing_license_key',
				__( 'No license key provided.', 'fl-design-system' ),
				[ 'status' => 400 ],
			);
		}

		if ( preg_match( '/[^a-zA-Z\d\s@.\-_]/', $license_key ) ) {
			return new \WP_Error(
				'invalid_license_key',
				__( 'Invalid license key. Only alphanumeric characters, @, ., -, and _ are allowed.', 'fl-design-system' ),
				[ 'status' => 422 ],
			);
		}

		$api      = new UpdateApiClient();
		$response = $api->activate_domain( $license_key, network_home_url() );

		// The BB update server uses two error shapes:
		// - error WITHOUT code: real failure (bad key, expired) -- reject
		// - error WITH code: informational (e.g. "domain already activated") -- treat as success
		if ( isset( $response->error ) && ! isset( $response->code ) ) {
			return new \WP_Error(
				'activation_failed',
				$response->error,
				[ 'status' => 400 ],
			);
		}

		$this->settings->set( [ 'license.key' => $license_key ] );

		return new \WP_REST_Response( [ 'success' => true ], 200 );
	}

	/**
	 * Send a request to the AI API.
	 *
	 * @param  string                      $body    JSON request body.
	 * @param  string                      $api_key API key.
	 * @return \WP_Error|\WP_REST_Response
	 */
	private function send_ai_request( string $body, string $api_key ) {
		$response = wp_remote_post(self::API_URL, [
			'timeout' => 30,
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => self::API_VERSION,
			],
			'body'    => $body,
		]);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'api_error',
				$response->get_error_message(),
				[ 'status' => 502 ],
			);
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$decoded       = json_decode( $response_body );

		if ( $status_code >= 400 ) {
			$error_message = isset( $decoded->error->message ) ? $decoded->error->message : 'Unknown API error';
			return new \WP_Error(
				'api_error',
				$error_message,
				[ 'status' => $status_code ],
			);
		}

		return new \WP_REST_Response( $decoded, 200 );
	}

	/**
	 * Flatten nested settings to dot-notation keys.
	 *
	 * Maps frontend field names to internal keys:
	 * { "ai": { "apiKey": "..." } } becomes [ "ai.api_key" => "..." ]
	 *
	 * @param  array $body Nested settings from request.
	 * @return array Flat dot-notation key/value pairs.
	 */
	private function flatten_settings( array $body ): array {
		$field_map = [
			'apiKey'           => 'api_key',
			'model'            => 'model',
			'brief'            => 'brief',
			'overrides'        => 'overrides',
			'unsplashApiKey'   => 'unsplash_api_key',
			'licenseKey'       => 'key',
		];

		// Fields that accept a flat map (object) value instead of a scalar.
		$map_fields = [ 'brief' ];

		// Map fields whose values should preserve newlines (sanitize_textarea_field).
		$textarea_map_fields = [ 'brief' ];

		// Fields that accept a nested map (e.g. { dsName: { tokenName: value } }).
		$nested_map_fields = [ 'overrides' ];

		$values = [];

		foreach ( $body as $group => $fields ) {
			if ( ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $field => $value ) {
				$mapped_field = $field_map[ $field ] ?? null;
				if ( ! $mapped_field ) {
					continue;
				}
				if ( in_array( $field, $nested_map_fields, true ) ) {
					$sanitized = [];
					if ( is_array( $value ) ) {
						foreach ( $value as $outer_key => $inner ) {
							$sanitized_inner = [];
							if ( is_array( $inner ) ) {
								foreach ( $inner as $k => $v ) {
									$sanitized_inner[ sanitize_text_field( $k ) ] = sanitize_text_field( $v );
								}
							}
							$sanitized[ sanitize_text_field( $outer_key ) ] = $sanitized_inner;
						}
					}
					$values[ $group . '.' . $mapped_field ] = wp_json_encode( $sanitized );
				} elseif ( in_array( $field, $map_fields, true ) ) {
					$sanitized    = [];
					$use_textarea = in_array( $field, $textarea_map_fields, true );
					if ( is_array( $value ) ) {
						foreach ( $value as $k => $v ) {
							$sanitized[ sanitize_text_field( $k ) ] = $use_textarea
								? sanitize_textarea_field( $v )
								: sanitize_text_field( $v );
						}
					}
					$values[ $group . '.' . $mapped_field ] = wp_json_encode( $sanitized );
				} else {
					$stored                                 = is_bool( $value ) ? ( $value ? '1' : '0' ) : sanitize_text_field( $value );
					$values[ $group . '.' . $mapped_field ] = $stored;
				}
			}
		}

		return $values;
	}
}
