<?php

namespace FL\DesignSystem\Usage;

use FL\DesignSystem\Contracts\AuthInterface;

class UsageProvider {

	private AuthInterface $auth;

	public function __construct( AuthInterface $auth ) {
		$this->auth = $auth;
	}

	public function boot() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( 'fl-design-system/v1', '/usage', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_get' ],
			'permission_callback' => [ $this->auth, 'admin_permission_callback' ],
		] );

		register_rest_route( 'fl-design-system/v1', '/my-credits', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle_my_credits' ],
			'permission_callback' => function() {
				return is_user_logged_in();
			},
		] );
	}

	/**
	 * Returns aggregated token usage data.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function handle_get( \WP_REST_Request $request ) {
		$range = $request->get_param( 'range' ) ?: '30d';

		$allowed_ranges = [ '1d', '7d', '30d', '90d' ];
		if ( ! in_array( $range, $allowed_ranges, true ) ) {
			$range = '30d';
		}

		$args = [ 'range' => $range ];

		$user_id = $request->get_param( 'user_id' );
		if ( $user_id ) {
			$args['user_id'] = (int) $user_id;
		}

		$summary = TokenUsageTable::get_summary( $args );

		return new \WP_REST_Response( $summary, 200 );
	}

	/**
	 * Returns the current user's credit usage.
	 *
	 * Three tiers:
	 * - admin: unlimited, shows all-time usage
	 * - editor: monthly cap (10K displayed / 10M tokens)
	 * - tester: lifetime cap (1,500 displayed / 1.5M tokens)
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_my_credits() {
		$user_id  = get_current_user_id();
		$is_admin = user_can( $user_id, 'manage_options' );
		$is_editor = user_can( $user_id, 'edit_others_design_systems' );
		$divisor  = TokenQuotaService::DISPLAY_DIVISOR;

		if ( $is_admin ) {
			$used = TokenUsageTable::get_user_total( $user_id );

			return new \WP_REST_Response( [
				'tier'          => 'admin',
				'used'          => $used,
				'limit'         => null,
				'display_used'  => (int) round( $used / $divisor ),
				'display_limit' => null,
				'is_limited'    => false,
			], 200 );
		}

		if ( $is_editor ) {
			$used  = TokenUsageTable::get_user_monthly_total( $user_id );
			$limit = TokenQuotaService::EDITOR_MONTHLY_LIMIT;

			return new \WP_REST_Response( [
				'tier'          => 'editor',
				'used'          => $used,
				'limit'         => $limit,
				'display_used'  => (int) round( $used / $divisor ),
				'display_limit' => (int) round( $limit / $divisor ),
				'is_limited'    => true,
				'resets_at'     => gmdate( 'Y-m-01 00:00:00', strtotime( 'first day of next month' ) ),
			], 200 );
		}

		$used  = TokenUsageTable::get_user_total( $user_id );
		$limit = TokenQuotaService::TESTER_LIMIT;

		return new \WP_REST_Response( [
			'tier'          => 'tester',
			'used'          => $used,
			'limit'         => $limit,
			'display_used'  => (int) round( $used / $divisor ),
			'display_limit' => (int) round( $limit / $divisor ),
			'is_limited'    => true,
		], 200 );
	}
}
