<?php

namespace FL\DesignSystem\BlockEditor;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\BlockEditor\CustomBlockRenderer;
use FL\DesignSystem\Contracts\SettingsStoreInterface;
use FL\DesignSystem\Plugin;
use FL\DesignSystem\BlockEditor\WpBlockCssRenderer;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\Admin\AdminProvider;

class BlockEditorProvider {

	private Plugin $plugin;
	private SettingsStoreInterface $settings;
	private CustomBlockRenderer $custom_renderer;

	/**
	 * @param Plugin                 $plugin          Plugin instance.
	 * @param SettingsStoreInterface $settings        Settings store.
	 * @param CustomBlockRenderer    $custom_renderer Renderer for fl-ds/custom blocks.
	 */
	public function __construct(
		Plugin $plugin,
		SettingsStoreInterface $settings,
		CustomBlockRenderer $custom_renderer
	) {
		$this->plugin           = $plugin;
		$this->settings         = $settings;
		$this->custom_renderer  = $custom_renderer;
	}

	public function boot() {
		add_action( 'init', [ $this, 'register_blocks' ], 20 );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_editor_styles' ] );

		// Register flDsCss attribute and frontend rendering for non-DS blocks.
		WpBlockCssRenderer::init();
	}

	/**
	 * Register DS-owned Gutenberg block types.
	 */
	public function register_blocks() {
		$this->register_custom_block();
		$this->register_scope_block();
	}

	/**
	 * Register the fl-ds/custom block type for LLM-generated freeform blocks.
	 */
	public function register_custom_block(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'fl-ds/custom' ) ) {
			return;
		}

		register_block_type(
			'fl-ds/custom',
			[
				'api_version'     => 3,
				'title'           => 'Custom Block',
				'category'        => 'fl-ds-custom',
				'render_callback' => [ $this->custom_renderer, 'render' ],
				'attributes'      => [
					'blockId'  => [ 'type' => 'string',  'default' => '' ],
					'label'    => [ 'type' => 'string',  'default' => '' ],
					'content'  => [ 'type' => 'string',  'default' => '' ],
					'settings' => [
						'type'    => 'object',
						'default' => new \stdClass(),
					],
				],
				'supports'        => [
					'html'            => false,
					'customClassName' => false,
					'inserter'        => false,
				],
			]
		);
	}

	/**
	 * Register the fl-ds/scope block type.
	 *
	 * Server-side registration is required so Gutenberg's REST validation
	 * preserves the block delimiter on save. The block has no render
	 * callback — its only purpose is carrying a dsRef attribute that the
	 * JS edit component reads to inject DS CSS via useStyleOverride.
	 * `save` returns InnerBlocks.Content, so the serialized wrapper emits
	 * only its inner block markup on the frontend.
	 */
	public function register_scope_block(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'fl-ds/scope' ) ) {
			return;
		}

		register_block_type(
			'fl-ds/scope',
			[
				'api_version' => 3,
				'title'       => 'Design System Scope',
				'category'    => 'fl-ds-custom',
				'attributes'  => [
					'dsRef' => [
						'type'    => 'string',
						'default' => '',
					],
				],
				'supports'    => [
					'html'            => false,
					'inserter'        => false,
					'customClassName' => false,
				],
			]
		);
	}

	/**
	 * Enqueue block editor styles via enqueue_block_assets so they load
	 * inside the editor iframe where block edit components render.
	 */
	public function enqueue_editor_styles() {
		if ( ! is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'fl-design-system-blocks',
			$this->plugin->url . 'packages/ai-design-system/frontend/build/block-editor.css',
			[],
			$this->plugin->version,
		);
	}

	/**
	 * Enqueue block editor scripts with localized block data.
	 */
	public function enqueue_editor_assets() {
		wp_enqueue_script(
			'fl-design-system-blocks',
			$this->plugin->url . 'packages/ai-design-system/frontend/build/block-editor.js',
			[ 'wp-api-fetch', 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-data', 'wp-i18n', 'wp-plugins', 'wp-edit-post' ],
			$this->plugin->version,
			true,
		);

		$overrides_json    = $this->settings->get( 'designSystem.overrides', '' );
		$overrides         = is_string( $overrides_json ) ? json_decode( $overrides_json, true ) : null;

		$post_id        = get_the_ID();
		$page_overrides = $post_id
			? PageOverrideProvider::get_page_override_data( (int) $post_id )
			: [ 'dsRef' => null ];

		$current_user = wp_get_current_user();

		wp_localize_script('fl-design-system-blocks', 'FLDesignSystemBlocks', [
			'apiBaseUrl'          => rest_url( 'fl-design-system/v1' ),
			'restNonce'           => wp_create_nonce( 'wp_rest' ),
			'hasAiKey'            => (bool) $this->settings->get( 'ai.api_key' ),
			'settingsUrl'         => AdminProvider::page_url( '#/settings' ),
			'isAdmin'             => current_user_can( 'manage_options' ),
			'postId'              => $post_id,
			'designOverrides'     => is_array( $overrides ) ? $overrides : new \stdClass(),
			'pageOverrides'       => $page_overrides,
			'siteName'              => get_bloginfo( 'name' ),
			'debug'                 => ( defined( 'FL_DS_DEBUG' ) && FL_DS_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'structuredPageGen'     => defined( 'FL_DS_STRUCTURED_PAGE_GEN' ) && FL_DS_STRUCTURED_PAGE_GEN,
			'structuredBlocksGen'   => ! defined( 'FL_DS_STRUCTURED_BLOCKS_GEN' ) || FL_DS_STRUCTURED_BLOCKS_GEN,
			'canSendMessages'       => apply_filters( 'fl_ds_can_send_messages', true ),
			'canUseChat'            => WordPressAuth::user_can_create_content(),
			'canUseUnfilteredHtml'  => WordPressAuth::user_can_create_content(),
			'user'                  => [
				'name' => ! empty( $current_user->display_name ) ? $current_user->display_name : ( ! empty( $current_user->user_nicename ) ? $current_user->user_nicename : $current_user->user_login ),
			],
		]);
	}
}
