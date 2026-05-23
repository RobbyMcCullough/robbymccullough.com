<?php

namespace FL\DesignSystem\DesignSystem;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Form\FormRuntime;
use FL\DesignSystem\Form\FormSubmissionProvider;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\DesignSystem\DesignSystemCssBuilder;
use FL\DesignSystem\Rendering\JsShim;
use FL\DesignSystem\Font\FontEntry;
use FL\DesignSystem\Font\GoogleFontsUrl;

/**
 * Enqueues design system CSS and JS from the fl-design-system custom post type.
 *
 * Replaces DesignTokenProvider, BaseCssProvider, and BaseJsProvider.
 * DS assets are stored as structured JSON { tokens, reset, base } in
 * post_content. CSS is reconstructed at enqueue time. Base JS is stored
 * in _fl_ds_base_js meta.
 */
class DesignSystemAssetProvider {

	private bool $has_enqueued = false;

	public function boot() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'enqueue_block_assets', [ $this, 'enqueue' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_form_runtime' ] );
		add_action( 'wp_footer', [ $this, 'emit_form_runtime' ], 5 );
	}

	/**
	 * Register the form runtime script handle on the frontend.
	 *
	 * Registration only — the actual enqueue happens at wp_footer, and
	 * only if at least one form rendered on the page. Registering early
	 * keeps the handle known to WordPress so wp_script_is() lookups and
	 * dependency declarations behave as expected.
	 */
	public function register_form_runtime(): void {
		if ( is_admin() ) {
			return;
		}

		$file_path = defined( 'FL_DESIGN_SYSTEM_FILE' ) ? FL_DESIGN_SYSTEM_FILE : '';
		if ( '' === $file_path ) {
			return;
		}

		$script_rel = 'packages/ai-design-system/frontend/build/form-runtime.js';
		$script_url = plugins_url( $script_rel, $file_path );
		$version    = defined( 'FL_DESIGN_SYSTEM_VERSION' ) ? FL_DESIGN_SYSTEM_VERSION : '0';

		wp_register_script( 'fl-ds-form-runtime', $script_url, [], $version, true );
	}

