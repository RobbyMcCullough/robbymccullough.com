<?php

namespace FL\DesignSystem\Services;

/**
 * SSRF guard for outbound HTTP requests.
 *
 * Two responsibilities:
 *
 *   1. {@see resolve_public()} validates that a URL points at a host whose
 *      DNS records resolve entirely to public-internet IPs (or matches the
 *      `fl_ds_url_guard_allowlist` filter). Closes H-1 (ImageValidator
 *      reaches arbitrary internal hosts via HEAD) and M-11 (webhook URL
 *      allows internal hosts).
 *
 *   2. {@see safe_remote_head()} / {@see safe_remote_request()} run the
 *      validated request with the resolved IP pinned via cURL's
 *      `CURLOPT_RESOLVE`, closing the DNS-rebinding TOCTOU where the
 *      first lookup returns a public IP and the second returns a private
 *      one. Falls back to streams transport with a back-to-back resolve
 *      check on hosts without cURL.
 *
 * Local/development environments (`wp_get_environment_type()` of `local`
 * or `development`) auto-bypass the private-IP rejection so devs can hit
 * `localhost`, `*.test`, `host.docker.internal`, and `192.168.x.x` for
 * staging imports. The resolved-IP pinning still applies in all
 * environments, so DNS rebinding is mitigated even with the bypass.
 *
 * Filters:
 *   - `fl_ds_url_guard_allowlist` (array of hostnames) — agencies override
 *     the default block for known-safe internal hosts. Pinning still
 *     applies at fetch time. Use with caution; broaden only the hosts you
 *     control.
 *
 * Actions:
 *   - `fl_ds_url_guard_rejected` ($host) — fired on production/staging
 *     when a host is rejected. Drives the one-time admin notice.
 */
class UrlGuard {

	/**
	 * Cache window for DNS resolution results, in seconds.
	 */
	private const RESOLVE_CACHE_TTL = 300;

	/**
	 * Forbidden IPv4 ranges (CIDR). Any IP that falls inside any of these
	 * is treated as private/restricted.
	 *
	 * - 0.0.0.0/8        — current network, source-only
	 * - 10.0.0.0/8       — RFC1918 private
	 * - 100.64.0.0/10    — Carrier-grade NAT
	 * - 127.0.0.0/8      — loopback
	 * - 169.254.0.0/16   — link-local + AWS/GCP metadata service
	 * - 172.16.0.0/12    — RFC1918 private
	 * - 192.0.0.0/24     — IETF protocol assignments
	 * - 192.0.2.0/24     — TEST-NET-1
	 * - 192.168.0.0/16   — RFC1918 private
	 * - 198.18.0.0/15    — benchmarking
	 * - 198.51.100.0/24  — TEST-NET-2
	 * - 203.0.113.0/24   — TEST-NET-3
	 * - 224.0.0.0/4      — multicast
	 * - 240.0.0.0/4      — reserved future use
	 * - 255.255.255.255  — broadcast
	 */
	private const PRIVATE_IPV4_RANGES = [
		[ '0.0.0.0', 8 ],
		[ '10.0.0.0', 8 ],
		[ '100.64.0.0', 10 ],
		[ '127.0.0.0', 8 ],
		[ '169.254.0.0', 16 ],
		[ '172.16.0.0', 12 ],
		[ '192.0.0.0', 24 ],
		[ '192.0.2.0', 24 ],
		[ '192.168.0.0', 16 ],
		[ '198.18.0.0', 15 ],
		[ '198.51.100.0', 24 ],
		[ '203.0.113.0', 24 ],
		[ '224.0.0.0', 4 ],
		[ '240.0.0.0', 4 ],
	];

