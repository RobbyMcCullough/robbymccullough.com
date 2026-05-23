<?php

namespace FL\DesignSystem\Form;

/**
 * Redirect action handler.
 *
 * Returns a URL the client should navigate to after a successful
 * submission. Performs no I/O — the dispatch loop in
 * FormSubmissionProvider only forwards the redirect to the client when
 * every action in the form succeeded, so a single Redirect action acts
 * as a "thank-you page" jump and pairs cleanly with Email or Webhook.
 *
 * Config shape:
 *   [
 *     'url'   => string, // required, absolute http(s) URL or root-relative path.
 *     'delay' => int,    // optional, seconds to wait before navigating. Clamped to [0, 30].
 *   ]
 *
 * Accepted URL formats:
 *   - Absolute http or https URLs (e.g. https://example.com/thanks)
 *   - Root-relative paths starting with `/` (e.g. /thanks)
 *
 * Rejected: protocol-relative (`//host`), `mailto:`, `javascript:`,
 * other schemes, and empty/whitespace input.
 */
class RedirectHandler implements FormActionInterface {

	private const DELAY_MAX_SECONDS = 30;

	/**
	 * Validate the configured URL and echo it back as the redirect target.
	 *
	 * @param  array $submission    Normalized submission payload (unused).
	 * @param  array $action_config Action configuration.
	 * @return array
	 */
	public function handle( array $submission, array $action_config ): array {
		$url = isset( $action_config['url'] ) ? trim( (string) $action_config['url'] ) : '';

		if ( '' === $url ) {
			return [
				'success'  => false,
				'redirect' => null,
				'error'    => 'Redirect action is missing a URL.',
			];
		}

		if ( ! $this->is_acceptable_url( $url ) ) {
			return [
				'success'  => false,
				'redirect' => null,
				'error'    => 'Redirect URL must start with http://, https://, or /.',
			];
		}

		return [
			'success'        => true,
			'redirect'       => esc_url_raw( $url, [ 'http', 'https' ] ),
			'redirect_delay' => $this->normalize_delay( $action_config['delay'] ?? 0 ),
			'error'          => null,
		];
	}

	/**
	 * Coerce the delay config value to an integer in [0, DELAY_MAX_SECONDS].
	 *
	 * Non-numeric strings, negatives, and values above the cap all collapse
	 * to a safe value rather than failing the whole submission — the URL is
	 * the load-bearing field, the delay is a UX nicety.
	 *
	 * @param mixed $delay
	 * @return int
	 */
	private function normalize_delay( $delay ): int {
		if ( ! is_numeric( $delay ) ) {
			return 0;
		}
		$seconds = (int) $delay;
		if ( $seconds < 0 ) {
			return 0;
		}
		if ( $seconds > self::DELAY_MAX_SECONDS ) {
			return self::DELAY_MAX_SECONDS;
		}
		return $seconds;
	}

	/**
	 * Whether the URL is one of the formats we accept for a client-side redirect.
	 *
	 * Root-relative paths must start with a single `/` — `//host` is
	 * protocol-relative and rejected to avoid surprise host changes.
	 */
	private function is_acceptable_url( string $url ): bool {
		if ( str_starts_with( $url, '/' ) ) {
			return ! str_starts_with( $url, '//' );
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return 'http' === $scheme || 'https' === $scheme;
	}
}
