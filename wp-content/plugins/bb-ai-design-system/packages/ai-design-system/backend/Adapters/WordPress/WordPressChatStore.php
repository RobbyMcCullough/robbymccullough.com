<?php

namespace FL\DesignSystem\Adapters\WordPress;

use FL\DesignSystem\Contracts\ChatStoreInterface;
use FL\DesignSystem\Chat\ChatTable;

class WordPressChatStore implements ChatStoreInterface {

	public const MAX_MESSAGES = 500;

	/**
	 * Get a conversation for a specific user and item.
	 *
	 * Uses json_decode without the associative flag so empty `{}` objects
	 * survive the round-trip as stdClass instead of being converted to `[]`.
	 * WP_REST_Response's json_encode serializes stdClass back to `{}`.
	 *
	 * @param  int|string $user_id   User ID.
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @return array|null Messages array or null if no conversation exists.
	 */
	public function get( int|string $user_id, string $item_type, int|string $item_id ): ?array {
		global $wpdb;

		$table = ChatTable::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT messages, summary FROM {$table} WHERE user_id = %d AND item_type = %s AND item_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from internal constant.
				$user_id,
				$item_type,
				$item_id,
			),
		);

		if ( ! $row ) {
			return null;
		}

		// Decode without `true` — the top-level JSON array decodes as a PHP
		// array, but nested objects (like tool_use input: {}) stay as stdClass.
		// This prevents PHP from converting {} to [] on the read path.
		$messages = json_decode( $row->messages );

		return is_array( $messages ) ? $messages : null;
	}

	/**
	 * Get the conversation mode for a specific user and item.
	 *
	 * @param  int|string $user_id   User ID.
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @return string|null Mode string, or null if unset.
	 */
	public function get_mode( int|string $user_id, string $item_type, int|string $item_id ): ?string {
		global $wpdb;

		$table = ChatTable::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT mode FROM {$table} WHERE user_id = %d AND item_type = %s AND item_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from internal constant.
				$user_id,
				$item_type,
				$item_id,
			),
		);

		return $row && $row->mode ? (string) $row->mode : null;
	}

	/**
	 * Get the active generation job metadata for a specific user and item.
	 *
	 * @param  int|string $user_id   User ID.
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @return array|null Decoded active_job array, or null if unset.
	 */
	public function get_active_job( int|string $user_id, string $item_type, int|string $item_id ): ?array {
		global $wpdb;

		$table = ChatTable::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT active_job FROM {$table} WHERE user_id = %d AND item_type = %s AND item_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from internal constant.
				$user_id,
				$item_type,
				$item_id,
			),
		);

		if ( ! $row || ! $row->active_job ) {
			return null;
		}

		$decoded = json_decode( $row->active_job, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Get the conversation summary for a specific user and item.
	 *
	 * @param  int|string $user_id   User ID.
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @return object|null Summary object or null.
	 */
	public function get_summary( int|string $user_id, string $item_type, int|string $item_id ): ?object {
		global $wpdb;

		$table = ChatTable::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT summary FROM {$table} WHERE user_id = %d AND item_type = %s AND item_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from internal constant.
				$user_id,
				$item_type,
				$item_id,
			),
		);

		if ( ! $row || ! $row->summary ) {
			return null;
		}

		$summary = json_decode( $row->summary );
		return is_object( $summary ) ? $summary : null;
	}

	/**
	 * Save (full replace) a conversation for a specific user and item.
	 *
	 * Caps messages at MAX_MESSAGES, keeping the most recent.
	 *
	 * @param  int|string  $user_id    User ID.
	 * @param  string      $item_type  Entity type key.
	 * @param  int|string  $item_id    Item ID.
	 * @param  array       $messages   Full messages array.
	 * @param  object|null $summary    Conversation summary object, or null.
	 * @param  string|null $mode       Conversation mode, or null.
	 * @param  array|null  $active_job In-flight generation job metadata, or null.
	 * @return bool        True on success.
	 */
	public function save( int|string $user_id, string $item_type, int|string $item_id, array $messages, ?object $summary = null, ?string $mode = null, ?array $active_job = null ): bool {
		global $wpdb;

		$table = ChatTable::table_name();

		// Cap at MAX_MESSAGES, keeping the most recent
		if ( count( $messages ) > self::MAX_MESSAGES ) {
			$messages = array_slice( $messages, -self::MAX_MESSAGES );
		}

		$data   = [
			'user_id'   => $user_id,
			'item_type' => $item_type,
			'item_id'   => $item_id,
			'messages'  => wp_json_encode( $messages ),
		];
		$format = [ '%d', '%s', '%d', '%s' ];

		if ( null !== $summary ) {
			$data['summary'] = wp_json_encode( $summary );
			$format[]        = '%s';
		}

		// Persist mode/active_job explicitly (including null) so a save can
		// clear a previously-set value. Skipping the column on null would
		// leave stale data in place.
		$data['mode'] = $mode;
		$format[]     = '%s';

		$data['active_job'] = null === $active_job ? null : wp_json_encode( $active_job );
		$format[]           = '%s';

		$result = $wpdb->replace( $table, $data, $format );

		return false !== $result;
	}

	/**
	 * Append messages to an existing conversation.
	 *
	 * @param  int|string $user_id   User ID.
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @param  array      $messages  Messages to append.
	 * @return bool       True on success.
	 */
	public function append( int|string $user_id, string $item_type, int|string $item_id, array $messages ): bool {
		$existing = $this->get( $user_id, $item_type, $item_id );
		$merged   = array_merge( $existing ?? [], $messages );

		return $this->save( $user_id, $item_type, $item_id, $merged );
	}

	/**
	 * Delete a conversation for a specific user and item.
	 *
	 * @param  int|string $user_id   User ID.
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @return bool       True on success.
	 */
	public function delete( int|string $user_id, string $item_type, int|string $item_id ): bool {
		global $wpdb;

		$table = ChatTable::table_name();

		$result = $wpdb->delete(
			$table,
			[
				'user_id'   => $user_id,
				'item_type' => $item_type,
				'item_id'   => $item_id,
			],
			[ '%d', '%s', '%d' ],
		);

		return false !== $result;
	}

	/**
	 * Delete all conversations for an item (cleanup when item is deleted).
	 *
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @return bool       True on success.
	 */
	public function delete_by_item( string $item_type, int|string $item_id ): bool {
		global $wpdb;

		$table = ChatTable::table_name();

		$result = $wpdb->delete(
			$table,
			[
				'item_type' => $item_type,
				'item_id'   => $item_id,
			],
			[ '%s', '%d' ],
		);

		return false !== $result;
	}
}
