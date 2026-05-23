<?php

namespace FL\DesignSystem\Settings;

/**
 * Result of a {@see SettingsSanitizer::sanitize()} call.
 *
 * Carries the sanitized settings tree and a count of string leaves whose
 * value was actually changed by sanitization. The count lets callers emit
 * a single structured log line per save without having to diff the tree
 * themselves or leak user content into logs.
 */
class SanitizationResult {

	/**
	 * @var array The sanitized settings tree (same shape as the input).
	 */
	public array $settings;

	/**
	 * @var int Number of string leaves whose value was changed by sanitization.
	 */
	public int $altered_count;

	/**
	 * @param array $settings      Sanitized settings tree.
	 * @param int   $altered_count Number of string leaves changed.
	 */
	public function __construct( array $settings, int $altered_count ) {
		$this->settings      = $settings;
		$this->altered_count = $altered_count;
	}
}
