<?php
/**
 * JWT validation service for MCP OAuth.
 *
 * Fetches JWKS from the Authorization Server, caches keys as a WP
 * transient, and validates incoming JWT access tokens.
 *
 * @package FL\DesignSystem\McpOAuth
 */

namespace FL\DesignSystem\McpOAuth\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\SignatureInvalidException;
use stdClass;

class JwtValidator {

	/**
	 * Transient key for the cached JWKS.
	 *
	 * @var string
	 */
	private const JWKS_TRANSIENT = 'fl_ds_mcp_oauth_jwks';

	/**
	 * JWKS cache TTL in seconds (24 hours).
	 *
	 * @var int
	 */
	private const JWKS_TTL = 86400;

	/**
	 * Transient key for JWKS refetch cooldown.
	 *
	 * @var string
	 */
	private const JWKS_COOLDOWN_TRANSIENT = 'fl_ds_mcp_oauth_jwks_cooldown';

	/**
	 * JWKS refetch cooldown TTL in seconds (1 minute).
	 *
	 * @var int
	 */
	private const JWKS_COOLDOWN_TTL = 60;

	/**
	 * Authorization Server URL.
	 *
	 * @var string
	 */
	private string $as_url;

	/**
	 * Expected audience (this site's MCP endpoint URL).
	 *
	 * @var string
	 */
	private string $expected_audience;

	/**
	 * @param string $as_url            Authorization Server URL (issuer).
	 * @param string $expected_audience This site's MCP endpoint URL.
	 */
	public function __construct( string $as_url, string $expected_audience ) {
		$this->as_url            = rtrim( $as_url, '/' );
		$this->expected_audience = $expected_audience;
	}

	/**
	 * Validate a JWT token string.
	 *
	 * Returns the decoded payload on success, or a WP_Error on failure.
	 *
	 * @param string $token The raw JWT string.
	 *
	 * @return stdClass|\WP_Error Decoded JWT payload or error.
	 */
	public function validate( string $token ) {
		// Read the kid from the JWT header without verifying the signature.
		$kid = $this->extract_kid( $token );
		if ( is_wp_error( $kid ) ) {
			return $kid;
		}

		// Fetch keys, trying cache first.
		$keys = $this->get_keys( $kid );
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}

		// Decode and verify the JWT.
		try {
			// Allow 30 seconds of clock drift between the AS and WordPress.
			JWT::$leeway = 30;

			$headers = new stdClass();
			$payload = JWT::decode( $token, $keys, $headers );
		} catch ( ExpiredException $e ) {
			return new \WP_Error(
				'jwt_expired',
				'The access token has expired.',
				[ 'status' => 401 ]
			);
		} catch ( BeforeValidException $e ) {
			return new \WP_Error(
				'jwt_not_yet_valid',
				'The access token is not yet valid.',
				[ 'status' => 401 ]
			);
		} catch ( SignatureInvalidException $e ) {
			return new \WP_Error(
				'jwt_signature_invalid',
				'JWT signature verification failed.',
				[ 'status' => 401 ]
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'jwt_invalid',
				'Invalid access token: ' . $e->getMessage(),
				[ 'status' => 401 ]
			);
		} finally {
			JWT::$leeway = 0;
		}

		// Verify issuer.
		if ( ! isset( $payload->iss ) || $payload->iss !== $this->as_url ) {
			return new \WP_Error(
				'jwt_invalid_issuer',
				'JWT issuer does not match the configured Authorization Server.',
				[ 'status' => 401 ]
			);
		}

		// Verify audience.
		if ( ! isset( $payload->aud ) || $payload->aud !== $this->expected_audience ) {
			return new \WP_Error(
				'jwt_invalid_audience',
				'JWT audience does not match this site.',
				[ 'status' => 401 ]
			);
		}

		// Ensure wp_user_id claim exists.
		if ( ! isset( $payload->wp_user_id ) || ! is_numeric( $payload->wp_user_id ) ) {
			return new \WP_Error(
				'jwt_missing_user',
				'JWT does not contain a valid wp_user_id claim.',
				[ 'status' => 401 ]
			);
		}

