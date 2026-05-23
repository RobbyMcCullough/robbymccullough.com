<?php

namespace FL\DesignSystem\Mcp\Abilities\DesignSystems;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\DesignSystem\SystemTokens;
use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\FormatSpecLoader;
use FL\DesignSystem\Mcp\Support\HashVerifier;

/**
 * MCP ability: get-design-system.
 *
 * Returns a design system's full context (tokens, CSS, fonts, format spec,
 * guidance) for use during page generation. Supports an `include` filter
 * to trim the response payload.
 */
class GetDesignSystem extends BaseAbility {

	private FormatSpecLoader $loader;
	private HashVerifier $hash_verifier;

	public function __construct( FormatSpecLoader $loader, HashVerifier $hash_verifier ) {
		$this->loader        = $loader;
		$this->hash_verifier = $hash_verifier;
	}

	public function name(): string {
		return 'beaver-builder-ai/get-design-system';
	}

	public function definition(): array {
		return [
			'label'               => 'Get Design System',
			'description'         => 'Returns a design system\'s full context (tokens, CSS, fonts, format spec) for generating pages with an existing design system. Call this after list-design-systems when the user has chosen a system. The response contains the format spec, design tokens, and creative direction your HTML should follow. If the design system has guidance, use it to maintain visual coherence across pages. For new design systems, use get-format-spec instead. Pass include to trim the payload: e.g. include: ["tokens"] for a token-only check, or omit for the full response.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'default'    => [],
				'properties' => [
					'design_system_uuid' => [
						'type'        => [ 'string', 'null' ],
						'description' => 'Design system UUID. Omit or pass null for site default.',
					],
					'include'            => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => [ 'tokens', 'css', 'systemCss', 'js', 'fonts', 'brief', 'guidance', 'format_spec' ],
						],
						'description' => 'Optional. Fields to return. Omit (or pass []) to return everything (default). Pass a subset to trim the payload (e.g. ["tokens"] for a token-only check, ["tokens", "guidance"] for creative direction without the full CSS or format spec). uuid, label, context_note, and content_hash are always returned.',
					],
				],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true ],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'design-systems',
				'summary'     => "Loads a design system's tokens, CSS, and creative direction",
			],
		];
	}

	public function execute( array $input ) {
		$uuid    = $input['design_system_uuid'] ?? null;
		$include = ! empty( $input['include'] ) ? $input['include'] : null;

		if ( $uuid ) {
			$post = DesignSystemPostType::get_by_uuid( $uuid );
		} else {
			$post = DesignSystemPostType::get_default();
		}

		if ( ! $post ) {
			return new \WP_Error(
				'not_found',
				$uuid
					? 'Design system not found for the given UUID.'
					: 'No default design system is configured.',
				[ 'status' => 404 ]
			);
		}

		if ( ! WordPressAuth::can_edit_design_system( $post ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'You do not have permission to access this design system.',
				[ 'status' => 403 ]
			);
		}

		$ds_response = DesignSystemPostType::format_for_response( $post );
		$structured  = DesignSystemPostType::get_structured_data( $post );

		$full_css   = DesignSystemPostType::reconstruct_css( $structured );
		$system_css = SystemTokens::get_css_for_tokens( $structured['tokens'] );

		$want_format_spec = ( null === $include ) || in_array( 'format_spec', $include, true );
		$format_spec      = $want_format_spec
			? $this->loader->load_format_spec( 'creation', [ 'has_design_system' => true ] )
			: null;

		$response = [
			'uuid'         => $ds_response['uuid'],
			'label'        => $ds_response['label'],
			'context_note' => 'IMPORTANT: The CSS, tokens, and guidance below define the visual foundation for your HTML. Reference token names (--ds-*) in your CSS. Follow the guidance to maintain visual coherence across pages. You may deviate when the user\'s request calls for something different.',
			'tokens'       => $ds_response['tokens'],
			'css'          => $full_css,
			'systemCss'    => $system_css,
			'js'           => $ds_response['js'],
			'fonts'        => $ds_response['fonts'],
			'brief'        => $ds_response['brief'],
			'guidance'     => $ds_response['guidance'],
			'content_hash' => $this->hash_verifier->compute_ds_content_hash( $post ),
		];

		if ( $want_format_spec ) {
			$response['format_spec'] = $format_spec;
		}

		return $this->apply_include_filter(
			$response,
			$include,
			[ 'uuid', 'label', 'context_note', 'content_hash' ]
		);
	}

	/**
	 * Filter a response payload by an optional include list.
	 *
	 * When $include is null, returns $data unchanged. Otherwise returns only
	 * the keys that appear in $include or $always. Keys absent from $data are
	 * silently ignored. Order follows $data's existing key order.
	 */
	private function apply_include_filter( array $data, ?array $include, array $always = [] ): array {
		if ( null === $include ) {
			return $data;
		}

		$keep = array_unique( array_merge( $always, $include ) );

		$filtered = [];
		foreach ( $data as $key => $value ) {
			if ( in_array( $key, $keep, true ) ) {
				$filtered[ $key ] = $value;
			}
		}
		return $filtered;
	}
}
