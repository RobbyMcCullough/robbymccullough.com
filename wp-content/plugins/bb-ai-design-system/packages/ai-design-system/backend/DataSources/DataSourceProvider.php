<?php

namespace FL\DesignSystem\DataSources;

/**
 * Bootstraps built-in data sources.
 *
 * Registers all core data source types in the DataSourceRegistry.
 * Called during provider boot in Plugin::register_providers().
 */
class DataSourceProvider {

	/**
	 * Register all built-in data sources.
	 */
	public static function boot(): void {
		WPPostQuerySource::register();
	}
}
