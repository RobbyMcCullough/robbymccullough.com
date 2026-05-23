<?php

namespace FL\DesignSystem\Form;

/**
 * Contract for a form submission action handler.
 *
 * Handlers are registered in the FormActionRegistry by a type slug
 * (e.g. 'email', 'webhook', 'custom') and dispatched at submit time
 * against a submission payload and the action-specific configuration.
 *
 * Implementations must remain platform-agnostic. WordPress-specific
 * calls (wp_mail, wp_remote_request, do_action) belong in an adapter
 * that is injected into the handler.
 *
 * Failure semantics: handlers should not throw. Returning
 * `success: false` reports a failure for this action only; sibling
 * actions in the same submission still run. Redirect is emitted only
 * when every action in the submission succeeded.
 */
interface FormActionInterface {

	/**
	 * Handle a form submission.
	 *
	 * @param array $submission   Normalized submission payload, shaped as:
	 *                            [
	 *                              'block_id' => string,
	 *                              'form_id'  => string,
	 *                              'fields'   => array<string, mixed>,
	 *                              'context'  => array, // e.g. site_url, admin_email
	 *                            ]
	 * @param array $action_config Action-specific configuration (e.g. 'to', 'subject' for email).
	 * @return array Result shaped as [
	 *                 'success'  => bool,
	 *                 'redirect' => string|null, // optional post-submit redirect
	 *                 'error'    => string|null, // human-readable error message
	 *               ]
	 */
	public function handle( array $submission, array $action_config ): array;
}
