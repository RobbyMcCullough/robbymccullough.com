<?php

namespace FL\DesignSystem\BeaverBuilder;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Form\FormFieldInjector;
use FL\DesignSystem\Form\SpamGuard;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\Rendering\CssScoper;
use FL\DesignSystem\Rendering\JsShim;
use FL\DesignSystem\Rendering\MarkerInjector;
use FL\DesignSystem\Rendering\MustacheEngine;
use FL\DesignSystem\Services\BindingsNormalizer;
use FL\DesignSystem\Services\Parser\FieldTypeResolver;
use FL\DesignSystem\Settings\SettingsResolver;

class BlockModuleRenderer {

	private ?SpamGuard $spam_guard = null;

	/**
	 * @param SpamGuard|null $spam_guard Spam guard used to issue form tokens at render time.
	 */
	public function __construct( ?SpamGuard $spam_guard = null ) {
		$this->spam_guard = $spam_guard;
	}

	/**
	 * Named DS icons mapped to their SVG markup.
	 */
	private const NAMED_ICONS = [
		'align-left'           => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 12H3"/><path d="M17 18H3"/><path d="M21 6H3"/></svg>',
		'award'                => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg>',
		'bar-chart-2'          => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/></svg>',
		'box'                  => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>',
		'chevrons-down'        => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 6 5 5 5-5"/><path d="m7 13 5 5 5-5"/></svg>',
		'code'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/></svg>',
		'columns'              => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>',
		'columns-2'            => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M12 3v18"/></svg>',
		'git-commit'           => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><line x1="3" x2="9" y1="12" y2="12"/><line x1="15" x2="21" y1="12" y2="12"/></svg>',
		'grid'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>',
		'hash'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" x2="20" y1="9" y2="9"/><line x1="4" x2="20" y1="15" y2="15"/><line x1="10" x2="8" y1="3" y2="21"/><line x1="16" x2="14" y1="3" y2="21"/></svg>',
		'headphones'           => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 14h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a9 9 0 0 1 18 0v7a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3"/></svg>',
		'image'                => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>',
		'inbox'                => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
		'layout'               => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
		'layout-grid'          => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>',
		'layout-panel-top'     => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="7" x="3" y="3" rx="2"/><rect width="7" height="7" x="3" y="14" rx="2"/><rect width="7" height="7" x="14" y="14" rx="2"/></svg>',
		'list'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h.01"/><path d="M3 18h.01"/><path d="M3 6h.01"/><path d="M8 12h13"/><path d="M8 18h13"/><path d="M8 6h13"/></svg>',
		'list-ordered'         => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 12h11"/><path d="M10 18h11"/><path d="M10 6h11"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>',
		'list-tree'            => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12h-8"/><path d="M21 6H8"/><path d="M21 18h-8"/><path d="M3 6v4c0 1.1.9 2 2 2h3"/><path d="M3 10v6c0 1.1.9 2 2 2h3"/></svg>',
		'lock'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
		'mail'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
		'map-pin'              => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/></svg>',
		'megaphone'            => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 6a13 13 0 0 0 8.4-2.8A1 1 0 0 1 21 4v12a1 1 0 0 1-1.6.8A13 13 0 0 0 11 14H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/><path d="M6 14a12 12 0 0 0 2.4 7.2 2 2 0 0 0 3.2-2.4A8 8 0 0 1 10 14"/><path d="M8 6v8"/></svg>',
		'message-circle'       => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>',
		'message-square-quote' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 14a2 2 0 0 0 2-2V8h-2"/><path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/><path d="M8 14a2 2 0 0 0 2-2V8H8"/></svg>',
		'minus'                => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/></svg>',
		'mouse-pointer'        => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 12.586 19 19"/><path d="M3.688 3.037a.497.497 0 0 0-.651.651l6.5 15.999a.501.501 0 0 0 .947-.062l1.569-6.083a2 2 0 0 1 1.448-1.479l6.124-1.579a.5.5 0 0 0 .063-.947z"/></svg>',
		'paintbrush'           => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m14.622 17.897-10.68-2.913"/><path d="M18.376 2.622a1 1 0 1 1 3.002 3.002L17.36 9.643a.5.5 0 0 0 0 .707l.944.944a2.41 2.41 0 0 1 0 3.408l-.944.944a.5.5 0 0 1-.707 0L8.354 7.348a.5.5 0 0 1 0-.707l.944-.944a2.41 2.41 0 0 1 3.408 0l.944.944a.5.5 0 0 0 .707 0z"/><path d="M9 8c-1.804 2.71-3.97 3.46-6.583 3.948a.507.507 0 0 0-.302.819l7.32 8.883a1 1 0 0 0 1.185.204C12.735 20.405 16 16.792 16 15"/></svg>',
		'panel-top'            => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/></svg>',
		'play'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/></svg>',
		'rectangle-horizontal' => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="12" x="2" y="6" rx="2"/></svg>',
		'rotate-3d'            => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.466 7.5C15.643 4.237 13.952 2 12 2 9.239 2 7 6.477 7 12s2.239 10 5 10c.342 0 .677-.069 1-.2"/><path d="m15.194 13.707 3.814 1.86-1.86 3.814"/><path d="M19 15.57c-1.804.885-4.274 1.43-7 1.43-5.523 0-10-2.239-10-5s4.477-5 10-5c4.838 0 8.873 1.718 9.8 4"/></svg>',
		'rows-3'               => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="4" x="3" y="3" rx="2"/><rect width="18" height="4" x="3" y="10" rx="2"/><rect width="18" height="4" x="3" y="17" rx="2"/></svg>',
		'search'               => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg>',
		'star'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
		'tag'                  => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>',
		'text'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 6.1H3"/><path d="M21 12.1H3"/><path d="M15.1 18H3"/></svg>',
		'type'                 => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" x2="15" y1="20" y2="20"/><line x1="12" x2="12" y1="4" y2="20"/></svg>',
	];

