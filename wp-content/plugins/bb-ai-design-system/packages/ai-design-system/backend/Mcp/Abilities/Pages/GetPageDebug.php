<?php

namespace FL\DesignSystem\Mcp\Abilities\Pages;

use FL\DesignSystem\Generation\DebugProvider;
use FL\DesignSystem\Mcp\BaseAbility;

/**
 * MCP ability: get-page-debug.
 *
 * Returns the raw LLM-generated HTML and brief stored at generation time.
 * Gated by WP_DEBUG/FL_DS_DEBUG and the standard edit_post check (which
 * limits debug data to the post author or Editors and above).
 */
class GetPageDebug extends BaseAbility {

	public function name(): string {
		return 'beaver-builder-ai/get-page-debug';
	}

	public function definition(): array {
		return [
			'label'               => 'Get Page Debug Data',
			'description'         => 'Returns the raw LLM-generated HTML and brief for a page, stored during generation. Use this alongside get-page-html to compare what the LLM produced against what was parsed and imported. Only available when WP_DEBUG is enabled.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'WordPress post ID of the generated page.',
					],
				],
				'required'   => [ 'post_id' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true ],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'pages',
				'summary'     => 'Returns raw LLM output and generation brief for debugging',
			],
		];
	}

	public function execute( array $input ) {
		$debug_on = ( defined( 'FL_DS_DEBUG' ) && FL_DS_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		if ( ! $debug_on ) {
			return new \WP_Error(
				'debug_disabled',
				'Debug is not enabled. Set FL_DS_DEBUG or WP_DEBUG to true.',
				[ 'status' => 403 ]
			);
		}

		$post_id = (int) ( $input['post_id'] ?? 0 );

		// Access model: combined with the abilities-layer unfiltered_html gate,
		// validate_post() (edit_post) restricts debug data to the post author or
		// users with edit_others_posts (Editor and above). Intentional — debug
		// data may include another user's LLM-generated content, but Editors
		// need access to support customers and diagnose generation issues.
		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$debug = DebugProvider::get_debug( $post_id );

		if ( empty( $debug['raw_html'] ) ) {
			return new \WP_Error(
				'no_debug_data',
				'No debug data stored for this page. Generate the page first with WP_DEBUG enabled.',
				[ 'status' => 404 ]
			);
		}

		return $debug;
	}
}
