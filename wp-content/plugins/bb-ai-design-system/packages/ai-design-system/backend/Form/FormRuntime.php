<?php

namespace FL\DesignSystem\Form;

/**
 * Per-request flag indicating that at least one design system form has
 * rendered on the current page. Consumed at wp_footer to decide whether
 * the frontend form submission runtime should be emitted.
 *
 * Set by FormFieldInjector during block rendering. Any future render
 * path that emits a `<form id="...">` outside the block pipeline should
 * also call mark_needed() so the runtime is emitted on that page.
 */
class FormRuntime {

	/** @var bool Whether the form runtime should be emitted on this request. */
	private static bool $needed = false;

	/**
	 * Mark the form runtime as required for the current request.
	 */
	public static function mark_needed(): void {
		self::$needed = true;
	}

	/**
	 * Whether the form runtime should be emitted on the current request.
	 */
	public static function is_needed(): bool {
		return self::$needed;
	}

	/**
	 * Reset the flag. Intended for test isolation; not used at runtime.
	 */
	public static function reset(): void {
		self::$needed = false;
	}
}