	/**
	 * Resolve a block icon value to an SVG string for BB registration.
	 *
	 * Handles raw SVG strings (passthrough) and named DS icon lookups.
	 * Falls back to the 'box' icon for unrecognized names.
	 *
	 * @param  string $icon Icon value from the block definition.
	 * @return string SVG markup string.
	 */
	public static function resolve_icon( string $icon ): string {
		if ( str_starts_with( trim( $icon ), '<svg' ) ) {
			return self::normalize_svg_size( $icon );
		}
		$svg = self::NAMED_ICONS[ $icon ] ?? self::NAMED_ICONS['box'];
		return self::normalize_svg_size( $svg );
	}

	/**
	 * Normalize an SVG for BB's module panel.
	 *
	 * Strips explicit width/height so the icon inherits the container's
	 * CSS dimensions (BB uses 20x20 for module icons) and increases
	 * stroke-width to compensate for the 24->20 viewBox scaling that
	 * would otherwise make stroked icons look thinner than BB's native
	 * fill-based icons.
	 *
	 * @param  string $svg Raw SVG markup.
	 * @return string Normalized SVG markup.
	 */
	private static function normalize_svg_size( string $svg ): string {
		$svg = preg_replace( '/stroke-width="2"/', 'stroke-width="2.25"', $svg );

		// Strip width/height only from the root <svg> tag so child elements
		// (e.g. <rect width="7" height="7">) keep their dimensions.
		$svg = preg_replace_callback( '/<svg\b[^>]*>/i', function ( $matches ) {
			return preg_replace( '/\s*(?<![a-z\-])(width|height)="[^"]*"/', '', $matches[0] );
		}, $svg, 1 );

		return $svg;
	}

