<?php

namespace FL\DesignSystem\Media;

use FL\DesignSystem\Services\UrlGuard;

/**
 * Lightweight image URL validation for the import pipeline.
 *
 * Catches obviously broken, placeholder, or hallucinated image URLs
 * in HTML and replaces them with a sensible default.
 */
class ImageValidator {

	/**
	 * Default placeholder image as an inline SVG data URL.
	 *
	 * Semi-transparent so it composites against whatever section background
	 * it lands on — black and white stops at 0.15 alpha mean the placeholder
	 * reads on light, dark, and branded backgrounds without committing to a
	 * color the surrounding design hasn't asked for. A 1px dual-alpha border
	 * (also black + white at 0.15) defines the image area on any background.
	 *
	 * Source SVG (regenerate the constant with `printf '%s' '<svg...>' | base64`):
	 *
	 *   <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 800" preserveAspectRatio="none">
	 *     <defs>
	 *       <linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">
	 *         <stop offset="0%" stop-color="rgb(255,255,255)" stop-opacity="0.15"/>
	 *         <stop offset="100%" stop-color="rgb(0,0,0)" stop-opacity="0.15"/>
	 *       </linearGradient>
	 *       <radialGradient id="h" cx="30%" cy="25%" r="60%">
	 *         <stop offset="0%" stop-color="rgb(255,255,255)" stop-opacity="0.10"/>
	 *         <stop offset="100%" stop-color="rgb(255,255,255)" stop-opacity="0"/>
	 *       </radialGradient>
	 *     </defs>
	 *     <rect width="1200" height="800" fill="url(#g)"/>
	 *     <rect width="1200" height="800" fill="url(#h)"/>
	 *     <rect width="1200" height="800" fill="none" stroke="rgb(0,0,0)"
	 *           stroke-opacity="0.15" stroke-width="2" vector-effect="non-scaling-stroke"/>
	 *     <rect width="1200" height="800" fill="none" stroke="rgb(255,255,255)"
	 *           stroke-opacity="0.15" stroke-width="2" vector-effect="non-scaling-stroke"/>
	 *   </svg>
	 */
	const DEFAULT_PLACEHOLDER = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxMjAwIDgwMCIgcHJlc2VydmVBc3BlY3RSYXRpbz0ibm9uZSI+PGRlZnM+PGxpbmVhckdyYWRpZW50IGlkPSJnIiB4MT0iMCUiIHkxPSIwJSIgeDI9IjEwMCUiIHkyPSIxMDAlIj48c3RvcCBvZmZzZXQ9IjAlIiBzdG9wLWNvbG9yPSJyZ2IoMjU1LDI1NSwyNTUpIiBzdG9wLW9wYWNpdHk9IjAuMTUiLz48c3RvcCBvZmZzZXQ9IjEwMCUiIHN0b3AtY29sb3I9InJnYigwLDAsMCkiIHN0b3Atb3BhY2l0eT0iMC4xNSIvPjwvbGluZWFyR3JhZGllbnQ+PHJhZGlhbEdyYWRpZW50IGlkPSJoIiBjeD0iMzAlIiBjeT0iMjUlIiByPSI2MCUiPjxzdG9wIG9mZnNldD0iMCUiIHN0b3AtY29sb3I9InJnYigyNTUsMjU1LDI1NSkiIHN0b3Atb3BhY2l0eT0iMC4xMCIvPjxzdG9wIG9mZnNldD0iMTAwJSIgc3RvcC1jb2xvcj0icmdiKDI1NSwyNTUsMjU1KSIgc3RvcC1vcGFjaXR5PSIwIi8+PC9yYWRpYWxHcmFkaWVudD48L2RlZnM+PHJlY3Qgd2lkdGg9IjEyMDAiIGhlaWdodD0iODAwIiBmaWxsPSJ1cmwoI2cpIi8+PHJlY3Qgd2lkdGg9IjEyMDAiIGhlaWdodD0iODAwIiBmaWxsPSJ1cmwoI2gpIi8+PHJlY3QgeD0iMCIgeT0iMCIgd2lkdGg9IjEyMDAiIGhlaWdodD0iODAwIiBmaWxsPSJub25lIiBzdHJva2U9InJnYigwLDAsMCkiIHN0cm9rZS1vcGFjaXR5PSIwLjE1IiBzdHJva2Utd2lkdGg9IjIiIHZlY3Rvci1lZmZlY3Q9Im5vbi1zY2FsaW5nLXN0cm9rZSIvPjxyZWN0IHg9IjAiIHk9IjAiIHdpZHRoPSIxMjAwIiBoZWlnaHQ9IjgwMCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSJyZ2IoMjU1LDI1NSwyNTUpIiBzdHJva2Utb3BhY2l0eT0iMC4xNSIgc3Ryb2tlLXdpZHRoPSIyIiB2ZWN0b3ItZWZmZWN0PSJub24tc2NhbGluZy1zdHJva2UiLz48L3N2Zz4=';

