<?php

namespace FL\DesignSystem\Form;

/**
 * First-line spam defense for form submissions.
 *
 * Four checks:
 *
 * 1. Honeypot: reject submissions whose hidden `_fl_hp` field is non-empty.
 *    Bots that fill every input get caught.
 *
 * 2. Time-trap: reject submissions that arrive within MIN_AGE_SECONDS of
 *    render time (bot fills the form too fast) or after MAX_AGE_SECONDS
 *    (stale token, browser left open). The token is HMAC-signed so the
 *    render timestamp cannot be tampered with client-side.
 *
 * 3. Rate limit (M-12): max 30 submissions per 10 minutes per identity.
 *    Identity keys on `user_id` for logged-in submitters and falls back
 *    to remote IP for anonymous flows. This avoids rate-limiting an
 *    entire office/proxy under one shared NAT IP while still bounding
 *    anonymous flood attacks.
 *
 * 4. Per-token idempotency window (M-12): a `(token, payload_hash)` pair
 *    that succeeds is cached for 30 seconds. A repeat submission within
 *    that window returns the cached result without re-firing the form
 *    actions. Handles legitimate retries (network blip, double-click,
 *    back-button resubmit) cleanly, where a single-use token would
 *    instead reject them as spam.
 *
 * The rate limit + idempotency layer raises the cost of casual abuse
 * but does not defend against distributed attackers (rotating IPs,
 * varying payloads).
 */
class SpamGuard {

	public const MIN_AGE_SECONDS = 2;
	public const MAX_AGE_SECONDS = 3600;

	/**
	 * Per-identity rate limit: 30 submissions per 10 minutes.
	 */
	public const RATE_LIMIT_MAX    = 30;
	public const RATE_LIMIT_WINDOW = 600;

	/**
	 * Idempotency window for `(token, payload_hash)` replays.
	 */
	public const IDEMPOTENCY_WINDOW = 30;

	public const REASON_HONEYPOT        = 'honeypot';
	public const REASON_TOKEN_MISSING   = 'token_missing';
	public const REASON_TOKEN_INVALID   = 'token_invalid';
	public const REASON_TOKEN_EXPIRED   = 'token_expired';
	public const REASON_SUBMIT_TOO_FAST = 'submit_too_fast';
	public const REASON_RATE_LIMITED    = 'rate_limited';

	private string $secret;

	/**
	 * @param string $secret HMAC secret. Must be non-empty and stable across requests.
	 *                       In WordPress the caller should pass wp_salt('auth').
	 */
	public function __construct( string $secret ) {
		$this->secret = $secret;
	}