	/**
	 * Format a block definition for the editor (lean projection).
	 *
	 * @param  array $block Block definition.
	 * @return array Editor-ready block data.
	 */
	public static function format_for_editor( array $block ): array {
		return [
			'slug'        => $block['name'] ?? '',
			'label'       => $block['label'] ?? $block['name'] ?? '',
			'icon'        => self::resolve_icon( $block['icon'] ?? '' ),
			'category'    => $block['category'] ?? '',
			'description' => $block['description'] ?? '',
			'template'    => $block['template'] ?? '',
			'css'         => $block['css'] ?? '',
			'js'          => $block['js'] ?? '',
			'form'        => $block['form'] ?? [],
			'container'   => ! empty( $block['container'] ),
			'accepts'     => $block['accepts'] ?? [],
		];
	}

	/**
	 * Build BB form tabs from a DS form config.
	 *
	 * Extracts tab nodes from the form config and creates a BB tab entry for
	 * each one. The module_data hidden field is placed in the first tab so it's
	 * always present for serialization. If no tab nodes exist, falls back to a
	 * single "Form" tab for backwards compatibility.
	 *
	 * @param  array      $form_config  DS form config array (list of form nodes).
	 * @param  string     $slug         Module slug (e.g. 'ds-menu').
	 * @param  array|null $default_data Optional default data for the ds_block_data field.
	 *                                  JSON-encoded as the field default. Falls back to ['type' => $slug].
	 * @return array  BB form array keyed by tab ID.
	 */
	public static function build_form_tabs( array $form_config, string $slug, ?array $default_data = null ): array {
		$tabs = array_filter( $form_config, function ( $node ) {
			return isset( $node['type'] ) && 'tab' === $node['type'];
		} );

		$default = $default_data ?? [ 'type' => $slug ];

		$module_data_field = [
			'ds_block_data' => [
				'type'    => 'hidden',
				'default' => wp_json_encode( $default ),
				'preview' => [ 'type' => 'refresh' ],
			],
		];

		// Design tab — body is React-rendered, no PHP fields needed.
		$design_tab = [
			'ds_design' => [
				'title'    => self::resolve_icon( 'paintbrush' ),
				'sections' => [],
			],
		];

		// Restricted users get no code tab; SaveGuard also preserves their
		// `ds_block_data` code fields on save, so hiding the UI matches the
		// server-side guarantee.
		$code_tab = WordPressAuth::user_can_create_content()
			? [
				'ds_code' => [
					'title'    => self::NAMED_ICONS['code'],
					'sections' => [],
				],
			]
			: [];

		if ( empty( $tabs ) ) {
			return array_merge(
				[
					'ds_form' => [
						'title'    => __( 'Form', 'fl-design-system' ),
						'sections' => [
							'data' => [
								'title'  => '',
								'fields' => $module_data_field,
							],
						],
					],
				],
				$design_tab,
				$code_tab
			);
		}

		$form     = [];
		$is_first = true;

		foreach ( $tabs as $tab ) {
			$tab_key   = $tab['key'] ?? '';
			$tab_title = $tab['title'] ?? $tab['label'] ?? $tab['key'] ?? '';

			if ( empty( $tab_key ) ) {
				continue;
			}

			$bb_tab_key  = 'ds_' . $tab_key;
			$section_key = $is_first ? 'data' : $bb_tab_key;
			$fields      = $is_first ? $module_data_field : [];

			$form[ $bb_tab_key ] = [
				'title'    => $tab_title,
				'sections' => [
					$section_key => [
						'title'  => '',
						'fields' => $fields,
					],
				],
			];

			$is_first = false;
		}

		return array_merge( $form, $design_tab, $code_tab );
	}

	/**
	 * Resolve block definition and module data from BB settings.
	 *
	 * DS blocks store their full definition inline in `ds_block_data`
	 * (template, css, js, form, settings).
	 *
	 * @param  object $settings The BB module settings object.
	 * @return array|null Array with 'definition' and 'module_data' keys, or null.
	 */
	private function resolve( $settings ): ?array {
		$raw = $settings->ds_block_data ?? '{}';
		if ( is_string( $raw ) ) {
			$module_data = json_decode( $raw, true );
			// BB's layout data cache can contain slashed strings when
			// update_layout_data and get_layout_data run in the same
			// PHP process (e.g. history restore). Retry with unslash.
			if ( ! is_array( $module_data ) ) {
				$module_data = json_decode( wp_unslash( $raw ), true );
			}
		} else {
			$module_data = json_decode( wp_json_encode( $raw ), true );
		}

		if ( ! is_array( $module_data ) || empty( $module_data['template'] ) ) {
			return null;
		}

		// The BB renderer reads `ds_block_data` directly from layout meta, so the
		// REST controller's normalization never runs here. Apply the bindings
		// normalizer at the boundary so downstream code always sees the new
		// `settings + bindings` shape. The normalizer is idempotent.
		$module_data = ( new BindingsNormalizer() )->normalize( $module_data );

		return [
			'definition'  => $module_data,
			'module_data' => $module_data,
		];
	}

