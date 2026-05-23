<?php

namespace FL\DesignSystem\Mcp\Abilities\DesignSystems;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\FormatSpecLoader;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Page\PageExporter;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\Services\Parser\PageParser;

/**
 * MCP ability: analyze-page-design.
 *
 * Returns parsed section CSS plus the art-direction template for a page,
 * so the agent can write observational creative guidance after generating
 * a page (especially useful for new design systems).
 */
class AnalyzePageDesign extends BaseAbility {

	private PageResolver $page_resolver;
	private FormatSpecLoader $loader;

	public function __construct( PageResolver $page_resolver, FormatSpecLoader $loader ) {
		$this->page_resolver = $page_resolver;
		$this->loader        = $loader;
	}

	public function name(): string {
		return 'beaver-builder-ai/analyze-page-design';
	}

	public function definition(): array {
		return [
			'label'               => 'Analyze Page Design',
			'description'         => 'Returns the CSS context and art direction template for a recently generated page. Call this after generate-page when you created a new design system (no existing design_system_uuid). Use the returned section CSS and template to write observational creative guidance analyzing the design you just created. Then call update-design-system-guidance to save it. This is especially valuable after generating a test sheet for a new design system -- the section CSS from a test sheet (palette, typography, cards, buttons) provides rich material for writing comprehensive observational guidance that captures the full design language.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'The post ID returned by generate-page.',
					],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true ],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'design-systems',
				'summary'     => 'Returns CSS context and art direction for a generated page',
			],
		];
	}

	public function execute( array $input ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$adapter = $this->page_resolver->resolve_adapter( $post_id );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		// Export the page as HTML, then re-parse to get structured CSS.
		$export = PageExporter::export( $post_id, $adapter );
		$parsed = PageParser::parse( $export['html'] );

		// Build compact section CSS summaries.
		$section_css = [];
		foreach ( $parsed['sections'] as $section ) {
			if ( ! empty( $section['css'] ) ) {
				$section_css[] = [
					'label' => $section['label'] ?? $section['id'] ?? '',
					'tag'   => $section['tag'] ?? 'section',
					'css'   => $section['css'],
				];
			}
		}

		// Load templates from the shared spec.
		$spec_dir         = FL_DESIGN_SYSTEM_DIR . 'packages/ai-design-system/data/spec/';
		$brief_guidance   = $this->loader->load_spec_file( $spec_dir . 'consumer/brief-guidance.md' );
		$art_direction    = '';
		$business_context = '';

		$parts = explode( '## Art Direction Guidance', $brief_guidance );
		if ( isset( $parts[1] ) ) {
			$art_direction = trim( explode( '## Using Creative Direction', $parts[1] )[0] );
		}
		$bc_parts = explode( '## Business Context Guidance', $brief_guidance );
		if ( isset( $bc_parts[1] ) ) {
			$business_context = trim( $bc_parts[1] );
		}

		// Load the user's original brief from the DS (if any).
		$ds_uuid_meta = get_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, true );
		$ds_uuid      = $ds_uuid_meta ? $ds_uuid_meta : null;
		$brief        = '';
		if ( $ds_uuid ) {
			$ds_post = DesignSystemPostType::get_by_uuid( $ds_uuid );
			if ( $ds_post ) {
				$brief_meta = get_post_meta( $ds_post->ID, DesignSystemPostType::META_BRIEF, true );
				$brief      = $brief_meta ? $brief_meta : '';
			}
		}

		return [
			'design_system_uuid'        => $ds_uuid,
			'base_css'                  => $parsed['designSystem']['base'] ?? '',
			'section_css'               => $section_css,
			'brief'                     => $brief,
			'art_direction_template'    => $art_direction,
			'business_context_template' => $business_context,
			'instructions'              => 'Analyze the CSS above and write an art direction document (~1,200 words) in observational tone. Reference token names (--ds-*) and class names (bb-*) by name. Do NOT include raw CSS or hex values. Follow art_direction_template for structure. Then write the ## Business Context section following business_context_template. Call update-design-system-guidance with the guidance text and business context as brief.',
		];
	}
}
