<?php

namespace FL\DesignSystem\Usage;

class TokenUsageTable {

	/**
	 * Get the full table name including the WordPress prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'fl_ds_token_usage';
	}

	/**
	 * Create or update the token usage table using dbDelta.
	 *
	 * Safe to call on every activation and upgrade — dbDelta only
	 * applies changes when the schema differs.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			provider varchar(32) NOT NULL DEFAULT 'anthropic',
			model varchar(64) NOT NULL,
			input_tokens int unsigned NOT NULL DEFAULT 0,
			output_tokens int unsigned NOT NULL DEFAULT 0,
			cost decimal(10,6) NOT NULL DEFAULT 0,
			context varchar(32) NOT NULL DEFAULT 'chat',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_created (user_id, created_at),
			KEY idx_model_created (model, created_at),
			KEY idx_created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log a token usage record.
	 *
	 * @param array $data {
	 *     @type int    $user_id       WordPress user ID.
	 *     @type string $provider      Provider name (e.g. 'anthropic').
	 *     @type string $model         Model ID.
	 *     @type int    $input_tokens  Number of input tokens.
	 *     @type int    $output_tokens Number of output tokens.
	 *     @type float  $cost          Calculated cost in USD.
	 *     @type string $context       Usage context (e.g. 'chat', 'page_generation').
	 * }
	 */
	public static function log( array $data ): void {
		global $wpdb;

		$wpdb->insert(
			self::table_name(),
			[
				'user_id'       => $data['user_id'] ?? 0,
				'provider'      => $data['provider'] ?? 'anthropic',
				'model'         => $data['model'],
				'input_tokens'  => $data['input_tokens'],
				'output_tokens' => $data['output_tokens'],
				'cost'          => $data['cost'],
				'context'       => $data['context'] ?? 'chat',
			],
			[ '%d', '%s', '%s', '%d', '%d', '%f', '%s' ]
		);
	}

	/**
	 * Get aggregated usage summary.
	 *
	 * @param array $args {
	 *     @type string $range   Time range ('1d', '7d', '30d', '90d'). Default '30d'.
	 *     @type int    $user_id Filter by WordPress user ID.
	 * }
	 * @return array {
	 *     @type array $total    Totals with input_tokens, output_tokens, cost.
	 *     @type array $by_model Per-model breakdown.
	 *     @type array $period   Start and end dates.
	 * }
	 */
	public static function get_summary( array $args = [] ): array {
		global $wpdb;

		$range   = $args['range'] ?? '30d';
		$user_id = $args['user_id'] ?? null;
		$days    = self::range_to_days( $range );
		$table   = self::table_name();

		$start = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$end   = gmdate( 'Y-m-d H:i:s' );

		$user_filter = '';
		$params      = [ $start ];

		if ( null !== $user_id ) {
			$user_filter = 'AND user_id = %d';
			$params[]    = (int) $user_id;
		}

		// Total aggregates.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(input_tokens), 0) AS input_tokens,
						COALESCE(SUM(output_tokens), 0) AS output_tokens,
						COALESCE(SUM(cost), 0) AS cost
				 FROM {$table}
				 WHERE created_at >= %s {$user_filter}",
				...$params
			),
			ARRAY_A
		);

		// Per-model breakdown.
		$by_model = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT model,
						COUNT(*) AS requests,
						COALESCE(SUM(input_tokens), 0) AS input_tokens,
						COALESCE(SUM(output_tokens), 0) AS output_tokens,
						COALESCE(SUM(cost), 0) AS cost
				 FROM {$table}
				 WHERE created_at >= %s {$user_filter}
				 GROUP BY model
				 ORDER BY cost DESC",
				...$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [
			'total'    => [
				'input_tokens'  => (int) ( $totals['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $totals['output_tokens'] ?? 0 ),
				'cost'          => (float) ( $totals['cost'] ?? 0 ),
			],
			'by_model' => $by_model ?: [],
			'period'   => [
				'start' => $start,
				'end'   => $end,
			],
		];
	}

	/**
	 * Get a user's all-time total token usage (input + output combined).
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Total tokens used.
	 */
	public static function get_user_total( int $user_id ): int {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(input_tokens + output_tokens), 0)
				 FROM {$table}
				 WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $total;
	}

	/**
	 * Get a user's token usage for the current calendar month.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return int Total tokens used this month.
	 */
	public static function get_user_monthly_total( int $user_id ): int {
		global $wpdb;

		$table       = self::table_name();
		$month_start = gmdate( 'Y-m-01 00:00:00' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(input_tokens + output_tokens), 0)
				 FROM {$table}
				 WHERE user_id = %d AND created_at >= %s",
				$user_id,
				$month_start
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $total;
	}

	/**
	 * Delete usage records older than the given number of days.
	 *
	 * @param int $days Number of days to retain. Default 90.
	 * @return int Number of rows deleted.
	 */
	public static function prune( int $days = 90 ): int {
		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Convert a range string to number of days.
	 *
	 * @param string $range Range string (e.g. '7d', '30d').
	 * @return int
	 */
	private static function range_to_days( string $range ): int {
		$map = [
			'1d'  => 1,
			'7d'  => 7,
			'30d' => 30,
			'90d' => 90,
		];
		return $map[ $range ] ?? 30;
	}
}
