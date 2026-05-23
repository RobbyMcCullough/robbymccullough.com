<?php

namespace FL\DesignSystem\Mcp;

use FL\DesignSystem\Mcp\Support\InputNormalizer;

/**
 * Registers MCP abilities with the WordPress Abilities API and
 * collects admin-facing metadata for the tools table.
 *
 * Why no caching of definition(): McpProvider::register_abilities()
 * runs twice — once on boot() to populate admin metadata, and again
 * on `wp_abilities_api_init` to register with the API. Both passes
 * rebuild schemas fresh against current CPT state. Caching here
 * would freeze the schema at boot-time and lose CPTs registered
 * between init:10 and the abilities-API hook.
 */
final class AbilityRegistry {

	/**
	 * @var AbilityInterface[]
	 */
	private array $abilities = [];

	/**
	 * Add an ability to the registry.
	 *
	 * Side effect: appends the ability's admin metadata to
	 * {@see McpProvider::$admin_tools} so the existing static reader
	 * ({@see McpProvider::get_admin_tool_list()}) keeps working
	 * unchanged for AdminAssetProvider.
	 */
	public function register( AbilityInterface $ability ): void {
		$this->abilities[] = $ability;

		$definition = $ability->definition();
		$meta       = $definition['meta'] ?? [];

		if ( isset( $meta['subcategory'] ) ) {
			McpProvider::push_admin_tool( [
				'label'       => $definition['label'] ?? $ability->name(),
				'summary'     => $meta['summary'] ?? '',
				'subcategory' => $meta['subcategory'],
			] );
		}
	}

	/**
	 * Register every ability with the WordPress Abilities API.
	 *
	 * Called from the `wp_abilities_api_init` hook. Schemas are rebuilt
	 * fresh on each call so dynamic enums (e.g. creatable post types)
	 * reflect current state.
	 */
	public function register_with_abilities_api(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->abilities as $ability ) {
			$definition = $ability->definition();
			// Property names are static across registration passes (only enums on
			// values are dynamic), so capturing once at registration is correct.
			$properties                     = $definition['input_schema']['properties'] ?? [];
			$definition['execute_callback'] = self::wrap_callback( $definition['execute_callback'], $properties );

			wp_register_ability( $ability->name(), $definition );
		}
	}

	/**
	 * Wrap an ability's execute callback with input normalization + ignored-key
	 * annotation. Exposed for unit testing the closure's call shape.
	 *
	 * `$input` defaults to `[]` because WP_Ability::invoke_callback() forwards
	 * an argument only when the ability declares a non-empty input_schema; the
	 * wrapper otherwise gets called with zero args and would fatal.
	 *
	 * @param callable $original    Underlying ability execute callback.
	 * @param array    $properties  input_schema properties (for alias normalization).
	 */
	public static function wrap_callback( callable $original, array $properties ): callable {
		return function ( array $input = [] ) use ( $original, $properties ) {
			$normalized = InputNormalizer::normalize( $input, $properties );
			$result     = call_user_func( $original, $normalized['input'] );
			return InputNormalizer::annotate( $result, $normalized['ignored_keys'] );
		};
	}

	/**
	 * @return AbilityInterface[]
	 */
	public function all(): array {
		return $this->abilities;
	}
}
