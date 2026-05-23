<?php
/**
 * MCP OAuth provider.
 *
 * Handles Protected Resource Metadata (.well-known), WWW-Authenticate
 * headers on 401 responses, JWT validation, and user mapping for MCP
 * requests authenticated via the Beaver Builder AI OAuth flow.
 *
 * @package FL\DesignSystem\McpOAuth
 */

namespace FL\DesignSystem\McpOAuth\Providers;

use FL\DesignSystem\McpOAuth\Services\JwtValidator;
use FL\DesignSystem\McpOAuth\Services\ChallengeService;
use FL\DesignSystem\Updater\UpdateApiClient;

class McpOAuthProvider {

	/**
	 * Whether the last authenticate_jwt failure was due to an expired license.
	 *
	 * @var bool
	 */
	private bool $license_auth_failure = false;

	/**
	 * Boot the provider: register all hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		// Protected Resource Metadata via REST API fallback route.
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// .well-known/oauth-protected-resource via rewrite rule.
		add_action( 'init', [ $this, 'add_well_known_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'add_well_known_query_var' ] );
		add_action( 'template_redirect', [ $this, 'handle_well_known_request' ] );

		// JWT user mapping via determine_current_user.
		// Priority 25: runs after WordPress default auth (priority 20 in
		// wp-includes/default-filters.php) but doesn't override an
		// already-authenticated user.
		add_filter( 'determine_current_user', [ $this, 'authenticate_jwt' ], 25 );

		// WWW-Authenticate header on unauthenticated MCP requests.
		// Hooks into rest_pre_dispatch to intercept before the MCP Adapter
		// processes the request. Only fires for MCP namespace requests.
		add_filter( 'rest_pre_dispatch', [ $this, 'maybe_send_www_authenticate' ], 10, 3 );

		// Hidden WP Admin page for challenge-based user verification.
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );

		// One-shot cleanup of the legacy fl_ds_mcp_oauth_as_url row left
		// behind by installs that predate the M-10 hardening.
		add_action( 'init', [ $this, 'cleanup_legacy_as_url_option' ] );
	}

	/**
	 * One-shot removal of the legacy fl_ds_mcp_oauth_as_url wp_options row.
	 *
	 * The AS URL is now sourced from get_as_url() exclusively (audit finding
	 * M-10). Older installs may still have a value persisted from
	 * handle_register; this pass deletes it and self-disables via a flag so
	 * the cleanup runs at most once per site.
	 *
	 * @return void
	 */
	public function cleanup_legacy_as_url_option(): void {
		if ( get_option( 'fl_ds_mcp_oauth_as_url_cleaned', false ) ) {
			return;
		}

		delete_option( 'fl_ds_mcp_oauth_as_url' );
		update_option( 'fl_ds_mcp_oauth_as_url_cleaned', 1, false );
	}

