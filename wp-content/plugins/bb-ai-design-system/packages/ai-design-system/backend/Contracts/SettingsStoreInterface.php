<?php

namespace FL\DesignSystem\Contracts;

interface SettingsStoreInterface {

	/**
	 * Get all settings with sensitive values masked.
	 *
	 * API keys return as hasKey: true/false instead of the raw value.
	 *
	 * @return array
	 */
	public function all(): array;

	/**
	 * Get a single setting by dot-notation key.
	 *
	 * @param  string $key     Dot-notation key (e.g., 'ai.api_key').
	 * @param  mixed  $default Default value if the setting doesn't exist.
	 * @return mixed
	 */
	public function get( string $key, $default = null );

	/**
	 * Set one or more settings.
	 *
	 * @param array $values Associative array of dot-notation keys to values.
	 */
	public function set( array $values ): void;

	/**
	 * Delete a setting by dot-notation key.
	 *
	 * @param string $key Dot-notation key.
	 */
	public function delete( string $key ): void;
}
