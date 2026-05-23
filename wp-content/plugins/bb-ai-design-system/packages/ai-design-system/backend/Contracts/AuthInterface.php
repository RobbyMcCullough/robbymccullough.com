<?php

namespace FL\DesignSystem\Contracts;

interface AuthInterface {

	/**
	 * Check whether a user is authenticated.
	 *
	 * @return bool
	 */
	public function check(): bool;

	/**
	 * Check whether the current user has a given capability.
	 *
	 * @param  string $capability Abstract capability name (e.g., 'manage_settings').
	 * @param  mixed  ...$args    Additional arguments (e.g., post ID for post-level checks).
	 * @return bool
	 */
	public function can( string $capability, ...$args ): bool;

	/**
	 * Permission callback for REST routes requiring authenticated admin access.
	 *
	 * @return bool
	 */
	public function permission_callback(): bool;

	/**
	 * Permission callback for REST routes requiring administrator access.
	 *
	 * @return bool
	 */
	public function admin_permission_callback(): bool;

	/**
	 * Permission callback for REST routes requiring editor access.
	 *
	 * @return bool
	 */
	public function editor_permission_callback(): bool;

	/**
	 * Permission callback for REST routes requiring content creator access.
	 *
	 * @return bool
	 */
	public function content_creator_permission_callback(): bool;

	/**
	 * Permission callback for REST routes readable by any logged-in user.
	 *
	 * @return bool
	 */
	public function read_permission_callback(): bool;

	/**
	 * Get the current user's ID.
	 *
	 * @return int|string
	 */
	public function user_id(): int|string;
}