	/**
	 * Register REST API routes.
	 *
	 * Includes the Protected Resource Metadata fallback route and the
	 * site registration, verification, and disconnect endpoints.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		// Protected Resource Metadata fallback (some hosts block dotfile paths).
		register_rest_route( 'fl-design-system/v1', '/mcp-oauth/protected-resource-metadata', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'serve_protected_resource_metadata' ],
			'permission_callback' => '__return_true',
		] );

		// Site registration -- connects this site to Beaver Builder AI.
		register_rest_route( 'fl-design-system/v1', '/mcp-oauth/register', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_register' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		// Site verification -- called by the AS to verify this site is real.
		register_rest_route( 'fl-design-system/v1', '/mcp-oauth/verify', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_verify' ],
			'permission_callback' => '__return_true',
		] );

		// Disconnect -- removes this site from Beaver Builder AI.
		register_rest_route( 'fl-design-system/v1', '/mcp-oauth/disconnect', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_disconnect' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	/**
	 * Add rewrite rule for .well-known/oauth-protected-resource.
	 *
	 * @return void
	 */
	public function add_well_known_rewrite(): void {
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource$',
			'index.php?fl_ds_well_known=oauth-protected-resource',
			'top'
		);
	}

	/**
	 * Register the custom query var for .well-known routing.
	 *
	 * @param array $vars Existing query vars.
	 *
	 * @return array Modified query vars.
	 */
	public function add_well_known_query_var( array $vars ): array {
		$vars[] = 'fl_ds_well_known';
		return $vars;
	}

	/**
	 * Handle .well-known/oauth-protected-resource requests.
	 *
	 * Intercepts the template redirect to serve the metadata JSON
	 * when the custom query var is present.
	 *
	 * @return void
	 */
	public function handle_well_known_request(): void {
		$well_known = get_query_var( 'fl_ds_well_known' );
		if ( 'oauth-protected-resource' !== $well_known ) {
			return;
		}

		if ( ! $this->is_connected() ) {
			status_header( 404 );
			nocache_headers();
			echo '{"error":"not_found"}';
			exit;
		}

		$metadata = $this->build_protected_resource_metadata();

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Serve Protected Resource Metadata via the REST API fallback route.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function serve_protected_resource_metadata( \WP_REST_Request $request ) {
		if ( ! $this->is_connected() ) {
			return new \WP_Error(
				'not_connected',
				'This site is not connected to Beaver Builder AI.',
				[ 'status' => 404 ]
			);
		}

		$metadata = $this->build_protected_resource_metadata();
		$response = new \WP_REST_Response( $metadata, 200 );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		return $response;
	}

	/**
	 * Build the Protected Resource Metadata payload.
	 *
	 * @return array
	 */
	private function build_protected_resource_metadata(): array {
		// Source of truth is get_as_url(). Reading the option directly here
		// would let a stale or attacker-controlled wp_options row redirect
		// the AS pointer (audit finding M-10).
		$as_url = $this->get_as_url();

		return [
			'resource'                 => $this->get_mcp_endpoint_url(),
			'authorization_servers'    => [ $as_url ],
			'scopes_supported'         => [ 'design' ],
			'bearer_methods_supported' => [ 'header' ],
		];
	}

	/**
	 * Intercept unauthenticated MCP requests and return 401 with
	 * WWW-Authenticate header when the site is connected.
	 *
	 * @param mixed            $result  Response to replace the requested one.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return mixed Unchanged $result or a WP_Error for 401.
	 */
	public function maybe_send_www_authenticate( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		// Only act on MCP namespace requests.
		if ( ! $this->is_mcp_request_by_route( $request->get_route() ) ) {
			return $result;
		}

		// Only act when the site is connected.
		if ( ! $this->is_connected() ) {
			return $result;
		}

		// If user is already authenticated, don't interfere.
		if ( get_current_user_id() > 0 ) {
			return $result;
		}

		// License-specific error: don't send WWW-Authenticate since re-auth won't help.
		if ( $this->license_auth_failure ) {
			return new \WP_Error(
				'mcp_oauth_license_expired',
				'Your Beaver Builder license is no longer active. Please renew your license to continue using BB AI.',
				[ 'status' => 401 ]
			);
		}

		// Send 401 with WWW-Authenticate header.
		$well_known_url = $this->get_well_known_url();

		// Set the header before returning the error response.
		header(
			sprintf(
				'WWW-Authenticate: Bearer resource_metadata="%s"',
				$well_known_url
			)
		);

		return new \WP_Error(
			'mcp_oauth_unauthorized',
			'Authentication required. Use OAuth 2.1 to obtain an access token.',
			[ 'status' => 401 ]
		);
	}

	/**
	 * Authenticate MCP requests using JWT Bearer tokens.
	 *
	 * Hooks into `determine_current_user` to map a valid JWT to a
	 * WordPress user. Only activates on MCP endpoint requests with
	 * a Bearer token present.
	 *
	 * @param int|false $user_id Current user ID (0 if not yet determined).
	 *
	 * @return int|false User ID from JWT or unchanged value.
	 */
	public function authenticate_jwt( $user_id ) {
		// Don't override an already-authenticated user.
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		// Only act on MCP endpoint requests.
		if ( ! $this->is_mcp_request() ) {
			return $user_id;
		}

		// Only act when the site is connected.
		if ( ! $this->is_connected() ) {
			return $user_id;
		}

		// Extract Bearer token from Authorization header.
		$token = $this->extract_bearer_token();
		if ( null === $token ) {
			// No Bearer token present -- don't interfere. Let existing auth
			// (Application Passwords) handle it.
			return $user_id;
		}

		// Validate the JWT.
		$validator = $this->create_jwt_validator();
		$payload   = $validator->validate( $token );

		if ( is_wp_error( $payload ) ) {
			// Invalid/expired JWT: return 0 (unauthenticated).
			// The 401 + WWW-Authenticate header from maybe_send_www_authenticate
			// will kick in for the response.
			return 0;
		}

		$wp_user_id = (int) $payload->wp_user_id;

		// Verify the WordPress user exists.
		$user = get_userdata( $wp_user_id );
		if ( false === $user ) {
			return 0;
		}

		// Verify the user has unfiltered_html capability.
		if ( ! user_can( $user, 'unfiltered_html' ) ) {
			return 0;
		}

		// Verify the site still has an active license.
		if ( ! $this->check_license_active() ) {
			$this->license_auth_failure = true;
			return 0;
		}

		return $wp_user_id;
	}

	/**
	 * Handle site registration with Beaver Builder AI.
	 *
	 * Sends site information to the Authorization Server and stores
	 * the returned credentials. Does not mark the site as connected
	 * until verification completes.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_register( \WP_REST_Request $request ) {
		if ( ! $this->check_license_active() ) {
			return new \WP_Error(
				'license_required',
				'An active Beaver Builder license is required to connect to BB AI.',
				[ 'status' => 403 ]
			);
		}

		$as_url = $this->get_as_url();

		$response = wp_remote_post( $as_url . '/api/sites/register', [
			'timeout' => 15,
			'headers' => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'    => wp_json_encode( [
				'site_url'     => $this->get_public_site_url(),
				'rest_url'     => $this->get_public_rest_url(),
				'mcp_endpoint' => $this->get_mcp_endpoint_url(),
				'licensed'     => true,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'registration_failed',
				'Could not reach Beaver Builder AI: ' . $response->get_error_message(),
				[ 'status' => 502 ]
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code < 200 || $status_code >= 300 || ! is_array( $body ) ) {
			$message = isset( $body['error'] ) ? $body['error'] : 'Registration failed (HTTP ' . $status_code . ')';
			return new \WP_Error(
				'registration_failed',
				$message,
				[ 'status' => $status_code >= 400 ? $status_code : 502 ]
			);
		}

		if ( empty( $body['site_id'] ) ) {
			return new \WP_Error(
				'registration_failed',
				'Invalid response from Beaver Builder AI.',
				[ 'status' => 502 ]
			);
		}

		// Store connection credentials. Note: fl_ds_mcp_oauth_as_url is no
		// longer persisted (audit finding M-10). All read paths now consult
		// get_as_url(), which is hardcoded and filterable for dev/staging
		// overrides. Persisting created a defense-in-depth gap because a stale
		// or attacker-controlled wp_options row could redirect the AS pointer.
		update_option( 'fl_ds_mcp_oauth_site_id', sanitize_text_field( $body['site_id'] ) );

		// The AS always returns a challenge_secret (rotated on re-registration).
		if ( ! empty( $body['challenge_secret'] ) ) {
			update_option( 'fl_ds_mcp_oauth_challenge_secret', sanitize_text_field( $body['challenge_secret'] ) );
		}

		// Store verify_token as a transient (10 min TTL) for the AS callback.
		if ( ! empty( $body['verify_token'] ) ) {
			set_transient( 'fl_ds_mcp_oauth_verify_token', sanitize_text_field( $body['verify_token'] ), 600 );
		}

		// Verify the site: ask the AS to call back and confirm this site is real.
		// In dev mode (FL_DS_MCP_OAUTH_SKIP_VERIFY), skip the callback and mark
		// as connected directly -- the AS can't reach local dev sites.
		if ( defined( 'FL_DS_MCP_OAUTH_SKIP_VERIFY' ) && FL_DS_MCP_OAUTH_SKIP_VERIFY
			&& ( 'local' === wp_get_environment_type() || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) ) {
			update_option( 'fl_ds_mcp_oauth_connected', true );
		} else {
			// Authenticate with HMAC signature using the challenge secret.
			$challenge_secret = ! empty( $body['challenge_secret'] )
				? $body['challenge_secret']
				: get_option( 'fl_ds_mcp_oauth_challenge_secret', '' );
			$verify_signature = hash_hmac( 'sha256', $body['site_id'], $challenge_secret );

			$verify_response = wp_remote_post( $as_url . '/api/sites/verify', [
				'timeout' => 15,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'HMAC-SHA256 ' . $verify_signature,
				],
				'body'    => wp_json_encode( [
					'site_id'      => $body['site_id'],
					'verify_token' => $body['verify_token'],
				] ),
			] );

			// Check the AS response directly. We can't rely on reading
			// fl_ds_mcp_oauth_connected back because handle_verify runs in
			// a separate PHP process and this request's object cache is stale.
			if ( is_wp_error( $verify_response ) ) {
				return new \WP_Error(
					'verification_failed',
					'Could not reach Beaver Builder AI for verification: ' . $verify_response->get_error_message(),
					[ 'status' => 502 ]
				);
			}

			$verify_status = wp_remote_retrieve_response_code( $verify_response );
			if ( $verify_status < 200 || $verify_status >= 300 ) {
				$verify_body  = json_decode( wp_remote_retrieve_body( $verify_response ), true );
				$verify_error = isset( $verify_body['error'] ) ? $verify_body['error'] : 'Verification failed (HTTP ' . $verify_status . ')';
				return new \WP_Error(
					'verification_failed',
					$verify_error,
					[ 'status' => 502 ]
				);
			}

			// AS confirmed verification succeeded — mark connected locally.
			// handle_verify already set this in its process, but the object
			// cache in this request may be stale, so set it again.
			update_option( 'fl_ds_mcp_oauth_connected', true );
		}

		return new \WP_REST_Response( [
			'success'   => true,
			'site_id'   => $body['site_id'],
			'connected' => true,
		], 200 );
	}

	/**
	 * Handle site verification callback from the Authorization Server.
	 *
	 * The AS calls this endpoint to confirm the site is real and reachable.
	 * On success, marks the site as connected.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_verify( \WP_REST_Request $request ) {
		$token = sanitize_text_field( $request->get_param( 'token' ) );

		if ( empty( $token ) ) {
			return new \WP_Error(
				'missing_token',
				'Verification token is required.',
				[ 'status' => 400 ]
			);
		}

		$stored_token = get_transient( 'fl_ds_mcp_oauth_verify_token' );

		if ( false === $stored_token || ! hash_equals( $stored_token, $token ) ) {
			return new \WP_Error(
				'invalid_token',
				'Verification token is invalid or expired.',
				[ 'status' => 403 ]
			);
		}

		// Verification succeeded -- mark site as connected.
		update_option( 'fl_ds_mcp_oauth_connected', true );
		delete_transient( 'fl_ds_mcp_oauth_verify_token' );

		$plugin_data = get_plugin_data( \FL_DESIGN_SYSTEM_FILE, false, false );

		return new \WP_REST_Response( [
			'verified'       => true,
			'plugin_version' => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '0.0.0',
		], 200 );
	}

	/**
	 * Handle site disconnection from Beaver Builder AI.
	 *
	 * Notifies the AS, then cleans up all local connection data regardless
	 * of whether the AS call succeeds.
	 *
	 * @param \WP_REST_Request $request REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_disconnect( \WP_REST_Request $request ) {
		$site_id = get_option( 'fl_ds_mcp_oauth_site_id', '' );
		// Source of truth is get_as_url() (audit finding M-10). The option is
		// no longer persisted; the cleanup_legacy_as_url_option() pass on init
		// removes any stale row left behind by older installs.
		$as_url           = $this->get_as_url();
		$challenge_secret = get_option( 'fl_ds_mcp_oauth_challenge_secret', '' );

		// Notify the AS. If this fails, still clean up locally.
		if ( ! empty( $site_id ) && ! empty( $as_url ) ) {
			$headers = [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			];

			// Authenticate with HMAC signature if we have the challenge secret.
			if ( ! empty( $challenge_secret ) ) {
				$signature                = hash_hmac( 'sha256', $site_id, $challenge_secret );
				$headers['Authorization'] = 'HMAC-SHA256 ' . $signature;
			}

			wp_remote_post( $as_url . '/api/sites/deregister', [
				'timeout' => 10,
				'headers' => $headers,
				'body'    => wp_json_encode( [
					'site_id' => $site_id,
				] ),
			] );
		}

		// Clean up all connection data.
		delete_option( 'fl_ds_mcp_oauth_site_id' );
		delete_option( 'fl_ds_mcp_oauth_challenge_secret' );
		delete_option( 'fl_ds_mcp_oauth_as_url' );
		update_option( 'fl_ds_mcp_oauth_connected', false );
		delete_transient( 'fl_ds_mcp_oauth_jwks' );
		delete_transient( 'fl_ds_mcp_oauth_jwks_cooldown' );

		return new \WP_REST_Response( [
			'success' => true,
		], 200 );
	}

	/**
	 * Register the hidden WP Admin page for challenge-based auth.
	 *
	 * Uses add_submenu_page with a null parent so it does not appear
	 * in the admin menu. The AS redirects users here during OAuth to
	 * verify their WordPress identity.
	 *
	 * @return void
	 */
	public function register_admin_page(): void {
		$hook = add_submenu_page(
			'',
			'MCP Authentication',
			'MCP Authentication',
			'unfiltered_html',
			'fl-design-system-mcp-auth',
			[ $this, 'render_admin_challenge_page' ]
		);

		// Handle the challenge redirect before WordPress outputs any HTML.
		// The page callback is only reached for error states (wp_die).
		if ( $hook ) {
			add_action( 'load-' . $hook, [ $this, 'handle_challenge_redirect' ] );
		}
	}

	/**
	 * Handle the challenge-based redirect before output starts.
	 *
	 * Runs on the load-{page} hook so headers can still be sent.
	 * Signs the challenge and redirects back to the AS. Falls through
	 * to render_admin_challenge_page for error states.
	 *
	 * @return void
	 */
	public function handle_challenge_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect-based challenge flow initiated by the AS, not a form submission.
		$challenge = isset( $_GET['challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['challenge'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$callback_url = isset( $_GET['callback_url'] ) ? esc_url_raw( wp_unslash( $_GET['callback_url'] ) ) : '';

		if ( empty( $challenge ) || empty( $callback_url ) ) {
			return; // Fall through to render_admin_challenge_page for error display.
		}

		// Validate callback_url points to the configured Authorization Server.
		// Source of truth is get_as_url() (audit finding M-10).
		$as_url = $this->get_as_url();
		if ( empty( $as_url ) || 0 !== strpos( $callback_url, $as_url ) ) {
			return; // Fall through to render_admin_challenge_page for error display.
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return; // Fall through for error display.
		}

		$challenge_service = new ChallengeService();
		$wp_user_id        = get_current_user_id();
		$signature         = $challenge_service->sign( $challenge, $wp_user_id );

		if ( null === $signature ) {
			return; // Fall through for error display.
		}

		$current_user = wp_get_current_user();

		$redirect_url = add_query_arg( [
			'challenge'   => rawurlencode( $challenge ),
			'signature'   => rawurlencode( $signature ),
			'wp_user_id'  => $wp_user_id,
			'wp_username' => rawurlencode( $current_user->display_name ),
		], $callback_url );

		wp_redirect( $redirect_url );
		exit;
	}

	/**
	 * Render the challenge-based auth page.
	 *
	 * Only reached for error states -- the happy path redirects in
	 * handle_challenge_redirect before any output.
	 *
	 * @return void
	 */
	public function render_admin_challenge_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verification is not applicable here; this is a redirect-based challenge flow initiated by the AS.
		$challenge = isset( $_GET['challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['challenge'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$callback_url = isset( $_GET['callback_url'] ) ? esc_url_raw( wp_unslash( $_GET['callback_url'] ) ) : '';

		if ( empty( $challenge ) || empty( $callback_url ) ) {
			wp_die(
				esc_html__( 'Invalid authentication request. Missing challenge or callback URL.', 'fl-design-system' ),
				esc_html__( 'Authentication Error', 'fl-design-system' ),
				[ 'response' => 400 ]
			);
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to authorize MCP connections. An account with editing permissions is required.', 'fl-design-system' ),
				esc_html__( 'Insufficient Permissions', 'fl-design-system' ),
				[ 'response' => 403 ]
			);
			return;
		}

		// If we reached here, the challenge signing failed.
		wp_die(
			esc_html__( 'This site is not configured for Beaver Builder AI authentication. Please connect your site first.', 'fl-design-system' ),
			esc_html__( 'Configuration Error', 'fl-design-system' ),
			[ 'response' => 500 ]
		);
	}

	/**
	 * Check whether the site has an active Beaver Builder license.
	 *
	 * Uses a transient cache to avoid hitting the update API on every call.
	 *
	 * @return bool
	 */
	private function check_license_active(): bool {
		if ( ! isset( $GLOBALS['fl_ds_license_provider'] ) ) {
			return false;
		}

		$license_provider = $GLOBALS['fl_ds_license_provider'];

		if ( ! $license_provider->is_licensed() ) {
			return false;
		}

		$cached = get_transient( 'fl_ds_mcp_license_status' );
		if ( false !== $cached ) {
			return 'active' === $cached;
		}

		$client = new UpdateApiClient();
		$info   = $client->get_subscription_info(
			$license_provider->get_license_key(),
			home_url()
		);

		$is_active = ! empty( $info->active );
		set_transient( 'fl_ds_mcp_license_status', $is_active ? 'active' : 'inactive', HOUR_IN_SECONDS );

		return $is_active;
	}

	/**
	 * Get the Authorization Server URL.
	 *
	 * Canonical source of the AS URL. All call sites that previously consulted
	 * the fl_ds_mcp_oauth_as_url wp_options row (JWT validation, callback
	 * allowlisting, protected-resource metadata, disconnect) MUST go through
	 * this method. Reading from wp_options directly is a defense-in-depth gap
	 * because a stale or attacker-controlled row could redirect the AS pointer
	 * to a hostile origin (audit finding M-10).
	 *
	 * Filterable via fl_ds_mcp_oauth_as_url for dev/staging overrides.
	 *
	 * @return string
	 */
	private function get_as_url(): string {
		/**
		 * Filter the Authorization Server URL.
		 *
		 * @param string $as_url The Authorization Server URL.
		 */
		return apply_filters( 'fl_ds_mcp_oauth_as_url', 'https://bb-mcp-connect.info-288.workers.dev' );
	}

	/**
	 * Create a JwtValidator instance with the current connection settings.
	 *
	 * @return JwtValidator
	 */
	private function create_jwt_validator(): JwtValidator {
		// Source of truth is get_as_url(). Pulling from wp_options would let a
		// stale or compromised row redirect JWT validation to a hostile issuer
		// (audit finding M-10).
		$as_url   = $this->get_as_url();
		$audience = $this->get_mcp_endpoint_url();

		return new JwtValidator( $as_url, $audience );
	}

	/**
	 * Extract the Bearer token from the Authorization header.
	 *
	 * @return string|null The token or null if not present.
	 */
	private function extract_bearer_token(): ?string {
		$auth_header = $this->get_authorization_header();
		if ( null === $auth_header ) {
			return null;
		}

		if ( 0 !== strpos( $auth_header, 'Bearer ' ) ) {
			return null;
		}

		$token = substr( $auth_header, 7 );
		if ( empty( $token ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Get the Authorization header from the current request.
	 *
	 * Checks multiple sources because different server configurations
	 * expose the header differently.
	 *
	 * @return string|null The header value or null.
	 */
	private function get_authorization_header(): ?string {
		// Apache with mod_rewrite may strip the header.
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		// Apache with mod_setenvif.
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			if ( is_array( $headers ) ) {
				// Header names are case-insensitive per HTTP spec.
				foreach ( $headers as $name => $value ) {
					if ( 'authorization' === strtolower( $name ) ) {
						return sanitize_text_field( $value );
					}
				}
			}
		}

		return null;
	}

	/**
	 * Check if the current request targets the MCP endpoint.
	 *
	 * @return bool
	 */
	private function is_mcp_request(): bool {
		// Check the REQUEST_URI for the MCP path.
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		return false !== strpos( $request_uri, '/wp-json/mcp/' )
			|| false !== strpos( $request_uri, '/?rest_route=/mcp/' );
	}

	/**
	 * Check if a REST route is an MCP route.
	 *
	 * @param string $route The REST route path.
	 *
	 * @return bool
	 */
	private function is_mcp_request_by_route( string $route ): bool {
		return 0 === strpos( $route, '/mcp/' );
	}

	/**
	 * Check if the site is connected to Beaver Builder AI.
	 *
	 * @return bool
	 */
	private function is_connected(): bool {
		return (bool) get_option( 'fl_ds_mcp_oauth_connected', false );
	}

	/**
	 * Get the public-facing site URL.
	 *
	 * Uses FL_DS_MCP_OAUTH_SITE_URL constant if defined (for local dev
	 * with tunnels), otherwise falls back to site_url().
	 *
	 * @return string
	 */
	private function get_public_site_url(): string {
		if ( defined( 'FL_DS_MCP_OAUTH_SITE_URL' ) ) {
			return rtrim( FL_DS_MCP_OAUTH_SITE_URL, '/' );
		}
		return $this->ensure_https( site_url() );
	}

	/**
	 * Get the public-facing REST API URL.
	 *
	 * @param string $path Optional path to append.
	 *
	 * @return string
	 */
	private function get_public_rest_url( string $path = '' ): string {
		if ( defined( 'FL_DS_MCP_OAUTH_SITE_URL' ) ) {
			return rtrim( FL_DS_MCP_OAUTH_SITE_URL, '/' ) . '/wp-json/' . ltrim( $path, '/' );
		}

		// Build the URL manually instead of via rest_url(). rest_url() calls
		// $wp_rewrite->using_index_permalinks(), but $wp_rewrite is null
		// until 'init'. authenticate_jwt() can run earlier than that:
		// bb-plugin loads its textdomain on plugins_loaded, which calls
		// get_user_locale() -> wp_get_current_user() -> the
		// determine_current_user filter, where this code runs.
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		$url    = home_url( '/' . $prefix . '/' . ltrim( $path, '/' ) );

		// Preserve compatibility with sites that customize REST URLs via
		// the rest_url filter (e.g. a different REST host).
		$url = apply_filters( 'rest_url', $url, $path, null, 'rest' );

		return $this->ensure_https( $url );
	}

	/**
	 * Upgrade a URL to HTTPS.
	 *
	 * Many WordPress sites serve over HTTPS but store http:// in the
	 * database, especially behind reverse proxies, CDNs, or load
	 * balancers that terminate SSL. Since the AS requires HTTPS and
	 * any site using this feature must be publicly accessible over
	 * HTTPS, always upgrade.
	 *
	 * @param string $url The URL to check.
	 *
	 * @return string The URL with https://.
	 */
	private function ensure_https( string $url ): string {
		if ( 0 === strpos( $url, 'http://' ) ) {
			return 'https://' . substr( $url, 7 );
		}
		return $url;
	}

	/**
	 * Get the .well-known/oauth-protected-resource URL for this site.
	 *
	 * @return string
	 */
	private function get_well_known_url(): string {
		return $this->get_public_site_url() . '/.well-known/oauth-protected-resource';
	}

	/**
	 * Get the MCP endpoint URL for this site.
	 *
	 * Uses the MCP Adapter's default server endpoint.
	 *
	 * @return string
	 */
	private function get_mcp_endpoint_url(): string {
		return $this->get_public_rest_url( 'mcp/mcp-adapter-default-server' );
	}
}
