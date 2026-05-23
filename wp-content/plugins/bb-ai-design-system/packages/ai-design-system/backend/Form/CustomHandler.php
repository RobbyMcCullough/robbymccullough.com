<?php

namespace FL\DesignSystem\Form;

use FL\DesignSystem\Contracts\FormActionHookInterface;

/**
 * Custom action handler.
 *
 * Fires a platform-native hook (`fl_ds_form_custom/{hook_name}` in WordPress)
 * so site owners can plug in arbitrary server-side logic by hooking.
 *
 * Config shape:
 *   [
 *     'hook' => string, // required, slug (a-z0-9 and dashes/underscores)
 *   ]
 */
class CustomHandler implements FormActionInterface {

	/**
	 * Matches safe hook slugs: letters, digits, underscore, dash, slash.
	 */
	private const HOOK_PATTERN = '/^[A-Za-z0-9_\-\/]+$/';

	private FormActionHookInterface $dispatcher;

	public function __construct( FormActionHookInterface $dispatcher ) {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Dispatch the submission to the configured custom hook.
	 *
	 * @param  array $submission    Normalized submission payload.
	 * @param  array $action_config Action configuration.
	 * @return array
	 */
	public function handle( array $submission, array $action_config ): array {
		$hook = isset( $action_config['hook'] ) ? trim( (string) $action_config['hook'] ) : '';

		if ( '' === $hook || ! preg_match( self::HOOK_PATTERN, $hook ) ) {
			return [
				'success'  => false,
				'redirect' => null,
				'error'    => 'Custom action is missing a valid hook name.',
			];
		}

		$fields  = is_array( $submission['fields'] ?? null ) ? $submission['fields'] : [];
		// `form_key` is the stable identifier — branch on this when routing
		// hook logic per form. `form_id` is the HTML id, exposed for logging
		// and display only; it can change if the template is renamed.
		$context = [
			'form_key' => (string) ( $submission['form_key'] ?? '' ),
			'form_id'  => (string) ( $submission['form_id'] ?? '' ),
			'block_id' => (string) ( $submission['block_id'] ?? '' ),
			'post_id'  => (int) ( $submission['post_id'] ?? 0 ),
		];

		$this->dispatcher->dispatch( $hook, $fields, $context );

		return [
			'success'  => true,
			'redirect' => null,
			'error'    => null,
		];
	}
}
