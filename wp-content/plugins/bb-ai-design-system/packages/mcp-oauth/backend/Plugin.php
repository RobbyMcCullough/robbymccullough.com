<?php
/**
 * MCP OAuth package orchestrator.
 *
 * Checks dependencies, registers providers, and manages the package
 * lifecycle. All OAuth functionality is dormant until the site is
 * connected to Beaver Builder AI (fl_ds_mcp_oauth_connected).
 *
 * @package FL\DesignSystem\McpOAuth
 */

namespace FL\DesignSystem\McpOAuth;

use FL\DesignSystem\McpOAuth\Providers\McpOAuthProvider;

class Plugin {

	/**
	 * Boot the package.
	 *
	 * Checks for the MCP Adapter dependency, shows an admin notice
	 * if missing, and registers providers if the dependency is met.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! $this->has_mcp_adapter() ) {
			add_action( 'admin_notices', [ $this, 'show_missing_adapter_notice' ] );
			return;
		}

		$this->register_providers();
	}

	/**
	 * Check whether the MCP Adapter plugin is available.
	 *
	 * Uses function_exists as a reliable check that works regardless
	 * of plugin loading order. The wp_register_ability function is
	 * defined by the MCP Adapter plugin.
	 *
	 * @return bool
	 */
	private function has_mcp_adapter(): bool {
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * Display an admin notice when the MCP Adapter plugin is not active.
	 *
	 * @return void
	 */
	public function show_missing_adapter_notice(): void {
		// Only show to users who can manage plugins.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'Beaver Builder AI MCP connection requires the MCP Adapter plugin. Please install and activate it to enable remote MCP connections.',
			'fl-design-system'
		);
		echo '</p></div>';
	}

	/**
	 * Register all package providers.
	 *
	 * @return void
	 */
	private function register_providers(): void {
		$provider = new McpOAuthProvider();
		$provider->boot();
	}
}