	/**
	 * Emit the form runtime at wp_footer when a form has rendered.
	 *
	 * Rendering a design system form calls FormRuntime::mark_needed()
	 * (via FormFieldInjector), which flips the flag checked here.
	 * Form-less pages get no runtime script, no inline config, and no
	 * runtime CSS, avoiding a wasted REST nonce in page caches.
	 *
	 * The runtime CSS is inlined (~586 bytes) so it lands in the same
	 * footer emission as the script. This sidesteps the FOUC that a
	 * footer-enqueued stylesheet would cause on form render.
	 */
	public function emit_form_runtime(): void {
		if ( is_admin() || ! FormRuntime::is_needed() ) {
			return;
		}

		$file_path = defined( 'FL_DESIGN_SYSTEM_FILE' ) ? FL_DESIGN_SYSTEM_FILE : '';
		if ( '' === $file_path ) {
			return;
		}

		wp_enqueue_script( 'fl-ds-form-runtime' );

		$config = [
			'endpoint' => rest_url(
				FormSubmissionProvider::REST_NAMESPACE . FormSubmissionProvider::REST_ROUTE
			),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'strings'  => [
				'submitting' => __( 'Sending…', 'fl-design-system' ),
				'sent'       => __( 'Sent', 'fl-design-system' ),
				'error'      => __( 'Sorry, something went wrong. Please try again.', 'fl-design-system' ),
			],
		];

		wp_add_inline_script(
			'fl-ds-form-runtime',
			'window.FLDesignSystemFormRuntime=' . wp_json_encode( $config ) . ';',
			'before'
		);

		$css_path = dirname( $file_path ) . '/packages/ai-design-system/frontend/build/form-runtime.css';
		if ( is_readable( $css_path ) ) {
			$css = file_get_contents( $css_path );
			if ( false !== $css && '' !== $css ) {
				echo '<style id="fl-ds-form-runtime-inline-css">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		}
	}

	/**
	 * Enqueue design system CSS, JS, and fonts.
	 *
	 * On the frontend (wp_enqueue_scripts), emits DS CSS, page CSS, base JS,
	 * and Google Fonts. In admin block editor contexts, this runs via
	 * `enqueue_block_assets` to register the `bb-design-system-css` handle
	 * for dependents like LayoutCssProvider; the DS CSS itself is delivered
	 * to editor iframes by BlockEditorSettingsFilter via settings.styles.
	 */
	public function enqueue() {
		// enqueue() is hooked to both wp_enqueue_scripts and enqueue_block_assets,
		// which both fire on frontend BB pages. Use a private flag rather than
		// wp_style_is( ..., 'enqueued' ) because that check returns true for
		// transitive deps too — once LayoutCssProvider enqueues a layout handle
		// that depends on bb-design-system-css, the style-is-enqueued query
		// reports bb-design-system-css as enqueued even though we haven't put
		// it in the queue ourselves, which would mask the first run.
		if ( $this->has_enqueued ) {
			return;
		}
		$this->has_enqueued = true;

		$post_id = $this->get_current_post_id();
		$ds_post = $this->resolve_design_system_post();

		// Ensure the primary handle exists for dependents even when no DS resolves.
		// Registered unconditionally so it's a safe target for the
		// fl_builder_layout_style_dependencies filter and for BlockEditorSettingsFilter.
		wp_register_style( 'bb-design-system-css', false );
		wp_enqueue_style( 'bb-design-system-css' );

		// Page CSS handle, registered unconditionally so it stays a valid
		// dep target for the fl_builder_layout_style_dependencies filter
		// even on requests without a DS. Enqueue is gated on $ds_post below —
		// no DS resolved means no inline content to attach, so leaving the
		// handle out of the queue keeps the dep graph clean.
		wp_register_style(
			'bb-design-system-page-css',
			false,
			[
				'bb-design-system-css',
				'bb-design-layout-css-beaver-builder',
				'bb-design-layout-css-block-editor',
			]
		);

		if ( ! $ds_post ) {
			return;
		}

		wp_enqueue_style( 'bb-design-system-page-css' );

		// In admin block editor contexts, BlockEditorSettingsFilter delivers
		// the DS CSS to iframes via settings.styles. The wp_add_inline_style
		// and late wp_head echo paths below are frontend-only.
		if ( is_admin() ) {
			return;
		}

		// 1. Emit DS bulk via wp_add_inline_style so it flows through the WP
		// style printer in dep order (BB layout CSS depends on
		// bb-design-system-css via fl_builder_layout_style_dependencies, so
		// BB layout prints after DS bulk). Html rules and SystemTokens output
		// late-print at priority 1000 so they remain cascade-last against
		// the theme's own html rule.
		$parts = DesignSystemCssBuilder::build_parts_for_post( $post_id );

		if ( ! empty( $parts['bulk'] ) ) {
			wp_add_inline_style( 'bb-design-system-css', $parts['bulk'] );
		}

		if ( ! empty( $parts['html'] ) ) {
			$html_css = $parts['html'];
			add_action(
				'wp_head',
				static function () use ( $html_css ) {
					echo '<style id="bb-design-system-html-css-inline-css">' . $html_css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				1000
			);
		}

		// 2. Enqueue page-level CSS via wp_add_inline_style so it flows
		// through the WP style printer between DS bulk and BB layout. The
		// emitted element is <style id="bb-design-system-page-css-inline-css">,
		// the same id the live JS path writes to. Always emit content (real
		// CSS or a placeholder comment) because wp_add_inline_style is a
		// no-op for empty input — the JS path needs a stable target at the
		// correct cascade slot.
		$page_css = DesignSystemCssBuilder::build_page_override_for_post( $post_id );
		wp_add_inline_style(
			'bb-design-system-page-css',
			'' !== $page_css ? $page_css : '/* page overrides */'
		);

		// 3. Enqueue DS base JS.
		// In the BB builder context, BlockModuleRenderer::render_ds_base_js()
		// outputs DS JS inline before per-block JS. For non-BB contexts
		// (Gutenberg, standalone), output via early wp_footer so DS utilities
		// are defined before CustomBlockRenderer's per-block scripts (priority 10).
		$is_bb = class_exists( 'FLBuilderModel' ) && \FLBuilderModel::is_builder_enabled();
		$js    = get_post_meta( $ds_post->ID, DesignSystemPostType::META_BASE_JS, true );

		if ( ! empty( $js ) && ! $is_bb && ! is_admin() ) {
			add_action(
				'wp_footer',
				static function () use ( $js ) {
					echo '<script>' . JsShim::wrap_base_js( $js ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				1
			);
		}

		// 4. Enqueue page-level JS from post meta.
		$page_js = get_post_meta( $post_id, PageOverrideProvider::PAGE_JS_META_KEY, true );

		if ( ! empty( $page_js ) && ! $is_bb && ! is_admin() ) {
			add_action(
				'wp_footer',
				static function () use ( $page_js ) {
					echo '<script>' . JsShim::wrap_base_js( $page_js ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				},
				2
			);
		}

		// 5. Enqueue Google Fonts.
		$fonts_raw = get_post_meta( $ds_post->ID, DesignSystemPostType::META_FONTS, true );

		if ( ! empty( $fonts_raw ) ) {
			$entries = FontEntry::normalize( $fonts_raw );

			if ( ! empty( $entries ) ) {
				$url = GoogleFontsUrl::build( $entries );

				if ( '' !== $url ) {
					wp_enqueue_style( 'bb-design-google-fonts', $url, [], null );
				}
			}
		}
	}

	/**
	 * Resolve the design system post for this request.
	 *
	 * Returns the DS referenced by the current page, or null if
	 * the page has no DS reference.
	 *
	 * @return \WP_Post|null
	 */
	private function resolve_design_system_post(): ?\WP_Post {
		return DesignSystemPostType::resolve_for_post( $this->get_current_post_id() );
	}

	/**
	 * Get the current post ID from the main query.
	 *
	 * @return int Post ID, or 0 if not available.
	 */
	private function get_current_post_id(): int {
		if ( is_admin() ) {
			return (int) get_the_ID();
		}

		global $wp_the_query;

		if ( isset( $wp_the_query->post ) && $wp_the_query->post instanceof \WP_Post ) {
			return (int) $wp_the_query->post->ID;
		}

		return (int) get_queried_object_id();
	}
}
