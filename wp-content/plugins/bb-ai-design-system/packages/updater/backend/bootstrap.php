<?php
/**
 * Bootstrap for the updater package.
 *
 * Hooks into fl_design_system_booted to register the license provider
 * after the core plugin and settings store are ready.
 *
 * @package FL\DesignSystem\Updater
 */

namespace FL\DesignSystem\Updater;

use FL\DesignSystem\Contracts\SettingsStoreInterface;
use FL\DesignSystem\Plugin;

add_action(
	'fl_design_system_booted',
	function ( Plugin $plugin, SettingsStoreInterface $settings ) {
		$product = [
			'name'    => $plugin->metadata['Name'] ?? 'Beaver Builder AI',
			'version' => $plugin->version,
			'slug'    => 'bb-ai-design-system',
			'type'    => 'plugin',
		];

		$provider = new LicenseProvider( $settings, $product );
		$provider->boot();

		// Store instance so other packages (e.g. AdminAssetProvider) can access it.
		$GLOBALS['fl_ds_license_provider'] = $provider;
	},
	10,
	2
);
