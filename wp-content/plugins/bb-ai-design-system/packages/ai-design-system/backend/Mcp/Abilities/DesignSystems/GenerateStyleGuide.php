<?php

namespace FL\DesignSystem\Mcp\Abilities\DesignSystems;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\FormatSpecLoader;
use FL\DesignSystem\Mcp\Support\PageGenerator;
use FL\DesignSystem\Mcp\Support\PostTypeService;

/**
 * MCP ability: generate-style-guide.
 *
 * Creates a style-guide page that showcases a design system. Execution
 * delegates to {@see PageGenerator}, the same shared instance the registry
 * passes to GeneratePage so both abilities run the same pipeline.
 */
class GenerateStyleGuide extends BaseAbility {

	private PageGenerator $generator;
	private PostTypeService $post_type_service;
	private FormatSpecLoader $loader;

	public function __construct( PageGenerator $generator, PostTypeService $post_type_service, FormatSpecLoader $loader ) {
		$this->generator         = $generator;
		$this->post_type_service = $post_type_service;
		$this->loader            = $loader;
	}

	public function name(): string {
		return 'beaver-builder-ai/generate-style-guide';
	}

	public function definition(): array {
		$creatable_post_types = $this->post_type_service->get_creatable_post_type_slugs();

		return [
			'label'               => 'Generate Style Guide',
			'description'         => 'Pass a complete HTML document via `html`. All CSS must be embedded inside `<style>` blocks in `<head>` and all JavaScript inside `<script>` blocks. There are no separate `css` or `js` parameters; unknown top-level keys are rejected. Creates a style guide page that showcases a design system. Use this when the user wants to create, explore, or showcase a design system without targeting a specific page layout (e.g., "create a new design", "make me a style guide", "show me what this design system looks like", "I want to explore a visual direction"). Do NOT use this when the user wants to build a specific page (landing page, about page, portfolio, etc.); use generate-page instead. DESIGN SELECTION: Ask the user if they would like to use an existing design system or start fresh. If existing, call get-design-system to load tokens and creative direction. If starting fresh, call get-format-spec and create a design system along with the style guide. SPEC INPUT: If the user has supplied a brand spec, design brief, or design system document, call `create-design-system` first and pass the resulting UUID via `design_system_uuid`. Do not attempt to bake the spec into this tool\'s call; spec preservation lives on `create-design-system`, not here. When auto-creating a design system (no design_system_uuid), provide a design_system_label. CREATIVE FLOW: Have a creative conversation with the user about their vision; explore colors, typography, spacing, mood, references. Ask questions to understand their direction, confirm in 2-3 sentences, then go straight to writing the HTML. Creative energy goes into the HTML, not a written brief. '
				. $this->loader->load_spec_file( FL_DESIGN_SYSTEM_DIR . 'packages/ai-design-system/data/spec/context/style-guide.md' )
				. ' Pages are created as drafts by default. AFTER CREATING: Always share the draft URL with the user immediately, before doing anything else. When the response includes `next_steps` (auto-DS without follow-up direction), call `analyze-page-design` and `update-design-system-guidance` to fill the gap, otherwise subsequent pages will lack consistency direction.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'title'               => [
						'type'        => 'string',
						'description' => 'Style guide page title. If omitted, inferred from the HTML title tag or design_system_label.',
					],
					'html'                => [
						'type'        => 'string',
						'description' => 'Complete HTML document following the format spec. All CSS belongs inside `<style>` blocks in `<head>` and all JavaScript inside `<script>` blocks; there is no separate `css` or `js` parameter and unknown top-level keys are rejected.',
					],
					'post_type'           => array_merge(
						[
							'type'        => 'string',
							'description' => $this->post_type_service->build_post_type_description( $creatable_post_types ),
						],
						$creatable_post_types ? [ 'enum' => $creatable_post_types ] : []
					),
					'status'              => [
						'type'        => 'string',
						'enum'        => [ 'draft', 'publish' ],
						'description' => 'Post status. Default: draft.',
					],
					'design_system_uuid'  => [
						'type'        => [ 'string', 'null' ],
						'description' => 'Design system UUID. Omit to create from HTML tokens.',
					],
					'design_system_label' => [
						'type'        => 'string',
						'description' => 'Descriptive name for the new design system when auto-creating (e.g. "Coastal Bakery", "SaaS Dashboard"). Optional, falls back to the page title, then the <title> tag, then "Imported Design System". Ignored if design_system_uuid is provided.',
					],
				],
				'required'   => [ 'html' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [
					'readonly'    => false,
					'destructive' => false,
				],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'design-systems',
				'summary'     => 'Creates a style guide page showcasing a design system',
			],
		];
	}

	public function execute( array $input ) {
		return $this->generator->generate( $input );
	}
}
