<?php

namespace FL\DesignSystem\Generation;

/**
 * Atomic per-user throttle counters and token reservations (M-15).
 *
 * Replaces the previous transient-based bookkeeping in
 * {@see \FL\DesignSystem\Generation\GenerationJobProvider} with SQL-level
 * atomicity, closing three TOCTOU races where a burst of parallel
 * `/generate` POSTs could each read the counter before any of them wrote
 * the increment back.
 *
 * Three counter types live in this single table:
 *
 *   - `rate_limit`: per-user requests-per-window. Row keyed by
 *     `(user_id, scope='rate_limit', window_start)`.
 *   - `concurrency`: per-user active job count. Row keyed by
 *     `(user_id, scope='concurrency', window_start=0)`. One row per user.
 *   - `token_reservation`: per-user pending generation token cost. Row
 *     keyed by `(user_id, scope='token_reservation', window_start=<job_id>)`
 *     so multiple concurrent jobs each get their own row. The `data`
 *     column holds the job_id so the job can release the row on
 *     completion.
 *
 * The atomicity guarantee comes from MySQL row-level locking on
 * `INSERT IGNORE` plus `UPDATE ... WHERE count < limit`: the engine
 * serializes concurrent writers per row, so two parallel incrementers
 * cannot both observe the pre-increment value.
 *
 * Token reservations carry a 5-minute TTL (3x the typical generation
 * window) as a safety net for worker crashes or fatal errors. Explicit
 * release on the success / cancel / error paths is the primary
 * mechanism; TTL is the fallback. {@see prune_expired} is called
 * opportunistically on every check so no orphaned reservation lives
 * past `expires_at + (time-to-next-check)`.
 */
class ThrottleTable {

	public const SCOPE_RATE_LIMIT        = 'rate_limit';
	public const SCOPE_CONCURRENCY       = 'concurrency';
	public const SCOPE_TOKEN_RESERVATION = 'token_reservation';

	/**
	 * Token reservation TTL in seconds (5 minutes). Tightened from 10
	 * minutes per the security-audit plan: shorter TTL gets chat users
	 * unblocked faster after a worker crash, while still covering the
	 * worst-case "model slowdown on a large prompt" outlier.
	 */
	public const RESERVATION_TTL = 300;

	/**
	 * Get the full table name including the WordPress prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'fl_ds_throttle';
	}

	/**
	 * Create or update the throttle table via dbDelta.
	 *
	 * Safe on every activation/upgrade.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// `count` is the integer counter (rate_limit / concurrency / reserved tokens).
		// `data` is an opaque short string (e.g. job_id) for reservations.
		// `window_key` is the discriminator within scope. For rate_limit it's
		// the window-start unix timestamp; for concurrency it's '0'; for
		// token_reservation it's the job_id (truncated to 64 chars).
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			scope varchar(32) NOT NULL,
			window_key varchar(64) NOT NULL DEFAULT '0',
			count int unsigned NOT NULL DEFAULT 0,
			data varchar(255) NOT NULL DEFAULT '',
			expires_at datetime NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_scope_window (user_id, scope, window_key),
			KEY scope_expires (scope, expires_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Atomically increment the rate-limit counter for `(user_id, window)`
	 * and return whether the post-increment count is within `$max`.
	 *
	 * Strategy: INSERT IGNORE the row if absent, then UPDATE with a
	 * `WHERE count < :max` predicate. Affected-row count tells us
	 * whether the increment landed.
	 *
	 * @param int $user_id
	 * @param int $window  Window length in seconds.
	 * @param int $max     Max allowed within window.
	 * @return bool True when the request is within budget.
	 */
	public static function consume_rate_limit_slot( int $user_id, int $window, int $max ): bool {
		global $wpdb;

		$table        = self::table_name();
		$window_start = (int) ( time() - ( time() % $window ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				  (user_id, scope, window_key, count)
				 VALUES (%d, %s, %s, 1)",
				$user_id,
				self::SCOPE_RATE_LIMIT,
				(string) $window_start
			)
		);

		if ( (int) $wpdb->insert_id > 0 ) {
			return true;
		}

		$rows = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET count = count + 1
				 WHERE user_id = %d
				   AND scope = %s
				   AND window_key = %s
				   AND count < %d",
				$user_id,
				self::SCOPE_RATE_LIMIT,
				(string) $window_start,
				$max
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows > 0;
	}

