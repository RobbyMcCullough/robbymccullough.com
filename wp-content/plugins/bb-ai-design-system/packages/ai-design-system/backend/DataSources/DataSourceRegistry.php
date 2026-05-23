<?php

namespace FL\DesignSystem\DataSources;

/**
 * Static registry of data source definitions.
 *
 * Each source provides a type, label, and resolver callable for
 * producing items from external data (post queries, taxonomy terms, etc.).
 */
class DataSourceRegistry {

	/**
	 * Registered data sources keyed by type.
	 *
	 * @var array<string, array{type: string, label: string, resolver: callable}>
	 */
	private static array $sources = [];

	/**
	 * Register a data source definition.
	 *
	 * @param string $type       Unique identifier (e.g., 'wp-post-query').
	 * @param array  $definition Must contain 'label' (string) and 'resolver' (callable).
	 */
	public static function register( string $type, array $definition ): void {
		if ( isset( self::$sources[ $type ] ) ) {
			throw new \RuntimeException( "Data source \"{$type}\" is already registered." );
		}

		if ( empty( $definition['label'] ) || ! is_string( $definition['label'] ) ) {
			throw new \InvalidArgumentException( "Data source \"{$type}\" must have a string label." );
		}

		if ( ! isset( $definition['resolver'] ) || ! is_callable( $definition['resolver'] ) ) {
			throw new \InvalidArgumentException( "Data source \"{$type}\" must have a callable resolver." );
		}

		$definition['type']    = $type;
		self::$sources[ $type ] = $definition;
	}

	/**
	 * Get a data source definition by type.
	 *
	 * @param  string $type The source type identifier.
	 * @return array|null The definition, or null if not registered.
	 */
	public static function get( string $type ): ?array {
		return self::$sources[ $type ] ?? null;
	}

	/**
	 * Get all registered data source definitions.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		return self::$sources;
	}

	/**
	 * Check if a data source type is registered.
	 *
	 * @param  string $type The source type identifier.
	 * @return bool
	 */
	public static function has( string $type ): bool {
		return isset( self::$sources[ $type ] );
	}

	/**
	 * Resolve a data source by calling its resolver with the given config and context.
	 *
	 * @param  string $type    The source type identifier.
	 * @param  array  $config  Source-specific configuration (e.g., post_type, count).
	 * @param  array  $context Render context (e.g., current post ID).
	 * @return array Array of items, each an associative array of property values.
	 */
	public static function resolve( string $type, array $config, array $context = [] ): array {
		$source = self::get( $type );

		if ( null === $source ) {
			return [];
		}

		return call_user_func( $source['resolver'], $config, $context );
	}
}
