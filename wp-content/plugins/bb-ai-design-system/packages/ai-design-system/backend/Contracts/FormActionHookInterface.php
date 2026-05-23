<?php

namespace FL\DesignSystem\Contracts;

/**
 * Contract for dispatching a form submission to a platform-native hook.
 *
 * The 'custom' action uses this to let site owners wire arbitrary
 * server-side logic to a named hook (e.g. `fl_ds_form_custom/{hook}`).
 */
interface FormActionHookInterface {

	/**
	 * Dispatch a form submission to the given hook slug.
	 *
	 * @param string $hook_name Sanitized hook slug (e.g. 'contact-confirm').
	 * @param array  $fields    Submitted form values keyed by input name.
	 * @param array  $context   Minimal context (form_id, block_id, post_id).
	 */
	public function dispatch( string $hook_name, array $fields, array $context ): void;
}