	/**
	 * Read the current rate-limit count for `(user_id, current window)`.
	 *
	 * @param int $user_id
	 * @param int $window
	 * @return int
	 */
	public static function current_rate_limit_count( int $user_id, int $window ): int {
		global $wpdb;

		$table        = self::table_name();
		$window_start = (int) ( time() - ( time() % $window ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT count FROM {$table}
				 WHERE user_id = %d AND scope = %s AND window_key = %s",
				$user_id,
				self::SCOPE_RATE_LIMIT,
				(string) $window_start
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/**
	 * Atomically reserve a concurrency slot. Returns true when the user's
	 * active count was below `$max` at the moment of increment.
	 *
	 * @param int $user_id
	 * @param int $max
	 * @return bool
	 */
	public static function consume_concurrency_slot( int $user_id, int $max ): bool {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				  (user_id, scope, window_key, count)
				 VALUES (%d, %s, '0', 1)",
				$user_id,
				self::SCOPE_CONCURRENCY
			)
		);

		if ( (int) $wpdb->insert_id > 0 ) {
			return true;
		}

		$rows = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET count = count + 1
				 WHERE user_id = %d
				   AND scope = %s
				   AND window_key = '0'
				   AND count < %d",
				$user_id,
				self::SCOPE_CONCURRENCY,
				$max
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $rows > 0;
	}

	/**
	 * Release one concurrency slot. Idempotent at the row level: the
	 * `count > 0` predicate prevents underflow.
	 *
	 * @param int $user_id
	 */
	public static function release_concurrency_slot( int $user_id ): void {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET count = count - 1
				 WHERE user_id = %d
				   AND scope = %s
				   AND window_key = '0'
				   AND count > 0",
				$user_id,
				self::SCOPE_CONCURRENCY
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Read the current concurrency count.
	 *
	 * @param int $user_id
	 * @return int
	 */
	public static function current_concurrency_count( int $user_id ): int {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT count FROM {$table}
				 WHERE user_id = %d AND scope = %s AND window_key = '0'",
				$user_id,
				self::SCOPE_CONCURRENCY
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $count;
	}

	/**
	 * Sum a user's outstanding token reservations (rows whose
	 * expires_at is still in the future).
	 *
	 * @param int $user_id
	 * @return int
	 */
	public static function sum_active_reservations( int $user_id ): int {
		global $wpdb;

		$table = self::table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(count), 0) FROM {$table}
				 WHERE user_id = %d
				   AND scope = %s
				   AND (expires_at IS NULL OR expires_at > %s)",
				$user_id,
				self::SCOPE_TOKEN_RESERVATION,
				$now
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $total;
	}

	/**
	 * Reserve `$tokens` for `(user_id, job_id)` with a TTL. The row is
	 * deleted by `release_reservation` on completion or by `prune_expired`
	 * after the TTL.
	 *
	 * @param int    $user_id
	 * @param string $job_id
	 * @param int    $tokens
	 * @return bool
	 */
	public static function record_reservation( int $user_id, string $job_id, int $tokens ): bool {
		global $wpdb;

		$expires = gmdate( 'Y-m-d H:i:s', time() + self::RESERVATION_TTL );

		return false !== $wpdb->insert(
			self::table_name(),
			[
				'user_id'    => $user_id,
				'scope'      => self::SCOPE_TOKEN_RESERVATION,
				'window_key' => substr( $job_id, 0, 64 ),
				'count'      => $tokens,
				'data'       => $job_id,
				'expires_at' => $expires,
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Release a token reservation by job_id.
	 *
	 * @param int    $user_id
	 * @param string $job_id
	 */
	public static function release_reservation( int $user_id, string $job_id ): void {
		global $wpdb;

		$wpdb->delete(
			self::table_name(),
			[
				'user_id'    => $user_id,
				'scope'      => self::SCOPE_TOKEN_RESERVATION,
				'window_key' => substr( $job_id, 0, 64 ),
			],
			[ '%d', '%s', '%s' ]
		);
	}

	/**
	 * Sweep reservations that have outlived their TTL plus older
	 * rate-limit window rows. Cheap (keyed by `(scope, expires_at)`
	 * index) and called opportunistically on every `check_quota`.
	 */
	public static function prune_expired(): void {
		global $wpdb;

		$table = self::table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE scope = %s
				   AND expires_at IS NOT NULL
				   AND expires_at < %s",
				self::SCOPE_TOKEN_RESERVATION,
				$now
			)
		);

		$window_cutoff = (int) ( time() - 600 );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				 WHERE scope = %s
				   AND CAST(window_key AS UNSIGNED) < %d",
				self::SCOPE_RATE_LIMIT,
				$window_cutoff
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Migrate in-flight transient state into the SQL table on activation.
	 *
	 * Why: at deploy time, jobs whose increments landed in transients
	 * before the cutover would not find their slot in the SQL table when
	 * `release_slot` runs, leaking the user's concurrency cap until the
	 * 15-minute transient TTL expires. The activation walk reads
	 * `fl_ds_active_jobs_<user_id>` transients and copies the active
	 * count into a concurrency row, then deletes the transients so the
	 * old code path cannot accidentally re-use them.
	 *
	 * Idempotent: re-running the migration when the transients are
	 * already gone is a no-op.
	 */
	public static function migrate_transients(): void {
		global $wpdb;

		$prefix = $wpdb->esc_like( '_transient_fl_ds_active_jobs_' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE option_name LIKE %s",
				$prefix . '%'
			)
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		$table = self::table_name();

		foreach ( $rows as $row ) {
			$user_id = (int) substr( $row->option_name, strlen( '_transient_fl_ds_active_jobs_' ) );
			if ( $user_id <= 0 ) {
				continue;
			}

			$ids = maybe_unserialize( $row->option_value );
			if ( ! is_array( $ids ) ) {
				$ids = [];
			}

			$count = count( $ids );
			if ( $count > 0 ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$table}
						  (user_id, scope, window_key, count)
						 VALUES (%d, %s, '0', %d)
						 ON DUPLICATE KEY UPDATE count = VALUES(count)",
						$user_id,
						self::SCOPE_CONCURRENCY,
						$count
					)
				);
			}

			delete_transient( 'fl_ds_active_jobs_' . $user_id );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}
}
