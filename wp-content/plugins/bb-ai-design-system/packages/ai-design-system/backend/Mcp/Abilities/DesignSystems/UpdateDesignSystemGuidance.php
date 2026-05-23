<?php

namespace FL\DesignSystem\Mcp\Abilities\DesignSystems;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\DesignSystem\GuidanceProcessor;
use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\HashVerifier;

/**
 * MCP ability: update-design-system-guidance.
 *
 * Saves creative guidance and an optional brief on a design system.
 * Hash-gated like other DS writes — agents must re-fetch on stale hash.
 */
class UpdateDesignSystemGuidance extends BaseAbility {

	private HashVerifier $hash_verifier;

	public function __construct( HashVerifier $hash_verifier ) {
		$this->hash_verifier = $hash_verifier;
	}

	public function name(): string {
		return 'beaver-builder-ai/update-design-system-guidance';
	}

	public function definition(): array {
		return [
			'label'               => 'Update Design Guidance',
			'description'         => 'Saves creative guidance and business brief on a design system. Apply the user\'s requested changes to guidance; preserve unchanged sections verbatim. Full rewrite only on explicit user request. Use this after analyze-page-design to save the guidance you wrote for a newly created design system. Also use this when the user asks to refine specific aspects of an existing design system\'s guidance, when adding new content based on token or section changes, or when the user supplies new spec content to merge in. When the user supplies new spec content, the same Mode A rules from consumer/brief-guidance.md apply: preserve the spec\'s PROSE verbatim. POSITIONAL RULE FOR IDENTIFIERS: translation happens only in code positions; backtick mentions in prose, tables, lists, headings, or checklists stay verbatim even when the prose references the identifier functionally. Token code positions: `var(--name)` calls and `--name: value;` declarations; these must resolve to a canonical `--ds-{category}-{name}` token, a literal value, or plain prose -- never a non-existent token name. Class code positions: `class="X"` HTML attribute values and CSS rule selector heads (`.X { ... }`); classes destined for `/* @base */` translate to their `bb-` prefixed form per `core/stylesheet-format.md` (`.accent` -> `.bb-accent`), while `/* @section */` classes are exempt. Drop `:root { ... }`, bare top-level `--name: value;` custom-property declarations, `[data-brand|theme|mode|color-scheme] { ... }`, `@media (prefers-color-scheme: ...) { ... }`, and `@import url(...)` / `<link rel="stylesheet">` blocks loading assets the design system already loads, from code fences. Keep component / layout / interaction examples and translate their token references. The storage layer applies the code-fence strips as a backstop and surfaces any surviving unknown `var(--*)` references on the response. Write guidance in observational style describing what was designed ("the palette centers on warm earth tones", "the accent blue appears on interactive elements") rather than prescriptive rules ("always use blue for buttons"), unless the source spec is itself prescriptive in voice, in which case preserve the spec\'s voice. For existing design systems outside of an active design iteration, only update if the user explicitly asks. Requires the content_hash from get-design-system to prevent overwriting changes made since the design system was last fetched, there is no override path; the agent must re-fetch on stale hash.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'design_system_uuid' => [
						'type'        => 'string',
						'description' => 'Design system UUID.',
					],
					'guidance'           => [
						'type'        => 'string',
						'description' => 'Art direction document (~1,200 words) analyzing the design in observational tone. Must be a non-empty string.',
					],
					'brief'              => [
						'type'        => 'string',
						'description' => 'Business context summary (under 200 words). Optional -- omit if the DS already has a brief.',
					],
					'content_hash'       => [
						'type'        => 'string',
						'description' => 'Hash from get-design-system response. Required to prevent overwriting changes made since the design system was last fetched.',
					],
				],
				'required'   => [ 'design_system_uuid', 'guidance', 'content_hash' ],
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
				'summary'     => 'Saves creative guidance and business brief on a design system',
			],
		];
	}

	public function execute( array $input ) {
		$uuid         = $input['design_system_uuid'] ?? '';
		$brief        = $input['brief'] ?? null;
		$content_hash = $input['content_hash'] ?? '';

		if ( empty( $uuid ) ) {
			return new \WP_Error(
				'missing_params',
				'design_system_uuid is required.',
				[ 'status' => 400 ]
			);
		}

		if ( ! array_key_exists( 'guidance', $input ) || ! is_string( $input['guidance'] ) || '' === trim( $input['guidance'] ) ) {
			return new \WP_Error(
				'missing_params',
				'guidance is required and must be a non-empty string. Pass the full art direction document text.',
				[ 'status' => 400 ]
			);
		}

		$guidance = $input['guidance'];

		if ( ! is_string( $content_hash ) || '' === $content_hash ) {
			return new \WP_Error(
				'missing_content_hash',
				'content_hash is required. Call get-design-system to fetch the current hash before updating.',
				[ 'status' => 400 ]
			);
		}

		$ds_post = DesignSystemPostType::get_by_uuid( $uuid );
		if ( ! $ds_post ) {
			return new \WP_Error(
				'not_found',
				'Design system not found.',
				[ 'status' => 404 ]
			);
		}

		if ( ! WordPressAuth::can_edit_design_system( $ds_post ) ) {
			return new \WP_Error(
				'rest_forbidden',
				'You do not have permission to edit this design system.',
				[ 'status' => 403 ]
			);
		}

		$hash_error = $this->hash_verifier->verify_ds_content_hash( $ds_post, $content_hash );
		if ( null !== $hash_error ) {
			return $hash_error;
		}

		$guidance = GuidanceProcessor::strip_conflicting_blocks( $guidance );

		// Sanitize matches the create path (DesignSystemPostType::create).
		update_post_meta( $ds_post->ID, DesignSystemPostType::META_GUIDANCE, DesignSystemPostType::sanitize_guidance( $guidance ) );

		$brief_written = false;
		if ( null !== $brief && '' !== $brief ) {
			update_post_meta( $ds_post->ID, DesignSystemPostType::META_BRIEF, DesignSystemPostType::sanitize_guidance( (string) $brief ) );
			$brief_written = true;
		}

		$structured     = DesignSystemPostType::get_structured_data( $ds_post );
		$unknown_tokens = GuidanceProcessor::find_unknown_token_refs( $guidance, array_keys( $structured['tokens'] ) );

		$response = [
			'uuid'            => $uuid,
			'updated'         => true,
			'message'         => 'Creative guidance saved on the design system.',
			'guidance_length' => mb_strlen( $guidance ),
			'content_hash'    => $this->hash_verifier->store_ds_mcp_hash( $ds_post ),
		];

		if ( $brief_written ) {
			$response['brief_length'] = mb_strlen( (string) $brief );
		}

		if ( ! empty( $unknown_tokens ) ) {
			$response['warnings'] = [
				sprintf(
					'Guidance references tokens not present in the design system: %s. These will not resolve at render time.',
					implode( ', ', $unknown_tokens )
				),
			];
		}

		return $response;
	}
}
