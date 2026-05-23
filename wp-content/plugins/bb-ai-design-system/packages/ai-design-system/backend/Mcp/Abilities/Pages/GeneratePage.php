<?php

namespace FL\DesignSystem\Mcp\Abilities\Pages;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\PageGenerator;
use FL\DesignSystem\Mcp\Support\PostTypeService;

/**
 * MCP ability: generate-page.
 *
 * Creates a new WordPress page or post from a complete HTML document.
 * Execution delegates to {@see PageGenerator}, the same shared instance the
 * registry passes to GenerateStyleGuide so both abilities run the exact
 * same pipeline.
 */
class GeneratePage extends BaseAbility {

	private PageGenerator $generator;
	private PostTypeService $post_type_service;

	public function __construct( PageGenerator $generator, PostTypeService $post_type_service ) {
		$this->generator         = $generator;
		$this->post_type_service = $post_type_service;
	}

	public function name(): string {
		return 'beaver-builder-ai/generate-page';
	}

	public function definition(): array {
		$creatable_post_types = $this->post_type_service->get_creatable_post_type_slugs();

		return [
			'label'               => 'Generate Page',
			'description'         => 'Pass a complete HTML document via `html`. All CSS must be embedded inside `<style>` blocks in `<head>` and all JavaScript inside `<script>` blocks. There are no separate `css` or `js` parameters. Creates a new WordPress page or post using Beaver Builder AI. Works with both Beaver Builder and the WordPress block editor; the correct editor is selected automatically based on site configuration. Use this when the user wants to build a specific page (landing page, about page, portfolio, etc.). If the user wants to explore a design system visually without a specific page in mind, use generate-style-guide instead. PREPARATION: Call list-post-types to check which post types are available, then use the correct one. DESIGN SELECTION: Ask the user if they would like to use an existing design system or start fresh. If existing, call get-design-system to load tokens and creative direction. If starting fresh, call get-format-spec and create a design system along with the page. SPEC INPUT: If the user has supplied a brand spec, design brief, or design system document, call `create-design-system` first and pass the resulting UUID via `design_system_uuid`. Do not attempt to bake the spec into this tool\'s call; spec preservation lives on `create-design-system`, not here. APPLYING GUIDANCE: When the design system has guidance, locate where each numeric value (letter-spacing, font-size, line-height, padding, radius) is described in the guidance and apply it to that specific element. Do not transfer values across elements based on what "feels right" -- if the guidance puts 0.02em letter-spacing on numbered step numbers, do not put 0.02em on the nav wordmark. IMPORTANT: Do not describe your design plan in detail before calling this tool. Ask the user questions to understand their vision, confirm in 2-3 sentences, then go straight to writing the HTML. Creative energy goes into the HTML, not a written brief. When auto-creating a design system (no design_system_uuid), pass a design_system_label when you have a fitting name; if omitted, the page title or <title> tag will be used as the label. Pages are created as drafts by default. AFTER CREATING: First, share the draft URL with the user. Then, if the response includes `next_steps` (auto-DS without follow-up direction), call `analyze-page-design` followed by `update-design-system-guidance` to populate consistency direction, otherwise subsequent pages will lack it.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'title'               => [
						'type'        => 'string',
						'description' => 'Page title. If omitted, inferred from the HTML title tag or design_system_label.',
					],
					'html'                => [
						'type'        => 'string',
						'description' => 'Complete HTML document following the format spec. All CSS belongs inside `<style>` blocks in `<head>` and all JavaScript inside `<script>` blocks; there is no separate `css` or `js` parameter.',
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
				'subcategory' => 'pages',
				'summary'     => 'Creates a new page with AI-generated content',
			],
		];
	}

	public function execute( array $input ) {
		return $this->generator->generate( $input );
	}
}
