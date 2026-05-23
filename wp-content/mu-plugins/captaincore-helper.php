<?php
/**
 * Plugin Name: CaptainCore Helper
 * Plugin URI: https://captaincore.io
 * Description: Collection of helper functions for CaptainCore
 * Version: 0.3.0
 * Author: CaptainCore
 * Author URI: https://captaincore.io
 * Text Domain: captaincore-helper
 */

/**
 * Registers AJAX callback for quick logins
 */
function captaincore_quick_login_action_callback() {

	$post = json_decode( file_get_contents( 'php://input' ) );
	// Error if token not valid
	if ( ! isset( $post->token ) || $post->token != md5( AUTH_KEY ) ) {
		return new WP_Error( 'token_invalid', 'Invalid Token', [ 'status' => 404 ] );
		wp_die();
	}

	$post->user_login = str_replace( "%20", " ", $post->user_login );
	$user     = get_user_by( 'login', $post->user_login );
	$password = wp_generate_password();
	$token    = sha1( $password );

	update_user_meta( $user->ID, 'captaincore_login_token', $token );
	$query_args = [
			'user_id'                 => $user->ID,
			'captaincore_login_token' => $token,
		];
	$login_url    = wp_login_url();
		$one_time_url = add_query_arg( $query_args, $login_url );

	echo $one_time_url;
	wp_die();

}

add_action( 'wp_ajax_nopriv_captaincore_quick_login', 'captaincore_quick_login_action_callback' );
/**
 * Login a request in as a user if the token is valid.
 */
function captaincore_login_handle_token() {

	global $pagenow;
	if ( 'wp-login.php' !== $pagenow || empty( $_GET['user_id'] ) || empty( $_GET['captaincore_login_token'] ) ) {
		return;
	}

	if ( is_user_logged_in() ) {
		$error = sprintf( __( 'Invalid one-time login token, but you are logged in as \'%1$s\'. <a href="%2$s">Go to the dashboard instead</a>?', 'captaincore-login' ), wp_get_current_user()->user_login, admin_url() );
	} else {
		$error = sprintf( __( 'Invalid one-time login token. <a href="%s">Try signing in instead</a>?', 'captaincore-login' ), wp_login_url() );
	}

	// Use a generic error message to ensure user ids can't be sniffed
	$user = get_user_by( 'id', (int) $_GET['user_id'] );
	if ( ! $user ) {
		wp_die( $error );
	}

	$token    = get_user_meta( $user->ID, 'captaincore_login_token', true );
	$is_valid = false;
		if ( hash_equals( $token, $_GET['captaincore_login_token'] ) ) {
			$is_valid = true;
		}

	if ( ! $is_valid ) {
		wp_die( $error );
	}

	delete_user_meta( $user->ID, 'captaincore_login_token' );
	wp_set_auth_cookie( $user->ID, 1 );
	wp_safe_redirect( admin_url() );
	exit;
}

add_action( 'init', 'captaincore_login_handle_token' );

if (defined('WP_CLI') && WP_CLI) {

    /**
     * Generates a one-time login link for a user based on user ID, email, or login.
     *
     * ## OPTIONS
     *
     * <user_identifier>
     * : The user ID, email, or login of the user to generate the login link for.
     *
     * ## EXAMPLES
     *
     * wp user login 123
     * wp user login user@example.com
     * wp user login myusername
     *
     * @param array $args The command arguments.
     */
    function captaincore_generate_login_link( $args ) {

        $user_identifier = $args[0];
        // Determine if the identifier is a user ID, email, or login
        if (is_numeric($user_identifier)) {
            $user = get_user_by('ID', $user_identifier);
        } elseif (is_email($user_identifier)) {
            $user = get_user_by('email', $user_identifier);
        } else {
            $user = get_user_by('login', $user_identifier);
        }

        // Check if the user exists
        if (!$user) {
            WP_CLI::error("User not found: $user_identifier");
            return;
        }

        // Generate tokens
        $password = wp_generate_password();
        $token    = sha1($password);

        // Update user meta with the new token
        update_user_meta( $user->ID, 'captaincore_login_token', $token );
        // Construct the one-time login URL
        $query_args = [
            'user_id'                 => $user->ID,
            'captaincore_login_token' => $token,
        ];
        $login_url    = wp_login_url();
        $one_time_url = add_query_arg($query_args, $login_url);
        // Output the URL to the CLI
        WP_CLI::log("$one_time_url");
    }

    WP_CLI::add_command( 'user login', 'captaincore_generate_login_link' );
}

/**
 * Disable auto-update email notifications for plugins.
 */
add_filter( 'auto_plugin_update_send_email', '__return_false' );

/**
 * Disable auto-update email notifications for themes.
 */
add_filter( 'auto_theme_update_send_email', '__return_false' );

/**
 * Dynamic URL override for Tailscale/LAN/Share access.
 * When accessed via a non-localhost domain, override home and siteurl
 * to use the current host so CSS/JS/images load correctly.
 */
function cove_maybe_override_site_url( $value ) {
    // Only run in front-end context with a valid HTTP_HOST
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        return $value;
    }
    
    $host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
    
    // Skip if no host or if it ends with .localhost (normal local access)
    if ( empty( $host ) || preg_match( '/\.localhost(:\d+)?$/', $host ) ) {
        return $value;
    }
    
    // Override to current host for Tailscale, LAN, or public share access
    $scheme = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
    return $scheme . '://' . $host;
}
add_filter( 'option_home', 'cove_maybe_override_site_url' );
add_filter( 'option_siteurl', 'cove_maybe_override_site_url' );
