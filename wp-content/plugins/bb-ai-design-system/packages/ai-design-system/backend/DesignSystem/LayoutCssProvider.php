<?php

namespace FL\DesignSystem\DesignSystem;

use FL\DesignSystem\Plugin;

class LayoutCssProvider {

	private Plugin $plugin;

	private bool $has_enqueued = false;

	/**
	 * @param Plugin $plugin Plugin instance.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function boot() {
		// Priority 1 so our handles are registered AND inline content is
		// attached before any default-priority consumer (BB's
		// fl-builder-layout-<id> register, DesignSystemAssetProvider's
		// bb-design-system-page-css register) declares us as a dep.
		// WordPress 6.9.1+ emits doing_it_wrong when wp_register_style is
		// called with unregistered deps.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 1 );
		add_action( 'enqueue_block_assets', [ $this, 'enqueue' ], 1 );
	}

	/**
	 * Register both context layout handles unconditionally, then enqueue and
	 * attach inline CSS for the active context. Listing both handles as
	 * registered keeps downstream dep declarations valid regardless of which
	 * context resolves for this request (a non-BB frontend never registers
	 * the beaver-builder handle on its own, and vice versa).
	 *
	 * Defensively registers bb-design-system-css too — DesignSystemAssetProvider
	 * also registers it (idempotent), but doing it here ensures our layout
	 * handles' deps are valid even when this callback runs first.
	 */
	public function enqueue(): void {
		if ( $this->has_enqueued ) {
			return;
		}
		$this->has_enqueued = true;

		if ( ! wp_style_is( 'bb-design-system-css', 'registered' ) ) {
			wp_register_style( 'bb-design-system-css', false );
		}

		foreach ( [ 'beaver-builder', 'block-editor' ] as $context ) {
			$handle = "bb-design-layout-css-{$context}";
			if ( ! wp_style_is( $handle, 'registered' ) ) {
				wp_register_style( $handle, false, [ 'bb-design-system-css' ] );
			}
		}

		$is_bb_page = class_exists( 'FLBuilderModel' )
			&& \FLBuilderModel::is_builder_enabled();

		$context = $is_bb_page ? 'beaver-builder' : 'block-editor';
		$handle  = "bb-design-layout-css-{$context}";

		wp_enqueue_style( $handle );

		$default_file = $this->plugin->dir
			. 'packages/ai-design-system/data/shared/default-layout-css/'
			. $context . '.css';

		if ( ! file_exists( $default_file ) ) {
			return;
		}

		$css = file_get_contents( $default_file );

		if ( false === $css || '' === trim( $css ) ) {
			return;
		}

		wp_add_inline_style( $handle, $css );
	}
}
