<?php
/**
 * HMAC-SHA256 challenge signing service.
 *
 * Signs challenge tokens during the multi-user auth flow so the
 * Authorization Server can verify that the user is authenticated
 * as a WordPress admin on this site.
 *
 * @package FL\DesignSystem\McpOAuth
 */

namespace FL\DesignSystem\McpOAuth\Services;

class ChallengeService {

	/**
	 * Sign a challenge token with HMAC-SHA256.
	 *
	 * Computes: hash_hmac('sha256', $challenge . $wp_user_id, $challenge_secret)
	 *
	 * @param string $challenge   The challenge token from the Authorization Server.
	 * @param int    $wp_user_id  The current WordPress user ID.
	 *
	 * @return string|null Hex-encoded HMAC signature, or null if no secret configured.
	 */
	public function sign( string $challenge, int $wp_user_id ): ?string {
		// Stored unencrypted in wp_options. Anyone with database access already
		// has full site control, so encrypting this value provides no additional
		// security.
		$challenge_secret = get_option( 'fl_ds_mcp_oauth_challenge_secret', '' );

		if ( empty( $challenge_secret ) ) {
			return null;
		}

		return hash_hmac( 'sha256', $challenge . ':' . $wp_user_id, $challenge_secret );
	}
}
