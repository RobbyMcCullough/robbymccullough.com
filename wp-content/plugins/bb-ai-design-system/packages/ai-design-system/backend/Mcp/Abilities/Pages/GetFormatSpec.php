<?php

namespace FL\DesignSystem\Mcp\Abilities\Pages;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\FormatSpecLoader;

/**
 * MCP ability: get-format-spec.
 *
 * Returns the assembled format spec optionally narrowed to a mode and a
 * subset of optional sections. Lives in Pages/ because subcategory is
 * 'pages' even though the spec describes both new-DS and existing-DS flows.
 */
class GetFormatSpec extends BaseAbility {

	private FormatSpecLoader $loader;

	public function __construct( FormatSpecLoader $loader ) {
		$this->loader = $loader;
	}

	public function name(): string {
		return 'beaver-builder-ai/get-format-spec';
	}

	public function definition(): array {
		return [
			'label'               => 'Get Format Spec',
			'description'         => 'Returns the Beaver Builder AI format specification for generating pages with a new design system. Call this instead of get-design-system when creating a new design system. The format spec defines the exact HTML structure, CSS markers, annotation rules, and design token conventions your output must follow. Without it, the importer will reject your HTML. Pass mode: "editing" for the editing-mode spec (adds mixed-content and editing-existing-page sections); pass has_design_system: true for the existing-DS variant; pass include to slice optional sections (e.g. ["annotations"] for annotation rules only). Use this for both `generate-page` (body contains content) and `create-design-system` (body may be empty). The HTML format and section conventions are identical.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				// Top-level default for empty-parameters resilience -- mcp-adapter's
				// ExecuteAbilityAbility nullifies `parameters: {}` via empty(), and
				// WP_Ability::normalize_input() substitutes this default when input
				// arrives as null so type: object validation still passes.
				'default'    => [],
				'properties' => [
					'mode'              => [
						'type'        => 'string',
						'enum'        => [ 'creation', 'editing' ],
						'description' => 'Workflow mode. "editing" adds mixed-content and editing-existing-page sections. Default: creation.',
					],
					'has_design_system' => [
						'type'        => 'boolean',
						'description' => 'True to include existing-ds context, false for new-ds. Default: false.',
					],
					'include'           => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => [ 'annotations', 'forms', 'javascript', 'google-fonts', 'reset', 'example' ],
						],
						'description' => 'Optional sections to include. Omit (or pass []) to include all optional sections (default). Pass a subset to trim, e.g. ["annotations"] for annotation rules only, useful for surgical edits where the agent already knows the rest of the spec.',
					],
				],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true ],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'pages',
				'summary'     => 'Returns the HTML format specification for new pages',
			],
		];
	}

	public function execute( array $input = [] ): array {
		$mode    = isset( $input['mode'] ) && 'editing' === $input['mode'] ? 'editing' : 'creation';
		$has_ds  = ! empty( $input['has_design_system'] );
		$include = ! empty( $input['include'] ) ? $input['include'] : null;

		return [
			'format_spec' => $this->loader->load_format_spec( $mode, [ 'has_design_system' => $has_ds ], $include ),
		];
	}
}