	/**
	 * Render the block HTML with BB node attributes injected onto the root element.
	 *
	 * Resolves the block definition, merges form defaults with saved settings,
	 * renders the Mustache template, then injects BB attributes onto the
	 * template's root element.
	 *
	 * @param  object           $settings The BB module settings object.
	 * @param  \FLBuilderModule $module   The BB module instance.
	 * @return string Rendered HTML with BB attributes on the root element.
	 */
	public function render_html( object $settings, \FLBuilderModule $module ): string {
		$resolved = $this->resolve( $settings );
		if ( ! $resolved ) {
			return '';
		}

		$definition  = $resolved['definition'];
		$module_data = $resolved['module_data'];
		$template    = $definition['template'] ?? '';

		if ( empty( $template ) ) {
			return '';
		}

		$form     = $definition['form'] ?? [];
		$saved    = $module_data['settings'] ?? [];
		$bindings = isset( $module_data['bindings'] ) && is_array( $module_data['bindings'] )
			? $module_data['bindings']
			: [];

		$merged = SettingsResolver::resolve_for_render(
			$form,
			is_array( $saved ) ? $saved : [],
			$bindings,
			[ 'post_id' => get_the_ID() ]
		);

		// When the builder is active, bake inline-editing markers into the
		// template and annotate repeater items with their full absolute
		// `__bb_path` so the rendered HTML carries stable, structural marker
		// paths (`groups.0.items.1.name` etc.). Production renders leave the
		// template untouched.
		$is_builder = \FLBuilderModel::is_builder_active();

		if ( $is_builder ) {
			$injector = new MarkerInjector();
			// Image detection is template-driven inside the planner (any
			// `<img>` with mustache becomes editable, regardless of value
			// shape). The resolver here only classifies body-context svg
			// tokens — `<div>{{{settings.icon}}}</div>` where the value
			// is `<svg>...</svg>` markup. See FieldTypeResolver.
			$resolver = new FieldTypeResolver( is_array( $merged ) ? $merged : [] );
			$template = $injector->inject_markers( $template, $resolver );
			$merged   = $injector->annotate_paths( $merged );
		}

		$context = [ 'settings' => $merged ];

		// For container blocks, render children into the {{{children}}} token.
		// Use a placeholder during Mustache rendering so we can inject
		// data-children-wrapper on the parent element for BB's drag-and-drop.
		$children_placeholder = '<!--bb-children-placeholder-->';

		if ( $module->accepts_children() ) {
			$context['children'] = $children_placeholder;
		}

		$mustache = new MustacheEngine();
		$html     = $mustache->render( $template, $context );

		if ( $module->accepts_children() ) {
			// When the builder is active and the children placeholder is inside
			// a nested element (not the root), mark that element so BB knows
			// where to drop children. If children are directly in the root
			// element, BB already handles it via data-accepts on the root.
			if ( \FLBuilderModel::is_builder_active() ) {
				$placeholder_pos = strpos( $html, $children_placeholder );
				$before          = substr( $html, 0, $placeholder_pos );
				$open_tags       = preg_match_all( '/<[a-z][^>]*>/i', $before );

				if ( $open_tags > 1 ) {
					$html = preg_replace(
						'/(<[a-z][^>]*)(>\s*' . preg_quote( $children_placeholder, '/' ) . ')/',
						'$1 data-children-wrapper="true"$2',
						$html,
						1
					);
				}
			}

			// Replace the placeholder with actual rendered children.
			ob_start();
			$module->render_children();
			$children_html = ob_get_clean();
			$html          = str_replace( $children_placeholder, $children_html, $html );
		}

		if ( $this->spam_guard && false !== stripos( $html, '<form' ) ) {
			$html = FormFieldInjector::inject(
				$html,
				(string) $module->node,
				(int) \FLBuilderModel::get_post_id(),
				$this->spam_guard,
				$merged
			);
		}

		return $this->apply_node_attributes( $html, $module );
	}

