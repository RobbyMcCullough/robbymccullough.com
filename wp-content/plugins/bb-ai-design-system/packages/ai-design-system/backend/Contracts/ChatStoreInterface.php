<?php

namespace FL\DesignSystem\Contracts;

interface ChatStoreInterface {

	/**
	 * Get a conversation for a specific user and item.
	 *
	 * @param  int|string $user_id   User ID.
	 * @param  string     $item_type Entity type key (e.g., 'block').
	 * @param  int|string $item_id   Item ID.
	 * @return array|null Messages array or null if no conversation exists.
	 */
	public function get( int|string $user_id, string $item_type, int|string $item_id ): ?array;

	/**
	 * Save (full replace) a conversation for a specific user and item.
	 *
	 * @param  int|string    $user_id    User ID.
	 * @param  string        $item_type  Entity type key.
	 * @param  int|string    $item_id    Item ID.
	 * @param  array         $messages   Full messages array.
	 * @param  object|null   $summary    Conversation summary object, or null.
	 * @param  string|null   $mode       Conversation mode (e.g., 'generate'), or null.
	 * @param  array|null    $active_job In-flight generation job metadata, or null.
	 * @return bool          True on success.
	 */
	public function save( int|string $user_id, string $item_type, int|string $item_id, array $messages, ?object $summary = null, ?string $mode = null, ?array $active_job = null ): bool;

	/**
	 * Append messages to an existing conversation.
	 *
	 * If no conversation exists, creates one with the provided messages.
	 *
	 * @param  int|string $user_id   User ID.
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @param  array      $messages  Messages to append.
	 * @return bool       True on success.
	 */
	public function append( int|string $user_id, string $item_type, int|string $item_id, array $messages ): bool;

	/**
	 * Delete a conversation for a specific user and item.
	 *
	 * @param  int|string $user_id   User ID.
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @return bool       True on success.
	 */
	public function delete( int|string $user_id, string $item_type, int|string $item_id ): bool;

	/**
	 * Delete all conversations for an item (cleanup when item is deleted).
	 *
	 * @param  string     $item_type Entity type key.
	 * @param  int|string $item_id   Item ID.
	 * @return bool       True on success.
	 */
	public function delete_by_item( string $item_type, int|string $item_id ): bool;
}
