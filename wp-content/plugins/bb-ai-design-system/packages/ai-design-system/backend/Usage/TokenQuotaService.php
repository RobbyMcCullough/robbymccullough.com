<?php

namespace FL\DesignSystem\Usage;

use FL\DesignSystem\Generation\ThrottleTable;

class TokenQuotaService {

	const TESTER_LIMIT = 1_500_000;

	const EDITOR_MONTHLY_LIMIT = 10_000_000;

	const DISPLAY_DIVISOR = 1000;

	/**
	 * Legacy alias for backward compatibility.
	 */
	const TOKEN_LIMIT = self::TESTER_LIMIT;

	public function boot() {
		add_filter( 'fl_ds_can_generate', [ $this, 'check_quota' ], 10, 2 );
	}

	/**
	 * Block generation for users who have exceeded their quota.
	 *
	 * Admins: unlimited. Editors: monthly cap. Testers: lifetime cap.
	 *
	 * M-15: counts active token reservations alongside consumed tokens
	 * so two concurrent /generate POSTs cannot each pass the quota
	 * gate when only one fits within the remaining budget. The
	 * reservation table is opportunistically swept on every check so
	 * orphaned reservations from worker crashes do not lock users out.
	 *
	 * @param bool|WP_Error $can_generate Current filter value.
	 * @param int           $user_id      WordPress user ID.
	 * @return bool|\WP_Error
	 */
	public function check_quota( $can_generate, int $user_id ) {
		if ( is_wp_error( $can_generate ) ) {
			return $can_generate;
		}

		// Admins have no quota.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return $can_generate;
		}

		// Sweep expired reservations before counting. Cheap (indexed
		// keyed by `(scope, expires_at)`) and the only mechanism that
		// runs on every quota check, so orphans never live past their
		// TTL plus the time to the next call.
		ThrottleTable::prune_expired();

		$reserved = ThrottleTable::sum_active_reservations( (int) $user_id );

		// Editors get a monthly quota.
		if ( user_can( $user_id, 'edit_others_design_systems' ) ) {
			$monthly = TokenUsageTable::get_user_monthly_total( $user_id );

			if ( ( $monthly + $reserved ) >= self::EDITOR_MONTHLY_LIMIT ) {
				return new \WP_Error(
					'quota_exceeded',
					__( 'You have used all of your credits for this month.', 'fl-design-system' ),
					[ 'status' => 403 ]
				);
			}

			return $can_generate;
		}

		// Everyone else (testers) gets a lifetime quota.
		$total = TokenUsageTable::get_user_total( $user_id );

		if ( ( $total + $reserved ) >= self::TESTER_LIMIT ) {
			return new \WP_Error(
				'quota_exceeded',
				__( 'You have used all of your testing credits.', 'fl-design-system' ),
				[ 'status' => 403 ]
			);
		}

		return $can_generate;
	}
}