	/**
	 * Inject BB node attributes onto the first HTML element's opening tag.
	 *
	 * Captures the attributes rendered by BB (fl-node-{id}, fl-module-{slug},
	 * data-node, data-type, animation, visibility, custom class, etc.) and
	 * merges them onto the root element. BB classes come first, then the
	 * template's own classes.
	 *
	 * @param  string           $html   The rendered template HTML.
	 * @param  \FLBuilderModule $module The BB module instance.
	 * @return string HTML with BB attributes injected on the root element.
	 */
	private function apply_node_attributes( string $html, \FLBuilderModule $module ): string {
		ob_start();
		$module->render_attributes();
		$bb_attrs = trim( ob_get_clean() );

		// Trim leading whitespace left by collapsed Mustache conditional sections
		// (e.g., {{#settings.link_url}}...{{/settings.link_url}} in the icon block).
		$html = ltrim( $html );

		if ( ! preg_match( '/^(<\w+)([\s\S]*?)(>)/', $html, $match ) ) {
			return $html;
		}

		$tag_open       = $match[1];
		$existing_attrs = $match[2];
		$tag_close      = $match[3];

		// Extract and merge class attributes
		$existing_classes = '';
		if ( preg_match( '/\bclass="([^"]*)"/', $existing_attrs, $m ) ) {
			$existing_classes = $m[1];
			$existing_attrs   = str_replace( $m[0], '', $existing_attrs );
		}

		$bb_classes = '';
		$bb_other   = $bb_attrs;
		if ( preg_match( '/\bclass="([^"]*)"/', $bb_attrs, $m ) ) {
			$bb_classes = $m[1];
			$bb_other   = str_replace( $m[0], '', $bb_attrs );
		}

		$merged_classes = trim( 'fl-ds-block ' . $bb_classes . ' ' . $existing_classes );
		$class_attr     = ' class="' . $merged_classes . '"';

		return $tag_open . $class_attr . $bb_other . $existing_attrs . $tag_close
			. substr( $html, strlen( $match[0] ) );
	}

	/**
	 * Scope CSS by prefixing selectors with a node-specific scope selector.
	 *
	 * Delegates to CssScoper. Kept for backward compatibility.
	 *
	 * @param  string $css            Raw CSS string.
	 * @param  string $scope_selector Scope selector (e.g. '.fl-node-abc123').
	 * @param  string $template       Mustache template for root class extraction.
	 * @param  array  $template_data  Optional data to render template with before extraction.
	 * @param  array  $form           Optional form config for variant root class detection.
	 * @return string Scoped CSS.
	 */
	public static function scope_css( string $css, string $scope_selector, string $template, array $template_data = [], array $form = [] ): string {
		return CssScoper::scope_css( $css, $scope_selector, $template, $template_data, $form );
	}

	/**
	 * Render block CSS scoped to the BB node.
	 *
	 * All CSS selectors are prefixed with `.fl-node-{id}` so each block
	 * instance gets its own isolated styles. Root-element selectors use
	 * compound joining; other selectors use descendant joining.
	 *
	 * @param \FLBuilderModule $module The BB module instance.
	 */
	public function render_css( \FLBuilderModule $module ): void {
		$resolved = $this->resolve( $module->settings );
		if ( ! $resolved ) {
			return;
		}

		$definition = $resolved['definition'];
		$css        = $definition['css'] ?? '';

		if ( empty( $css ) ) {
			return;
		}

		$template    = $definition['template'] ?? '';
		$module_data = $resolved['module_data'];
		$form        = $definition['form'] ?? [];
		$saved       = $module_data['settings'] ?? [];
		$defaults    = SettingsResolver::resolve_defaults( $form );
		$merged      = array_merge( $defaults, $saved );

		echo CssScoper::scope_css( $css, ".fl-node-{$module->node}", $template, [ 'settings' => $merged ] );
	}