	/**
	 * Check (and increment) the per-identity rate limit.
	 *
	 * Why: bots that bypass the honeypot/time-trap (e.g. by replaying a
	 * known-valid token through a modified payload) are still bounded
	 * to RATE_LIMIT_MAX per RATE_LIMIT_WINDOW. Stored in transients;
	 * the cost of failure is "spammer gets a few extra submissions per
	 * window" so atomic SQL is not required here.
	 *
	 * @param  int    $user_id Logged-in user id (0 if anonymous).
	 * @param  string $ip      Remote IP address.
	 * @return bool  True when the request is within budget.
	 */
	public function check_rate_limit( int $user_id, string $ip ): bool {
		$key   = $this->rate_limit_key( $user_id, $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	/**
	 * Look up a cached idempotent result for `(token, payload_hash)`.
	 *
	 * @param  string $token        Raw `_fl_ts` value.
	 * @param  string $payload_hash Hash of the normalized submission payload.
	 * @return array|null Cached result, or null when no replay is stored.
	 */
	public function get_idempotent_result( string $token, string $payload_hash ): ?array {
		if ( '' === $token || '' === $payload_hash ) {
			return null;
		}
		$value = get_transient( $this->idempotency_key( $token, $payload_hash ) );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Cache the result of a successful submission for IDEMPOTENCY_WINDOW
	 * seconds so legitimate retries with the same `(token, payload)` get
	 * the same response without re-firing the action handlers.
	 *
	 * @param string $token
	 * @param string $payload_hash
	 * @param array  $result
	 */
	public function record_idempotent_result( string $token, string $payload_hash, array $result ): void {
		if ( '' === $token || '' === $payload_hash ) {
			return;
		}
		set_transient( $this->idempotency_key( $token, $payload_hash ), $result, self::IDEMPOTENCY_WINDOW );
	}

	/**
	 * Hash a normalized submission payload to use as the idempotency key
	 * suffix. Sorting keys before hashing lets us match payloads that
	 * differ only in field order.
	 *
	 * @param array $payload
	 * @return string
	 */
	public static function payload_hash( array $payload ): string {
		$normalized = self::normalize_for_hash( $payload );
		return hash( 'sha256', wp_json_encode( $normalized ) );
	}

	/**
	 * Recursively sort an array's keys so two payloads that differ only
	 * in key order produce the same hash.
	 *
	 * @param  mixed $value
	 * @return mixed
	 */
	private static function normalize_for_hash( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		ksort( $value );
		foreach ( $value as $k => $v ) {
			$value[ $k ] = self::normalize_for_hash( $v );
		}
		return $value;
	}

	/**
	 * Build the rate-limit transient key. Hash the IP fallback so the
	 * key length stays bounded and the IP itself is not stored verbatim.
	 *
	 * @param int    $user_id
	 * @param string $ip
	 * @return string
	 */
	private function rate_limit_key( int $user_id, string $ip ): string {
		if ( $user_id > 0 ) {
			return 'fl_ds_form_user_' . $user_id;
		}
		return 'fl_ds_form_ip_' . substr( md5( $ip ), 0, 16 );
	}

	/**
	 * Build the idempotency transient key. Token + payload hash bound
	 * the lookup; truncated to keep the transient name within WP's
	 * 172-character option-name limit.
	 *
	 * @param string $token
	 * @param string $payload_hash
	 * @return string
	 */
	private function idempotency_key( string $token, string $payload_hash ): string {
		return 'fl_ds_form_idem_' . substr( hash( 'sha256', $token . '|' . $payload_hash ), 0, 32 );
	}

	/**
	 * Issue a signed time-trap token for a (block_id, form_id) pair.
	 *
	 * Token format: `{timestamp}.{hex_signature}` where the signature is
	 * HMAC-SHA256 over `{block_id}|{form_id}|{timestamp}`.
	 *
	 * @param  string $block_id Block identifier.
	 * @param  string $form_id  Form identifier.
	 * @param  int|null $timestamp Optional timestamp (primarily for tests); defaults to time().
	 * @return string Signed token.
	 */
	public function issue_token( string $block_id, string $form_id, ?int $timestamp = null ): string {
		$ts  = null === $timestamp ? time() : $timestamp;
		$sig = $this->sign( $block_id, $form_id, $ts );
		return $ts . '.' . $sig;
	}

	/**
	 * Validate honeypot + time-trap for a submission.
	 *
	 * @param  string $token     Raw `_fl_ts` value from the submission.
	 * @param  string $honeypot  Raw `_fl_hp` value from the submission.
	 * @param  string $block_id  Block identifier from the submission.
	 * @param  string $form_id   Form identifier from the submission.
	 * @param  int|null $now     Optional "now" timestamp (primarily for tests).
	 * @return array Result shaped as [ 'valid' => bool, 'reason' => string|null ].
	 */
	public function validate( string $token, string $honeypot, string $block_id, string $form_id, ?int $now = null ): array {
		if ( '' !== $honeypot ) {
			return [
				'valid'  => false,
				'reason' => self::REASON_HONEYPOT,
			];
		}

		if ( '' === $token ) {
			return [
				'valid'  => false,
				'reason' => self::REASON_TOKEN_MISSING,
			];
		}

		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return [
				'valid'  => false,
				'reason' => self::REASON_TOKEN_INVALID,
			];
		}

		$ts       = (int) $parts[0];
		$received = (string) $parts[1];
		$expected = $this->sign( $block_id, $form_id, $ts );

		if ( ! hash_equals( $expected, $received ) ) {
			return [
				'valid'  => false,
				'reason' => self::REASON_TOKEN_INVALID,
			];
		}

		$current = null === $now ? time() : $now;
		$age     = $current - $ts;

		if ( $age < self::MIN_AGE_SECONDS ) {
			return [
				'valid'  => false,
				'reason' => self::REASON_SUBMIT_TOO_FAST,
			];
		}

		if ( $age > self::MAX_AGE_SECONDS ) {
			return [
				'valid'  => false,
				'reason' => self::REASON_TOKEN_EXPIRED,
			];
		}

		return [
			'valid'  => true,
			'reason' => null,
		];
	}

	/**
	 * Produce the HMAC signature for the given tuple.
	 *
	 * @param  string $block_id Block identifier.
	 * @param  string $form_id  Form identifier.
	 * @param  int    $ts       Issue timestamp.
	 * @return string Hex-encoded HMAC-SHA256.
	 */
	private function sign( string $block_id, string $form_id, int $ts ): string {
		$message = $block_id . '|' . $form_id . '|' . $ts;
		return hash_hmac( 'sha256', $message, $this->secret );
	}
}