		// Require expiration claim.
		if ( ! isset( $payload->exp ) ) {
			return new \WP_Error(
				'jwt_missing_exp',
				'JWT must contain an exp claim.',
				[ 'status' => 401 ]
			);
		}

		return $payload;
	}

	/**
	 * Extract the kid (key ID) from a JWT header without verifying signature.
	 *
	 * @param string $token Raw JWT string.
	 *
	 * @return string|\WP_Error The kid value or an error.
	 */
	private function extract_kid( string $token ) {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) {
			return new \WP_Error(
				'jwt_malformed',
				'JWT does not have 3 segments.',
				[ 'status' => 401 ]
			);
		}

		$header_json = base64_decode( strtr( $parts[0], '-_', '+/' ) );
		if ( false === $header_json ) {
			return new \WP_Error(
				'jwt_malformed',
				'JWT header is not valid base64.',
				[ 'status' => 401 ]
			);
		}

		$header = json_decode( $header_json );
		if ( ! is_object( $header ) || ! isset( $header->kid ) ) {
			return new \WP_Error(
				'jwt_missing_kid',
				'JWT header does not contain a kid.',
				[ 'status' => 401 ]
			);
		}

		return $header->kid;
	}

	/**
	 * Get parsed JWKS keys, with kid-based cache invalidation.
	 *
	 * If the kid is not found in the cached key set, the JWKS is
	 * re-fetched immediately to handle key rotation.
	 *
	 * @param string $kid The key ID from the JWT header.
	 *
	 * @return array<string, Key>|\WP_Error Parsed keys or error.
	 */
	private function get_keys( string $kid ) {
		// Try cached keys first.
		$cached_jwks = get_transient( self::JWKS_TRANSIENT );
		if ( false !== $cached_jwks && is_array( $cached_jwks ) ) {
			$keys = $this->parse_jwks( $cached_jwks );
			if ( ! is_wp_error( $keys ) && isset( $keys[ $kid ] ) ) {
				return $keys;
			}
			// kid not found in cache -- fall through to re-fetch (key rotation).
		}

		// Rate-limit JWKS refetches to prevent DoS via unknown kid flooding.
		if ( false !== get_transient( self::JWKS_COOLDOWN_TRANSIENT ) ) {
			return new \WP_Error(
				'jwks_rate_limited',
				'JWKS was recently fetched. Try again shortly.',
				[ 'status' => 401 ]
			);
		}

		// Fetch fresh JWKS from the Authorization Server.
		return $this->fetch_and_cache_jwks();
	}

	/**
	 * Fetch JWKS from the Authorization Server and cache it.
	 *
	 * @return array<string, Key>|\WP_Error Parsed keys or error.
	 */
	private function fetch_and_cache_jwks() {
		$jwks_url = $this->as_url . '/.well-known/jwks.json';

		$response = wp_remote_get( $jwks_url, [
			'timeout' => 10,
			'headers' => [
				'Accept' => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'jwks_fetch_failed',
				'Failed to fetch JWKS from Authorization Server: ' . $response->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return new \WP_Error(
				'jwks_fetch_failed',
				'JWKS endpoint returned HTTP ' . $status_code,
				[ 'status' => 500 ]
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$jwks = json_decode( $body, true );

		if ( ! is_array( $jwks ) || ! isset( $jwks['keys'] ) ) {
			return new \WP_Error(
				'jwks_invalid',
				'JWKS response is not valid JSON or missing keys.',
				[ 'status' => 500 ]
			);
		}

		// Cache the raw JWKS data (not parsed Key objects, those are not serializable).
		set_transient( self::JWKS_TRANSIENT, $jwks, self::JWKS_TTL );
		set_transient( self::JWKS_COOLDOWN_TRANSIENT, 1, self::JWKS_COOLDOWN_TTL );

		return $this->parse_jwks( $jwks );
	}

	/**
	 * Parse raw JWKS data into Key objects.
	 *
	 * @param array $jwks Raw JWKS array with a 'keys' member.
	 *
	 * @return array<string, Key>|\WP_Error Parsed keys or error.
	 */
	private function parse_jwks( array $jwks ) {
		try {
			return JWK::parseKeySet( $jwks );
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'jwks_parse_failed',
				'Failed to parse JWKS: ' . $e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}
}