	/**
	 * Render block JS once per block type.
	 *
	 * Echoes the block's JavaScript wrapped in a self-executing function.
	 * Only outputs once per block type to avoid duplicate execution.
	 *
	 * @param \FLBuilderModule $module The BB module instance.
	 */
	public function render_js( \FLBuilderModule $module ): void {
		self::render_ds_base_js();
		self::render_ds_page_js();

		$resolved = $this->resolve( $module->settings );
		if ( ! $resolved ) {
			return;
		}

		$definition = $resolved['definition'];
		$js         = $definition['js'] ?? '';

		if ( empty( $js ) ) {
			return;
		}

		// Only output block JS once per unique JS content. Uses a
		// content hash instead of block type so inline modules (all type
		// ds-block) with different JS each get their scripts output.
		static $rendered_block_js = [];
		$js_hash                  = md5( $js );
		if ( in_array( $js_hash, $rendered_block_js, true ) ) {
			return;
		}
		$rendered_block_js[] = $js_hash;

		echo self::wrap_js_iife( $js );
	}

	/**
	 * Output design system base JS once before any per-block JS.
	 *
	 * Resolves the DS referenced by the current page and echoes its
	 * base JavaScript. Called from render_js() so DS utilities are
	 * defined before any section JS that depends on them.
	 */
	private static function render_ds_base_js(): void {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		$ds_post = DesignSystemPostType::resolve_for_post( \FLBuilderModel::get_post_id() );

		if ( ! $ds_post ) {
			return;
		}

		$js = get_post_meta( $ds_post->ID, DesignSystemPostType::META_BASE_JS, true );
		if ( ! empty( $js ) ) {
			// Output with DOMContentLoaded/load shim (but NOT IIFE-wrapped)
			// so utilities stay in global scope and DOMContentLoaded listeners
			// fire immediately during BB AJAX layout renders.
			echo JsShim::wrap_base_js( $js );
		}
	}

	/**
	 * Output page-level JS once after base JS.
	 *
	 * Reads page CSS/JS from post meta on the current page and outputs
	 * it with the same wrapping as base JS so page utilities are callable
	 * by section JS.
	 */
	private static function render_ds_page_js(): void {
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		$post_id = \FLBuilderModel::get_post_id();
		$page_js = get_post_meta( $post_id, PageOverrideProvider::PAGE_JS_META_KEY, true );

		if ( ! empty( $page_js ) ) {
			echo JsShim::wrap_base_js( $page_js );
		}
	}

	/**
	 * Wrap JavaScript code in a self-executing function with
	 * DOMContentLoaded/load event shims.
	 *
	 * @param  string $js Raw JavaScript code.
	 * @return string IIFE-wrapped JavaScript.
	 */
	private static function wrap_js_iife( string $js ): string {
		return '(function(){'
			. 'var _dAEL=document.addEventListener.bind(document);'
			. 'var _wAEL=window.addEventListener.bind(window);'
			. 'function _shim(orig,el){return function(t,fn,o){'
			. 'if(t==="DOMContentLoaded"&&document.readyState!=="loading"){fn();return}'
			. 'if(t==="load"&&document.readyState==="complete"){fn();return}'
			. 'return orig.call(el,t,fn,o);'
			. '};}'
			. 'document.addEventListener=_shim(_dAEL,document);'
			. 'window.addEventListener=_shim(_wAEL,window);'
			. 'try{'
			. 'var code=' . wp_json_encode( $js ) . ';'
			. '(new Function(code))();'
			. '}catch(e){console.error("DS Block JS Error:",e);}'
			. 'document.addEventListener=_dAEL;'
			. 'window.addEventListener=_wAEL;'
			. '})();';
	}
}
