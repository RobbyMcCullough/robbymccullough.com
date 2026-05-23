<?php

namespace FL\DesignSystem\BeaverBuilder;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\Contracts\SettingsStoreInterface;
use FL\DesignSystem\Plugin;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\Admin\AdminProvider;

class BeaverBuilderAssetProvider {

	private Plugin $plugin;
	private SettingsStoreInterface $settings;
	private string $module_namespace;

	/**
	 * @param Plugin                   $plugin           Plugin instance.
	 * @param SettingsStoreInterface   $settings         Settings store.
	 * @param string                   $module_namespace Module namespace prefix (e.g. 'ds', 'tw').
	 */
	public function __construct(
		Plugin $plugin,
		SettingsStoreInterface $settings,
		string $module_namespace = 'ds',
	) {
		$this->plugin           = $plugin;
		$this->settings         = $settings;
		$this->module_namespace = $module_namespace;
	}

	/**
	 * Register the enqueue hook.
	 */
	public function boot() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );

		// Registered unconditionally (not gated on is_builder_active()) so the
		// dep filter applies on every frontend page render where BB enqueues
		// its layout CSS, not just inside active edit sessions. WP silently
		// drops unregistered handles, so missing DS targets are a safe no-op.
		add_filter( 'fl_builder_layout_style_dependencies', [ $this, 'add_ds_layout_deps' ] );
	}

	/**
	 * Prepend DS handles to BB's layout CSS dependencies so the WP style
	 * printer emits DS bulk and shared layout CSS before BB's layout cache.
	 * See agents/decisions/css-load-order-via-bb-style-deps.md.
	 *
	 * @param  array $deps Existing dependencies from prior filter callbacks.
	 * @return array
	 */
	public function add_ds_layout_deps( $deps ) {
		$ds_deps = [
			'bb-design-system-css',
			'bb-design-layout-css-beaver-builder',
			'bb-design-system-page-css',
		];
		if ( ! is_array( $deps ) ) {
			return $ds_deps;
		}
		return array_values( array_merge( $deps, $ds_deps ) );
	}

	/**
	 * Get the post ID for the current builder session.
	 *
	 * Uses $wp_the_query (never overridden by Themer's preview query)
	 * instead of FLBuilderModel::get_post_id() which can fall through
	 * to $post->ID during wp_enqueue_scripts when Themer has replaced
	 * $post with a preview post.
	 *
	 * @return int|false
	 */
	private function get_builder_post_id() {
		global $wp_the_query;
		if ( isset( $wp_the_query->post ) && $wp_the_query->post instanceof \WP_Post ) {
			return (int) $wp_the_query->post->ID;
		}
		return \FLBuilderModel::get_post_id();
	}

	/**
	 * Enqueue scripts and styles for the BB builder UI.
	 */
	public function enqueue() {
		if ( ! \FLBuilderModel::is_builder_active() ) {
			return;
		}

		$builder_handle = \FLBuilder::is_debug() ? 'fl-builder' : 'fl-builder-min';

		wp_enqueue_script(
			'fl-design-system-beaver-builder',
			$this->plugin->url . 'packages/ai-design-system/frontend/build/beaver-builder.js',
			[ $builder_handle ],
			$this->plugin->version,
			true,
		);

		wp_enqueue_style(
			'fl-design-system-beaver-builder',
			$this->plugin->url . 'packages/ai-design-system/frontend/build/beaver-builder.css',
			[],
			$this->plugin->version,
		);

		$post_id        = $this->get_builder_post_id();
		$page_overrides = $post_id
			? PageOverrideProvider::get_page_override_data( (int) $post_id )
			: [ 'dsRef' => null ];

		$current_user = wp_get_current_user();

		wp_localize_script('fl-design-system-beaver-builder', 'FLDesignSystemBeaverBuilder', [
			'apiBaseUrl'         => rest_url( 'fl-design-system/v1' ),
			'restNonce'          => wp_create_nonce( 'wp_rest' ),
			'hasAiKey'           => (bool) $this->settings->get( 'ai.api_key' ),
			'settingsUrl'        => AdminProvider::page_url( '#/settings' ),
			'isAdmin'            => current_user_can( 'manage_options' ),
			'postId'             => $post_id,
			'moduleNamespace'    => $this->module_namespace,
			'pageOverrides'      => $page_overrides,
			'siteName'              => get_bloginfo( 'name' ),
			'canSendMessages'       => apply_filters( 'fl_ds_can_send_messages', true ),
			'debug'                 => ( defined( 'FL_DS_DEBUG' ) && FL_DS_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'structuredPageGen'     => defined( 'FL_DS_STRUCTURED_PAGE_GEN' ) && FL_DS_STRUCTURED_PAGE_GEN,
			'structuredBlocksGen'   => ! defined( 'FL_DS_STRUCTURED_BLOCKS_GEN' ) || FL_DS_STRUCTURED_BLOCKS_GEN,
			'canUseChat'            => WordPressAuth::user_can_create_content(),
			'canUseUnfilteredHtml'  => WordPressAuth::user_can_create_content(),
			'user'                  => [
				'name' => ! empty( $current_user->display_name ) ? $current_user->display_name : ( ! empty( $current_user->user_nicename ) ? $current_user->user_nicename : $current_user->user_login ),
			],
		]);
	}
}
