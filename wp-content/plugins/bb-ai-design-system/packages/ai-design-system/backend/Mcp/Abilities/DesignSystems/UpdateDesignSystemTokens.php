<?php

namespace FL\DesignSystem\Mcp\Abilities\DesignSystems;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\DesignSystem\SystemTokens;
use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\FontOverridesService;
use FL\DesignSystem\Mcp\Support\HashVerifier;

/**
 * MCP ability: update-design-system-tokens.
 *
 * Merges per-token overrides into the design system. Null values remove
 * tokens (except system tokens, which are protected). Optional
 * `font_overrides` patch is delegated to {@see FontOverridesService}.
 */
class UpdateDesignSystemTokens extends BaseAbility {

	private HashVerifier $hash_verifier;
	private FontOverridesService $font_overrides;

	public function __construct( HashVerifier $hash_verifier, FontOverridesService $font_overrides ) {
		$this->hash_verifier  = $hash_verifier;
		$this->font_overrides = $font_overrides;
	}

	public function name(): string {
		return 'beaver-builder-ai/update-design-system-tokens';
	}

	public function definition(): array {
		return [
			'label'               => 'Update Design Tokens',
			'description'         => 'Updates token values on an existing design system. Pass the token map via `overrides`. Merges overrides with existing tokens -- only the specified tokens are changed, the rest remain untouched. Set a token value to null to remove it. System tokens (--ds-system-*) are always preserved. When the designer requests changes like "warmer palette" or "tighter spacing", read current tokens first (via get-design-system), then apply targeted overrides here. After significant token changes, call update-design-system-guidance to update the creative direction to reflect the new design state. Requires the content_hash from get-design-system to prevent overwriting changes made since the design system was last fetched -- there is no override path, the agent must re-fetch on stale hash.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'design_system_uuid' => [
						'type'        => 'string',
						'description' => 'Design system UUID.',
					],
					'overrides'          => [
						'type'        => 'object',
						'description' => 'Map of token name to new CSS value (e.g., { "--ds-color-primary": "#8B4513" }). Merges with existing tokens. Set a value to null to remove a token.',
					],
					'font_overrides'     => [
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'Per-family font edits. Map family name to { "variants": "..." } to add/update, or null to remove. Other families are preserved. Use to add a Google Font when changing --ds-font-heading or --ds-font-body, or to drop a family that is no longer referenced. Variants follow the Google Fonts URL syntax (e.g., "wght@400;700" or "ital,wght@0,400;1,400"); empty string loads the runtime default weight set.',
					],
					'content_hash'       => [
						'type'        => 'string',
						'description' => 'Hash from get-design-system response. Required to prevent overwriting changes made since the design system was last fetched.',
					],
				],
				'required'   => [ 'design_system_uuid', 'overrides', 'content_hash' ],
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
				'summary'     => 'Updates token values on a design system (affects all pages)',
			],
		];
	}

	public function execute( array $input ) {
		$uuid         = $input['design_system_uuid'] ?? '';
		$overrides    = $input['overrides'] ?? [];
		$content_hash = $input['content_hash'] ?? '';

		if ( empty( $uuid ) || ! is_array( $overrides ) ) {
			return new \WP_Error(
				'missing_params',
				'design_system_uuid and overrides are required.',
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

		$structured = DesignSystemPostType::get_structured_data( $ds_post );
		$tokens     = $structured['tokens'];

		// Merge overrides: null values remove tokens, others update/add.
		foreach ( $overrides as $name => $value ) {
			$name = sanitize_text_field( $name );

			// Protect system tokens from removal.
			if ( null === $value && str_starts_with( $name, '--ds-system-' ) ) {
				continue;
			}

			if ( null === $value ) {
				unset( $tokens[ $name ] );
			} else {
				$tokens[ $name ] = sanitize_text_field( $value );
			}
		}

		// Ensure system token defaults are always present.
		foreach ( SystemTokens::DEFAULTS as $sys_name => $sys_value ) {
			if ( ! isset( $tokens[ $sys_name ] ) ) {
				$tokens[ $sys_name ] = $sys_value;
			}
		}

		$structured['tokens'] = $tokens;

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

		// Apply per-family font overrides if provided.
		if ( isset( $input['font_overrides'] ) ) {
			$font_error = $this->font_overrides->apply( $ds_post, $input['font_overrides'] );
			if ( null !== $font_error ) {
				return $font_error;
			}
		}

		return [
			'uuid'         => $uuid,
			'updated'      => true,
			'token_count'  => count( $tokens ),
			'message'      => 'Design tokens updated.',
			'next_steps'   => 'If this significantly changed the design direction (new palette, different typography, shifted mood), update the creative guidance via update-design-system-guidance with design_system_uuid "' . $uuid . '" to reflect the current state. Write in observational tone describing what the design now looks like.',
			'content_hash' => $this->hash_verifier->store_ds_mcp_hash( $ds_post ),
		];
	}
}
