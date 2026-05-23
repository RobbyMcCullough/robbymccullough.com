<?php

namespace FL\DesignSystem\Adapters\WordPress;

use FL\DesignSystem\Contracts\FormActionHookInterface;

/**
 * Dispatches custom form actions to a WordPress `do_action` hook.
 *
 * Site owners and companion plugins hook into:
 *
 *   add_action( 'fl_ds_form_custom/my-hook', function ( $fields, $context ) {
 *       // $fields  — submitted form values keyed by input name
 *       // $context — [ 'form_id' => ..., 'block_id' => ..., 'post_id' => ... ]
 *   }, 10, 2 );
 *
 * Handlers that only need the fields can omit the context:
 *
 *   add_action( 'fl_ds_form_custom/my-hook', function ( $fields ) {
 *       // ...
 *   } );
 */
class WordPressFormActionHook implements FormActionHookInterface {

	public const HOOK_PREFIX = 'fl_ds_form_custom/';

	/**
	 * Fire the hook for the given name.
	 *
	 * @param string $hook_name Sanitized hook slug.
	 * @param array  $fields    Submitted form values.
	 * @param array  $context   Minimal context (form_id, block_id, post_id).
	 */
	public function dispatch( string $hook_name, array $fields, array $context ): void {
		do_action( self::HOOK_PREFIX . $hook_name, $fields, $context );
	}
}
