<?php

namespace FL\DesignSystem\DesignKit;

use FL\DesignSystem\BeaverBuilder\BeaverBuilderPageAdapter;
use FL\DesignSystem\BeaverBuilder\LayoutManager;
use FL\DesignSystem\BlockEditor\BlockEditorPageAdapter;
use FL\DesignSystem\Plugin;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\Page\PageImporter;

/**
 * Imports header/footer HTML into Beaver Themer layouts or Site Editor template parts.
 */
class GlobalImporter {

	/**
	 * Import a header/footer into a Beaver Themer layout.
	 *
	 * Creates a fl-theme-layout post with the appropriate type metadata,
	 * then imports the HTML content via PageImporter with the BB adapter.
	 *
	 * @param string      $html    The HTML content.
	 * @param string      $type    'header' or 'footer'.
	 * @param string      $ds_css  The design system CSS to inject.
	 * @param string|null $ds_uuid DS UUID to link.
	 * @param string      $label   Design system label for naming.
	 * @return array{postId: int, status: string}|array{status: string, error: string}
	 */
	public static function import_themer_layout( string $html, string $type, string $ds_css, ?string $ds_uuid = null, string $label = '' ): array {
		// Inject DS CSS into the HTML.
		if ( '' !== $ds_css ) {
			$html = KitImporter::inject_css_into_html( $html, $ds_css );
		}

		// Create the Themer layout post.
		$type_label = 'header' === $type ? 'Header' : 'Footer';
		$title      = $label ? "$type_label - $label" : $type_label;
		$post_id    = \wp_insert_post( [
			'post_type'   => 'fl-theme-layout',
			'post_title'  => $title,
			'post_status' => 'draft',
		], true );

		if ( \is_wp_error( $post_id ) ) {
			return [
				'status' => 'error',
				'error'  => 'Failed to create Themer layout: ' . $post_id->get_error_message(),
			];
		}

		// Enable BB for the Themer layout.
		if ( class_exists( 'FLBuilderModel' ) ) {
			\update_post_meta( $post_id, '_fl_builder_enabled', true );
		}

		// Set layout type.
		\update_post_meta( $post_id, '_fl_theme_layout_type', $type );

		// Set default settings for headers.
		if ( 'header' === $type ) {
			\update_post_meta( $post_id, '_fl_theme_layout_settings', [
				'sticky'     => '0',
				'shrink'     => '0',
				'overlay'    => '0',
				'overlay_bg' => 'transparent',
				'sticky-on'  => '',
			] );
		}

		// Do NOT set _fl_theme_builder_locations -- user configures where it applies.

		// Import HTML via PageImporter with BB adapter.
		$module_ns = Plugin::resolve_module_namespace( 'none' );
		$layout    = new LayoutManager();
		$adapter   = new BeaverBuilderPageAdapter( $layout, $module_ns );

		$options = [
			'create_design_system' => false,
		];
		if ( $ds_uuid ) {
			$options['design_system_uuid'] = $ds_uuid;
		}

		$import_result = PageImporter::import( $html, $post_id, $adapter, $options );

		// Set DS ref meta if UUID provided.
		if ( $ds_uuid ) {
			\update_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, $ds_uuid );
		}

		$edit_url = class_exists( 'FLBuilderModel' )
			? \FLBuilderModel::get_edit_url( $post_id )
			: \get_edit_post_link( $post_id, 'raw' );

		$result = [
			'postId'  => $post_id,
			'status'  => 'imported',
			'editUrl' => $edit_url,
		];

		if ( ! empty( $import_result['errors'] ) ) {
			$result['errors'] = $import_result['errors'];
		}

		return $result;
	}

	/**
	 * Import a header/footer into a Site Editor template part.
	 *
	 * Creates a wp_template_part post with the appropriate area taxonomy,
	 * then imports the HTML content via PageImporter with the Block Editor adapter.
	 *
	 * @param string      $html    The HTML content.
	 * @param string      $area    'header' or 'footer'.
	 * @param string      $ds_css  The design system CSS to inject.
	 * @param string|null $ds_uuid DS UUID to link.
	 * @param string      $label   Design system label for naming.
	 * @return array{postId: int, status: string}|array{status: string, error: string}
	 */
	public static function import_site_editor_part( string $html, string $area, string $ds_css, ?string $ds_uuid = null, string $label = '' ): array {
		// Inject DS CSS into the HTML.
		if ( '' !== $ds_css ) {
			$html = KitImporter::inject_css_into_html( $html, $ds_css );
		}

		// Create the template part post with a unique slug.
		$area_label = 'header' === $area ? 'Header' : 'Footer';
		$title      = $label ? "$area_label - $label" : $area_label;
		$slug       = 'imported-' . $area . '-' . time();

		$post_id = \wp_insert_post( [
			'post_type'    => 'wp_template_part',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'draft',
			'post_content' => '',
		], true );

		if ( \is_wp_error( $post_id ) ) {
			return [
				'status' => 'error',
				'error'  => 'Failed to create template part: ' . $post_id->get_error_message(),
			];
		}

		// Set theme association.
		\wp_set_object_terms( $post_id, \get_stylesheet(), 'wp_theme' );

		// Set area taxonomy.
		\wp_set_object_terms( $post_id, $area, 'wp_template_part_area' );

		// Import HTML via PageImporter with Block Editor adapter.
		$adapter = new BlockEditorPageAdapter();

		$options = [
			'create_design_system' => false,
		];
		if ( $ds_uuid ) {
			$options['design_system_uuid'] = $ds_uuid;
		}

		$import_result = PageImporter::import( $html, $post_id, $adapter, $options );

		// Set DS ref meta if UUID provided.
		if ( $ds_uuid ) {
			\update_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, $ds_uuid );
		}

		$result = [
			'postId'  => $post_id,
			'status'  => 'imported',
			'editUrl' => \get_edit_post_link( $post_id, 'raw' ),
		];

		if ( ! empty( $import_result['errors'] ) ) {
			$result['errors'] = $import_result['errors'];
		}

		return $result;
	}
}
