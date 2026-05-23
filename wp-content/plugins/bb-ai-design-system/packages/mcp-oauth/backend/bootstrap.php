<?php
/**
 * Bootstrap for the mcp-oauth package.
 *
 * Loads the vendored firebase/php-jwt library, registers the PSR-4
 * autoloader for the FL\DesignSystem\McpOAuth namespace, and boots
 * the package orchestrator.
 *
 * This file is auto-loaded by the glob pattern in bb-ai-design-system.php:
 *   packages/* /backend/bootstrap.php
 *
 * @package FL\DesignSystem\McpOAuth
 */

namespace FL\DesignSystem\McpOAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Vendored firebase/php-jwt autoloader ──────────────────────────────
// The Design System plugin has no Composer autoloading for production
// dependencies. Load the vendored firebase/php-jwt classes manually.
// Guard against double-loading if another plugin ships the same library.
if ( ! class_exists( '\\Firebase\\JWT\\JWT' ) ) {
	$vendor_dir = __DIR__ . '/Vendor/firebase-php-jwt/';
	require_once $vendor_dir . 'JWTExceptionWithPayloadInterface.php';
	require_once $vendor_dir . 'BeforeValidException.php';
	require_once $vendor_dir . 'ExpiredException.php';
	require_once $vendor_dir . 'SignatureInvalidException.php';
	require_once $vendor_dir . 'Key.php';
	require_once $vendor_dir . 'JWT.php';
	require_once $vendor_dir . 'JWK.php';
}

// ── PSR-4 autoloader for FL\DesignSystem\McpOAuth ─────────────────────
spl_autoload_register( function ( string $class ): void {
	$prefix    = 'FL\\DesignSystem\\McpOAuth\\';
	$base_dir  = __DIR__ . '/';

	$len = strlen( $prefix );
	if ( 0 !== strncmp( $prefix, $class, $len ) ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// ── Activation / deactivation hooks ───────────────────────────────────
// Must be registered at plugin load time (not deferred to plugins_loaded)
// because WordPress processes activation hooks during the plugin load phase.
register_activation_hook( \FL_DESIGN_SYSTEM_FILE, function (): void {
	$provider = new Providers\McpOAuthProvider();
	$provider->add_well_known_rewrite();
	flush_rewrite_rules();
} );

register_deactivation_hook( \FL_DESIGN_SYSTEM_FILE, function (): void {
	flush_rewrite_rules();
	delete_transient( 'fl_ds_mcp_oauth_jwks' );
} );

// ── Boot the package ──────────────────────────────────────────────────
// Defer boot to `plugins_loaded` so all plugins are loaded and
// function_exists('wp_register_ability') is reliable. The MCP Adapter
// registers its functions during plugin load, so plugins_loaded is
// the earliest safe point for the dependency check.
add_action( 'plugins_loaded', function (): void {
	$plugin = new Plugin();
	$plugin->boot();
} );
