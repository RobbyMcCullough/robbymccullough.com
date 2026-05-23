<?php

namespace FL\DesignSystem\Mcp\Abilities\DesignSystems;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\FontOverridesService;
use FL\DesignSystem\Mcp\Support\HashVerifier;

/**
 * MCP ability: update-design-system-assets.
 *
 * Replaces shared design system assets — base CSS, reset CSS, base JS,
 * fonts — without touching tokens. Each provided string field replaces
 * its existing value; omitted fields are preserved. Hash-gated.
 */
class UpdateDesignSystemAssets extends BaseAbility {

	private HashVerifier $hash_verifier;
	private FontOverridesService $font_overrides;

	public function __construct( HashVerifier $hash_verifier, FontOverridesService $font_overrides ) {
		$this->hash_verifier  = $hash_verifier;
		$this->font_overrides = $font_overrides;
	}

	public function name(): string {
		return 'beaver-builder-ai/update-design-system-assets';
	}

	public function definition(): array {
		return [
			'label'               => 'Update Design System Assets',
			'description'         => 'Updates shared design system assets -- base CSS, reset CSS, base JavaScript, and/or fonts -- without touching tokens. WARNING: Base assets are shared across ALL pages using this design system. Updating them can change the appearance of every page on the site. Before calling this tool, you MUST: (1) Inform the user that this change will affect all pages using this design system. (2) Wait for the user to explicitly confirm they want to proceed. (3) Read current assets first with get-design-system to write a complete replacement that preserves what should stay. Each provided string field (base, reset, js) replaces the existing value entirely. Omitted fields are left unchanged. At least one field (base, reset, js, font_overrides) is required. Requires the content_hash from get-design-system to prevent overwriting changes made since the design system was last fetched -- there is no override path, the agent must re-fetch on stale hash.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'design_system_uuid' => [
						'type'        => 'string',
						'description' => 'Design system UUID.',
					],
					'base'               => [
						'type'        => 'string',
						'description' => 'Base CSS string -- shared cross-page styles. Replaces existing base CSS.',
					],
					'reset'              => [
						'type'        => 'string',
						'description' => 'Reset CSS string. Replaces existing reset CSS.',
					],
					'js'                 => [
						'type'        => 'string',
						'description' => 'Base JavaScript string -- shared cross-page utilities. Replaces existing base JS.',
					],
					'font_overrides'     => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'Per-family font edits. Map family name to { "variants": "..." } to add/update, or null to remove. Other families are preserved. Variants follow the Google Fonts URL syntax (e.g., "wght@400;700" or "ital,wght@0,400;1,400"); empty string loads the runtime default weight set.',
					],
					'content_hash'       => [
						'type'        => 'string',
						'description' => 'Hash from get-design-system response. Required to prevent overwriting changes made since the design system was last fetched.',
					],
				],
				'required'   => [ 'design_system_uuid', 'content_hash' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [
					'readonly'    => false,
					'destructive' => true,
				],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'design-systems',
				'summary'     => 'Updates shared CSS, JS, and fonts on a design system (affects all pages)',
			],
		];
	}

	public function execute( array $input ) {
		$uuid         = $input['design_system_uuid'] ?? '';
		$content_hash = $input['content_hash'] ?? '';

		if ( empty( $uuid ) ) {
			return new \WP_Error(
				'missing_params',
				'design_system_uuid is required.',
				[ 'status' => 400 ]
			);
		}

		$has_field = isset( $input['base'] ) || isset( $input['reset'] ) || isset( $input['js'] ) || isset( $input['font_overrides'] );
		if ( ! $has_field ) {
			return new \WP_Error(
				'missing_params',
				'At least one field (base, reset, js, font_overrides) must be provided.',
				[ 'status' => 400 ]
			);
		}

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

		$structured     = DesignSystemPostType::get_structured_data( $ds_post );
		$updated_fields = [];

		if ( isset( $input['base'] ) ) {
			$structured['base'] = DesignSystemPostType::sanitize_css( $input['base'] );
			$updated_fields[]   = 'base CSS';
		}

		if ( isset( $input['reset'] ) ) {
			$structured['reset'] = DesignSystemPostType::sanitize_css( $input['reset'] );
			$updated_fields[]    = 'reset CSS';
		}

		$result = wp_update_post( [
			'ID'           => $ds_post->ID,
			'post_content' => wp_slash( wp_json_encode( $structured ) ),
		], true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Reflect the just-written post_content on the in-memory post so the
		// post-write hash is computed against fresh state without a re-fetch.
		$ds_post->post_content = wp_json_encode( $structured );

		if ( isset( $input['js'] ) ) {
			update_post_meta( $ds_post->ID, DesignSystemPostType::META_BASE_JS, DesignSystemPostType::sanitize_js( $input['js'] ) );
			$updated_fields[] = 'base JS';
		}

		if ( isset( $input['font_overrides'] ) ) {
			$font_error = $this->font_overrides->apply( $ds_post, $input['font_overrides'] );
			if ( null !== $font_error ) {
				return $font_error;
			}
			$updated_fields[] = 'fonts';
		}

		return [
			'uuid'           => $uuid,
			'updated'        => true,
			'updated_fields' => $updated_fields,
			'message'        => 'Design system assets updated: ' . implode( ', ', $updated_fields ) . '.',
			'content_hash'   => $this->hash_verifier->store_ds_mcp_hash( $ds_post ),
		];
	}
}
