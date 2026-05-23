<?php

namespace FL\DesignSystem\Updater;

/**
 * Communicates with the Beaver Builder update server API.
 *
 * @package FL\DesignSystem\Updater
 */
class UpdateApiClient {

	const API_URL = 'https://updates.wpbeaverbuilder.com/';

	/**
	 * Activate a license key for this domain.
	 *
	 * @param string $license License key.
	 * @param string $domain  Site domain.
	 * @param array  $products Registered products.
	 * @return object Response object with ->error on failure.
	 */
	public function activate_domain( string $license, string $domain, array $products = [] ): object {
		return $this->api_request( [
			'fl-api-method' => 'activate_domain',
			'license'       => $license,
			'domain'        => $this->clean_domain( $domain ),
			'products'      => wp_json_encode( $products ),
		] );
	}

	/**
	 * Get subscription info for a license.
	 *
	 * @param string $license License key.
	 * @param string $domain  Site domain.
	 * @return object Response object.
	 */
	public function get_subscription_info( string $license, string $domain ): object {
		return $this->api_request( [
			'fl-api-method' => 'subscription_info',
			'license'       => $license,
			'domain'        => $this->clean_domain( $domain ),
		] );
	}

	/**
	 * Check for an available update.
	 *
	 * @param string $license License key.
	 * @param string $domain  Site domain.
	 * @param array  $product Product config (name, version, slug).
	 * @return object Response object.
	 */
	public function check_update( string $license, string $domain, array $product ): object {
		return $this->api_request( [
			'fl-api-method' => 'update_info',
			'license'       => $license,
			'domain'        => $this->clean_domain( $domain ),
			'product'       => $product['name'],
			'slug'          => $product['slug'],
			'version'       => $product['version'],
			'php'           => phpversion(),
			'wp'            => get_bloginfo( 'version' ),
		] );
	}

	/**
	 * Send a POST request to the update API.
	 *
	 * Audit finding M-9 (partial mitigation). Parameters used to ride in the
	 * URL query string of a wp_remote_get(), which leaked the license key
	 * into HTTP access logs, reverse-proxy buffers, and any intermediate
	 * cache that records request lines. Switching to POST removes the
	 * license from the outbound URL. Verified the bb-updates server reads
	 * from $_REQUEST (class-fl-update-api.php:791), so POST is accepted
	 * with no server change. The license still ends up in the
	 * update_plugins transient at rest because the server embeds it in the
	 * returned `package` URL; full removal requires the server to issue
	 * opaque per-request download tokens (deferred).
	 *
	 * sslverify is forced on to harden against MITM downgrade tampering of
	 * the response payload (audit finding M-8 partial).
	 *
	 * @param array $args Request body parameters.
	 * @return object Decoded response or error object.
	 */
	private function api_request( array $args ): object {
		$request = wp_remote_post( self::API_URL, [
			'timeout'   => 25,
			'sslverify' => true,
			'body'      => $args,
		] );

		$error        = new \stdClass();
		$error->error = 'Unknown Error';

		if ( is_wp_error( $request ) ) {
			$error->error = $this->redact_license( $request->get_error_message() );
			return $error;
		}

		$code = wp_remote_retrieve_response_code( $request );
		if ( 200 !== $code ) {
			$error->error = sprintf( '%s response from server', $code );
			return $error;
		}

		$body = wp_remote_retrieve_body( $request );
		if ( is_wp_error( $body ) ) {
			$error->error = $this->redact_license( $body->get_error_message() );
			return $error;
		}

		$decoded = json_decode( $body );
		if ( ! is_object( $decoded ) ) {
			return $error;
		}

		return $decoded;
	}

	/**
	 * Replace license= values inside a string with REDACTED.
	 *
	 * Defense-in-depth so error messages, transports that fall back to
	 * GET semantics, or future code paths that reintroduce the license in
	 * URL form do not surface the secret to logs (audit finding M-9).
	 *
	 * @param string $message Error message.
	 * @return string Redacted message.
	 */
	private function redact_license( string $message ): string {
		return preg_replace( '/license=[^&\s\'"]+/i', 'license=REDACTED', $message );
	}

	/**
	 * Strip query params from a domain URL.
	 *
	 * @param string $url Domain URL.
	 * @return string Cleaned URL.
	 */
	private function clean_domain( string $url ): string {
		$pos = strpos( $url, '?' );
		return $pos ? untrailingslashit( substr( $url, 0, $pos ) ) : $url;
	}
}
