<?php

namespace FL\DesignSystem\Form;

/**
 * Registry of form submission action handlers.
 *
 * Action types (e.g. 'email', 'webhook', 'custom') are registered
 * with a handler implementing FormActionInterface. The FormSubmissionProvider
 * resolves the handler by type at submit time.
 *
 * Re-registering an existing type replaces the previous handler to allow
 * tests and third-party packages to override built-in handlers.
 */
class FormActionRegistry {

	/**
	 * @var array<string, FormActionInterface>
	 */
	private array $handlers = [];

	/**
	 * Register a handler for an action type.
	 *
	 * @param string              $type    Action type slug (e.g. 'email').
	 * @param FormActionInterface $handler Handler implementation.
	 */
	public function register( string $type, FormActionInterface $handler ): void {
		$this->handlers[ $type ] = $handler;
	}

	/**
	 * Get the handler for a type, or null if none is registered.
	 *
	 * @param  string $type Action type slug.
	 * @return FormActionInterface|null
	 */
	public function get( string $type ): ?FormActionInterface {
		return $this->handlers[ $type ] ?? null;
	}

	/**
	 * Whether a handler is registered for the given type.
	 *
	 * @param  string $type Action type slug.
	 * @return bool
	 */
	public function has( string $type ): bool {
		return isset( $this->handlers[ $type ] );
	}

	/**
	 * All registered handlers keyed by type.
	 *
	 * @return array<string, FormActionInterface>
	 */
	public function all(): array {
		return $this->handlers;
	}
}
