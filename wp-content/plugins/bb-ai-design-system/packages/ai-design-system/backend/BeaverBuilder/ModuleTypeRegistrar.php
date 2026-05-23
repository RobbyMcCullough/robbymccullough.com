<?php

namespace FL\DesignSystem\BeaverBuilder;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;

/**
 * Registers the ds-block module type in BB.
 */
class ModuleTypeRegistrar {

	private BlockModuleRenderer $renderer;

	private string $namespace;

	/**
	 * @param BlockModuleRenderer $renderer  Block rendering callbacks.
	 * @param string              $namespace Module namespace prefix (e.g. 'ds', 'tw').
	 */
	public function __construct( BlockModuleRenderer $renderer, string $namespace = 'ds' ) {
		$this->renderer  = $renderer;
		$this->namespace = $namespace;
	}

	/**
	 * Register the ds-block module type in BB.
	 */
	public function register_module_types(): void {
		if ( ! class_exists( 'FLBuilderModuleType' ) ) {
			return;
		}

		// Avoid duplicate registration when both wp and AJAX hooks fire.
		if ( \FLBuilderModel::is_module_registered( $this->namespace . '-block' ) ) {
			return;
		}

		$form = [
			'ds_general' => [
				'title'    => __( 'Content', 'fl-design-system' ),
				'sections' => [
					'ds_block_data_section' => [
						'title'  => '',
						'fields' => [
							'ds_block_data' => [
								'type'    => 'hidden',
								'default' => wp_json_encode( [
									'type'     => 'ds-block',
									'template' => '<div class="fl-ds-block">{{settings.label}}</div>',
									'css'      => '.fl-ds-block { outline: 1px dashed currentColor; outline-offset: -1px; color: rgba(0, 0, 0, 0.4); padding: 24px; text-align: center; font-size: 14px; min-height: 80px; }',
									'form'     => [
										[
											'type'     => 'tab',
											'key'      => 'general',
											'title'    => 'Content',
											'children' => [
												[
													'type'     => 'section',
													'key'      => 'main',
													'title'    => '',
													'children' => [
														[
															'type'  => 'text',
															'key'   => 'label',
															'label' => 'Label',
														],
													],
												],
											],
										],
									],
									'settings' => [
										'label' => 'Custom Block',
									],
								] ),
							],
						],
					],
				],
			],
		];

		// Design tab — body is React-rendered via the deferred-tabs filter.
		$form['ds_design'] = [
			'title'    => BlockModuleRenderer::resolve_icon( 'paintbrush' ),
			'sections' => [],
		];

		// Only surface the raw code editor to users trusted with unfiltered HTML.
		if ( WordPressAuth::user_can_create_content() ) {
			$form['ds_code'] = [
				'title'    => BlockModuleRenderer::resolve_icon( 'code' ),
				'sections' => [],
			];
		}

		\FLBuilderModuleType::register( 'block', [
			'name'            => __( 'Block', 'fl-design-system' ),
			'namespace'       => $this->namespace,
			'category'        => __( 'Basic', 'fl-design-system' ),
			'icon'            => BlockModuleRenderer::resolve_icon( 'code' ),
			'enabled'         => false,
			'include_wrapper' => false,
			'partial_refresh' => true,
			'top_level'       => true,
			'form'            => $form,
			'render' => function( $settings, $module ) {
				return $this->renderer->render_html( $settings, $module );
			},
			'css' => function( $module ) {
				$this->renderer->render_css( $module );
			},
			'js' => function( $module ) {
				$this->renderer->render_js( $module );
			},
		] );
	}
}