	/**
	 * Resolve and validate a URL.
	 *
	 * Returns an array `[ 'host' => ..., 'port' => ..., 'ip' => ..., 'scheme' => ... ]`
	 * on success, or `WP_Error` on failure. "Any IP private" rejects the
	 * host: a mixed-record host with one public + one private IP is
	 * untrusted because the actual fetch could land on either.
	 *
	 * Local/development environments skip the private-IP rejection but
	 * still resolve+pin so DNS rebinding is mitigated regardless.
	 *
	 * @param string $url
	 * @return array|\WP_Error
	 */
	public static function resolve_public( string $url ) {
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) ) {
			return new \WP_Error( 'fl_ds_url_guard_parse', __( 'Invalid URL.', 'fl-design-system' ) );
		}
		$scheme = strtolower( (string) ( $parsed['scheme'] ?? '' ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return new \WP_Error( 'fl_ds_url_guard_scheme', __( 'URL scheme must be http or https.', 'fl-design-system' ) );
		}
		$host = strtolower( (string) ( $parsed['host'] ?? '' ) );
		if ( '' === $host ) {
			return new \WP_Error( 'fl_ds_url_guard_host', __( 'URL has no host.', 'fl-design-system' ) );
		}
		$port = isset( $parsed['port'] ) ? (int) $parsed['port'] : ( 'https' === $scheme ? 443 : 80 );

		$cache = self::cached_resolve( $host );
		if ( null !== $cache ) {
			$ips = $cache;
		} else {
			$ips = self::resolve_ips( $host );
			self::cache_resolve( $host, $ips );
		}

		if ( empty( $ips ) ) {
			return new \WP_Error(
				'fl_ds_url_guard_unresolvable',
				/* translators: %s: hostname that failed to resolve. */
				sprintf( __( 'Could not resolve host: %s', 'fl-design-system' ), $host )
			);
		}

		$is_local_dev = self::is_local_dev_environment();
		$allowlist    = self::resolve_allowlist();

		if ( ! $is_local_dev && ! in_array( $host, $allowlist, true ) ) {
			foreach ( $ips as $ip ) {
				if ( self::is_private_ip( $ip ) ) {
					do_action( 'fl_ds_url_guard_rejected', $host );
					self::record_rejected_host( $host );
					return new \WP_Error(
						'fl_ds_url_guard_private',
						/* translators: %s: hostname that resolved to a private or restricted IP. */
						sprintf( __( 'Host %s resolves to a private or restricted IP.', 'fl-design-system' ), $host ),
						[ 'host' => $host ]
					);
				}
			}
		}

		// Pin to the first resolved IP. Mixed-record hosts in the allowlist
		// or in local-dev still get a single deterministic target.
		return [
			'host'   => $host,
			'port'   => $port,
			'ip'     => $ips[0],
			'scheme' => $scheme,
		];
	}

	/**
	 * Wrapper around `wp_remote_head` that pins the resolved IP via cURL.
	 *
	 * @param string $url
	 * @param array  $args
	 * @return array|\WP_Error
	 */
	public static function safe_remote_head( string $url, array $args = [] ) {
		return self::safe_remote_call( $url, array_merge( $args, [ 'method' => 'HEAD' ] ) );
	}

	/**
	 * Wrapper around `wp_remote_request` that pins the resolved IP via cURL.
	 *
	 * @param string $url
	 * @param array  $args
	 * @return array|\WP_Error
	 */
	public static function safe_remote_request( string $url, array $args = [] ) {
		return self::safe_remote_call( $url, $args );
	}

	/**
	 * Shared body for `safe_remote_*`. Validates, optionally pins, fetches.
	 *
	 * @param string $url
	 * @param array  $args
	 * @return array|\WP_Error
	 */
	private static function safe_remote_call( string $url, array $args ) {
		$validated = self::resolve_public( $url );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$args['sslverify'] = true;

		$method         = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$args['method'] = $method;

		if ( self::can_pin_with_curl() ) {
			return self::request_with_curl_pin( $url, $args, $validated );
		}

		// Streams fallback: re-resolve immediately before the fetch and
		// verify the IP set is still entirely public (or in allowlist /
		// local-dev). Narrows the TOCTOU window from indefinite to ms.
		$revalidated = self::resolve_public( $url );
		if ( is_wp_error( $revalidated ) ) {
			return $revalidated;
		}
		if ( $revalidated['ip'] !== $validated['ip'] ) {
			return new \WP_Error(
				'fl_ds_url_guard_rebind',
				__( 'DNS resolution changed between validation and fetch.', 'fl-design-system' )
			);
		}
		self::record_streams_fallback( $validated['host'] );
		return wp_remote_request( $url, $args );
	}

	/**
	 * Whether cURL is available and the WP HTTP transport will use it.
	 */
	private static function can_pin_with_curl(): bool {
		return function_exists( 'curl_init' );
	}

	/**
	 * Issue the request through wp_remote_request with a temporary
	 * `http_api_curl` filter that injects `CURLOPT_RESOLVE` to pin the
	 * host:port:ip triple. Pins SNI / Host header to the original
	 * hostname so cert validation still works.
	 *
	 * @param string $url
	 * @param array  $args
	 * @param array  $validated `[ 'host' => ..., 'port' => ..., 'ip' => ... ]`
	 * @return array|\WP_Error
	 */
	private static function request_with_curl_pin( string $url, array $args, array $validated ) {
		$pin = sprintf( '%s:%d:%s', $validated['host'], (int) $validated['port'], $validated['ip'] );

		$callback = function ( $handle ) use ( $pin ) {
			if ( is_resource( $handle ) || $handle instanceof \CurlHandle ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, [ $pin ] );
			}
		};

		add_action( 'http_api_curl', $callback, 10, 1 );
		try {
			$response = wp_remote_request( $url, $args );
		} finally {
			remove_action( 'http_api_curl', $callback, 10 );
		}
		return $response;
	}

	/**
	 * DNS resolution for a host: returns IPv4 + IPv6 addresses.
	 *
	 * Calls into the `fl_ds_url_guard_resolve_override` filter first so
	 * tests (and pluggable resolution callbacks) can pre-populate results
	 * without hitting the network.
	 *
	 * @param string $host
	 * @return string[]
	 */
	private static function resolve_ips( string $host ): array {
		// Test/extension seam: a non-null filter return short-circuits the
		// real DNS lookup. Tests register the mapping in setUp.
		$override = apply_filters( 'fl_ds_url_guard_resolve_override', null, $host );
		if ( null !== $override ) {
			return is_array( $override ) ? array_values( $override ) : [];
		}

		$ips = [];
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return [ $host ];
		}
		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = $record['ip'];
					}
				}
			}
			$records6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $records6 ) ) {
				foreach ( $records6 as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}
		if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
			$fallback = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $fallback ) ) {
				$ips = array_values( $fallback );
			}
		}
		return $ips;
	}

	/**
	 * Whether an IP falls inside any of the private/restricted ranges.
	 *
	 * @param string $ip
	 * @return bool
	 */
	public static function is_private_ip( string $ip ): bool {
		// IPv6 private/restricted ranges:
		//   ::1            loopback
		//   fc00::/7       unique local
		//   fe80::/10      link-local
		//   ::ffff:0:0/96  IPv4-mapped (delegate to IPv4 path)
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$lower = strtolower( $ip );
			if ( '::1' === $lower || '::' === $lower ) {
				return true;
			}
			$packed = inet_pton( $lower );
			if ( false === $packed ) {
				return true;
			}
			$first_byte  = ord( $packed[0] );
			$second_byte = ord( $packed[1] );
			// fc00::/7 => first byte 0xFC or 0xFD.
			if ( 0xFC === ( $first_byte & 0xFE ) ) {
				return true;
			}
			// fe80::/10 => first byte 0xFE, second byte top 2 bits 10 (0x80–0xBF).
			if ( 0xFE === $first_byte && ( $second_byte & 0xC0 ) === 0x80 ) {
				return true;
			}
			// IPv4-mapped IPv6 (::ffff:a.b.c.d) — recurse on the IPv4 part.
			if ( 0 === strncmp( $packed, "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xFF\xFF", 12 ) ) {
				$v4 = inet_ntop( substr( $packed, 12 ) );
				if ( is_string( $v4 ) ) {
					return self::is_private_ip( $v4 );
				}
			}
			return false;
		}

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			// Couldn't parse — treat as untrusted.
			return true;
		}

		$ip_long = ip2long( $ip );
		if ( false === $ip_long ) {
			return true;
		}
		foreach ( self::PRIVATE_IPV4_RANGES as $range ) {
			[ $base, $bits ] = $range;
			$base_long       = ip2long( $base );
			if ( false === $base_long ) {
				continue;
			}
			$mask = 0 === $bits ? 0 : ( ~( ( 1 << ( 32 - $bits ) ) - 1 ) & 0xFFFFFFFF );
			if ( ( $ip_long & $mask ) === ( $base_long & $mask ) ) {
				return true;
			}
		}
		// 255.255.255.255 broadcast.
		if ( -1 === $ip_long || 0xFFFFFFFF === $ip_long ) {
			return true;
		}
		return false;
	}

	/**
	 * Whether the current environment skips private-IP rejection.
	 *
	 * @return bool
	 */
	private static function is_local_dev_environment(): bool {
		if ( ! function_exists( 'wp_get_environment_type' ) ) {
			return false;
		}
		$env = wp_get_environment_type();
		return 'local' === $env || 'development' === $env;
	}

	/**
	 * Hostname allowlist via `fl_ds_url_guard_allowlist`.
	 *
	 * @return string[]
	 */
	private static function resolve_allowlist(): array {
		$allowlist = apply_filters( 'fl_ds_url_guard_allowlist', [] );
		if ( ! is_array( $allowlist ) ) {
			return [];
		}
		return array_map( 'strtolower', array_filter( $allowlist, 'is_string' ) );
	}

	/**
	 * Track the last rejected host in a transient so the admin notice
	 * can surface it. 24 hour TTL; replaced on each new rejection.
	 *
	 * @param string $host
	 */
	private static function record_rejected_host( string $host ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		// Truncated host only; never the full URL or path.
		set_transient( 'fl_ds_url_guard_last_rejected', substr( $host, 0, 255 ), DAY_IN_SECONDS );
	}

	/**
	 * Track that the streams fallback was used so the admin notice can
	 * inform operators.
	 *
	 * @param string $host
	 */
	private static function record_streams_fallback( string $host ): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}
		set_transient( 'fl_ds_url_guard_streams_fallback', 1, DAY_IN_SECONDS );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[fl-ds-url-guard] Streams fallback used for host %s.', $host ) );
		}
	}

	/**
	 * Memoize DNS resolution results for `RESOLVE_CACHE_TTL` seconds. Keyed
	 * by hostname so multiple URLs against the same host within an import
	 * pass share the same lookup.
	 *
	 * @param string $host
	 * @return string[]|null
	 */
	private static function cached_resolve( string $host ): ?array {
		$cache = $GLOBALS['__fl_ds_url_guard_resolve_cache'] ?? [];
		if ( isset( $cache[ $host ] ) && $cache[ $host ]['expires_at'] > time() ) {
			return $cache[ $host ]['ips'];
		}
		return null;
	}

	/**
	 * @param string   $host
	 * @param string[] $ips
	 */
	private static function cache_resolve( string $host, array $ips ): void {
		$cache                                      = $GLOBALS['__fl_ds_url_guard_resolve_cache'] ?? [];
		$cache[ $host ]                             = [
			'ips'        => $ips,
			'expires_at' => time() + self::RESOLVE_CACHE_TTL,
		];
		$GLOBALS['__fl_ds_url_guard_resolve_cache'] = $cache;
	}

	/**
	 * Test seam: clear the resolve cache.
	 */
	public static function clear_resolve_cache(): void {
		$GLOBALS['__fl_ds_url_guard_resolve_cache'] = [];
	}
}
