<?php

namespace FL\DesignSystem\DesignKit;

use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\Services\AdapterResolver;

/**
 * Bootstrap provider for Design Kit endpoints.
 */
class DesignKitProvider {

	private AuthInterface $auth;

	private ?BuiltInKitRegistry $registry;

	private ?AdapterResolver $adapter_resolver;

	/**
	 * @param AuthInterface           $auth             Auth adapter for permission checks.
	 * @param BuiltInKitRegistry|null $registry         Registry of bundled kits, if available.
	 * @param AdapterResolver|null    $adapter_resolver Editor adapter resolver (required to include pages in downloads).
	 */
	public function __construct(
		AuthInterface $auth,
		?BuiltInKitRegistry $registry = null,
		?AdapterResolver $adapter_resolver = null
	) {
		$this->auth             = $auth;
		$this->registry         = $registry;
		$this->adapter_resolver = $adapter_resolver;
	}

	/**
	 * Boot the provider by registering the controller.
	 */
	public function boot(): void {
		$page_writer = $this->adapter_resolver
			? new DesignKitPageWriter( $this->adapter_resolver )
			: null;

		$controller = new DesignKitController( $this->auth, $this->registry, $page_writer );
		$controller->boot();

		if ( null !== $this->registry ) {
			$template_pages = new TemplatePageController( $this->auth, $this->registry );
			$template_pages->boot();
		}
	}
}
