<?php

namespace FL\DesignSystem\Mcp;

/**
 * Contract for a single MCP ability.
 *
 * Each ability owns its own definition (schema + metadata) and execute
 * body. The {@see AbilityRegistry} wires these into the WordPress
 * Abilities API and the admin tool list.
 */
interface AbilityInterface {

	/**
	 * Namespaced ability name (e.g. "beaver-builder-ai/list-pages").
	 */
	public function name(): string;

	/**
	 * Ability registration arguments passed to wp_register_ability().
	 *
	 * Includes label, description, category, input_schema, execute/permission
	 * callbacks, and the meta block that drives admin tool grouping.
	 *
	 * Called fresh on every registration pass so dynamic schema fragments
	 * (e.g. CPT enums) reflect current state.
	 *
	 * @return array
	 */
	public function definition(): array;

	/**
	 * Execute the ability against parsed input.
	 *
	 * @param  array $input Validated input parameters.
	 * @return mixed Response payload, or WP_Error on failure.
	 */
	public function execute( array $input );

	/**
	 * Permission gate.
	 *
	 * Defaults to content-creator trust via {@see Permissions::content_creator()}.
	 * Per-resource checks (post ownership, DS edit access) live inside execute().
	 */
	public function permission(): bool;
}
