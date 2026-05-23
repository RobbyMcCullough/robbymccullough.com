<?php

namespace FL\DesignSystem\BlockEditor;

use FL\DesignSystem\Form\FormFieldInjector;
use FL\DesignSystem\Form\SpamGuard;
use FL\DesignSystem\Rendering\BlockHtmlHelper;
use FL\DesignSystem\Rendering\CssScoper;
use FL\DesignSystem\Rendering\MustacheEngine;
use FL\DesignSystem\Services\BindingsNormalizer;
use FL\DesignSystem\Settings\SettingsResolver;

/**
 * Server-side render callback for fl-ds/custom blocks.
 *
 * Handles LLM-generated freeform blocks that carry their template, CSS,
 * JS, and form definition directly as block attributes. Each instance is
 * scoped independently using the blockId attribute.
 *
 * When the rendered HTML contains a `<form>`, SpamGuard-issued hidden
 * fields and block-identifying data attributes are injected so the
 * frontend runtime can claim the form and the REST endpoint can resolve
 * its settings.
 */
class CustomBlockRenderer {

	private SpamGuard $spam_guard;

	/** @var string[] JS content hashes already enqueued this request. */
	private array $rendered_hashes = [];

	public function __construct( SpamGuard $spam_guard ) {
		$this->spam_guard = $spam_guard;
	}

	/**
	 * PHP render_callback for fl-ds/custom blocks.
	 *
	 * Parses template/css/js/form from the block's inner content and reads
	 * settings/blockId from comment attributes. Resolves settings, renders
	 * via Mustache, scopes CSS, and defers JS.
	 *
	 * @param  array  $attributes Block attributes from the comment delimiter.
	 * @param  string $content    Raw innerHTML between the block comment delimiters.
	 * @return string Rendered HTML.
	 */
	public function render( array $attributes, string $content = '' ): string {
		$parsed   = ContentParser::parse( $content );
		$template = $parsed['template'];

		if ( empty( $template ) ) {
			return '';
		}

		$block_id = $attributes['blockId'] ?? '';
		$css      = $parsed['css'];
		$js       = $parsed['js'];
		$form     = $parsed['form'];
		$settings = (array) ( $attributes['settings'] ?? [] );
		$bindings = (array) ( $attributes['bindings'] ?? [] );

		// Block-editor blocks read attributes directly from post_content rather
		// than going through `BlockService::format_ds_block_data`, so we apply
		// the bindings normalizer here to split any legacy object-shape settings
		// into the new `settings + bindings` shape. Idempotent on already-clean
		// data.
		$normalized = ( new BindingsNormalizer() )->normalize(
			[
				'settings' => $settings,
				'bindings' => $bindings,
			]
		);

		$resolved = SettingsResolver::resolve_for_render(
			$form,
			isset( $normalized['settings'] ) && is_array( $normalized['settings'] ) ? $normalized['settings'] : [],
			isset( $normalized['bindings'] ) && is_array( $normalized['bindings'] ) ? $normalized['bindings'] : [],
			[ 'post_id' => get_the_ID() ]
		);

		$mustache = new MustacheEngine();
		$html     = $mustache->render( $template, [ 'settings' => $resolved ] );

		$scope_class = ! empty( $block_id ) ? 'fl-ds-custom-' . $block_id : 'fl-ds-custom-block';

		if ( false !== stripos( $html, '<form' ) ) {
			$html = FormFieldInjector::inject(
				$html,
				$scope_class,
				(int) get_the_ID(),
				$this->spam_guard,
				$resolved
			);
		}

		$output = '';

		if ( ! empty( $css ) ) {
			$scoped_css = CssScoper::scope_css( $css, '.' . $scope_class, $template );
			if ( ! empty( $scoped_css ) ) {
				$output .= '<style>' . $scoped_css . '</style>' . "\n";
			}
		}

		$output .= BlockHtmlHelper::inject_classes( $html, 'fl-ds-block ' . $scope_class );

		if ( ! empty( $js ) ) {
			$this->enqueue_js( $js, $scope_class );
		}

		return $output;
	}

	/**
	 * Enqueue JS for footer output, deduplicated by content hash.
	 *
	 * Each unique JS payload is output once per request. The block's root
	 * element is passed as `block` so scripts can reference it without
	 * querying by ID.
	 *
	 * @param string $js          Raw JavaScript code.
	 * @param string $scope_class CSS class scoping this block instance.
	 */
	private function enqueue_js( string $js, string $scope_class ): void {
		$hash = substr( md5( $js ), 0, 8 );
		if ( in_array( $hash, $this->rendered_hashes, true ) ) {
			return;
		}
		$this->rendered_hashes[] = $hash;

		$wrapped = '(function(){'
			. 'var block=document.querySelector(' . wp_json_encode( '.' . $scope_class ) . ');'
			. 'if(!block)return;'
			. 'try{(new Function("block",' . wp_json_encode( $js ) . '))(block);}'
			. 'catch(e){console.error("FL DS custom block JS error:",e);}'
			. '})();';

		add_action(
			'wp_footer',
			static function () use ( $wrapped ) {
				echo '<script>' . $wrapped . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
	}
}