	/**
	 * Maximum number of images to validate via HTTP per page.
	 */
	const MAX_HTTP_CHECKS = 20;

	/**
	 * Timeout in seconds for HTTP HEAD requests.
	 */
	const HTTP_TIMEOUT = 2;

	/**
	 * Domains that are known placeholder services.
	 *
	 * @var string[]
	 */
	private static $placeholder_domains = [
		'example.com',
		'via.placeholder.com',
		'placehold.co',
	];

	/**
	 * Filename patterns that indicate a placeholder image.
	 *
	 * @var string[]
	 */
	private static $placeholder_filenames = [
		'placeholder.jpg',
		'placeholder.png',
		'placeholder.webp',
	];

	/**
	 * Validate and fix image URLs in an HTML string.
	 *
	 * Checks <img src=""> attributes. Replaces broken/placeholder URLs
	 * with the default placeholder.
	 *
	 * @param string $html HTML string to validate.
	 * @return string HTML with validated/replaced image URLs.
	 */
	public static function validate( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}

		$checked    = [];
		$http_count = 0;

		return preg_replace_callback(
			'/<img\b([^>]*)\bsrc\s*=\s*(["\'])([^"\']*)\2([^>]*)>/i',
			function ( $matches ) use ( &$checked, &$http_count ) {
				$before = $matches[1];
				$quote  = $matches[2];
				$url    = $matches[3];
				$after  = $matches[4];

				$validated = self::validate_url( $url, $checked, $http_count );

				return '<img' . $before . 'src=' . $quote . $validated . $quote . $after . '>';
			},
			$html
		);
	}

	/**
	 * Validate a single image URL.
	 *
	 * @param string $url        The URL to validate.
	 * @param array  &$checked   Cache of already-checked URLs.
	 * @param int    &$http_count Running count of HTTP checks performed.
	 * @return string The validated URL or the default placeholder.
	 */
	private static function validate_url( string $url, array &$checked, int &$http_count ): string {
		// Empty or invalid src values.
		if ( '' === $url || '#' === $url || 'about:blank' === $url ) {
			return self::DEFAULT_PLACEHOLDER;
		}

		// Data URLs are fine.
		if ( 0 === strpos( $url, 'data:' ) ) {
			return $url;
		}

		// Relative URLs are local assets — keep them.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		// Check cache.
		if ( isset( $checked[ $url ] ) ) {
			return $checked[ $url ];
		}

		// Pattern-based placeholder detection (no HTTP needed).
		if ( self::is_placeholder_url( $url ) ) {
			$checked[ $url ] = self::DEFAULT_PLACEHOLDER;
			return self::DEFAULT_PLACEHOLDER;
		}

		// Trust Unsplash URLs.
		if ( false !== strpos( $url, 'images.unsplash.com' ) ) {
			$checked[ $url ] = $url;
			return $url;
		}

		// HTTP validation with limit.
		if ( $http_count >= self::MAX_HTTP_CHECKS ) {
			$checked[ $url ] = $url;
			return $url;
		}

		++$http_count;
		$result = self::check_url_via_http( $url );

		$checked[ $url ] = $result;
		return $result;
	}

	/**
	 * Check whether a URL matches known placeholder patterns.
	 *
	 * @param string $url The URL to check.
	 * @return bool True if the URL is a placeholder.
	 */
	private static function is_placeholder_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( $host ) {
			foreach ( self::$placeholder_domains as $domain ) {
				if ( $host === $domain || self::str_ends_with( $host, '.' . $domain ) ) {
					return true;
				}
			}
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( $path ) {
			$basename = basename( $path );
			foreach ( self::$placeholder_filenames as $filename ) {
				if ( $basename === $filename ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Perform an HTTP HEAD request to verify a URL returns a 2xx status.
	 *
	 * Routed through {@see UrlGuard::safe_remote_head} so the request is
	 * gated by the SSRF private-IP check (H-1) and the resolved IP is
	 * pinned via cURL to defeat DNS rebinding. Internal-host blocks return
	 * the placeholder via the same code path as a 5xx response so we do
	 * not differentiate "not reachable" from "internally blocked" to the
	 * caller (avoids SSRF info leak via differential logging).
	 *
	 * @param string $url The URL to check.
	 * @return string The original URL if valid, or the default placeholder.
	 */
	private static function check_url_via_http( string $url ): string {
		$response = UrlGuard::safe_remote_head( $url, [
			'timeout' => self::HTTP_TIMEOUT,
		] );

		if ( is_wp_error( $response ) ) {
			return self::DEFAULT_PLACEHOLDER;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			return $url;
		}

		return self::DEFAULT_PLACEHOLDER;
	}

	/**
	 * Polyfill for str_ends_with (PHP 8.0+).
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The string to search for.
	 * @return bool
	 */
	private static function str_ends_with( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return true;
		}
		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}
