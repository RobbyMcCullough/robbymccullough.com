<?php

namespace FL\DesignSystem\Mcp\Support;

use FL\DesignSystem\BeaverBuilder\BeaverBuilderPageAdapter;
use FL\DesignSystem\Generation\DebugProvider;
use FL\DesignSystem\Page\PageImporter;

/**
 * Shared page-generation pipeline used by generate-page and generate-style-guide.
 *
 * Both abilities take an HTML document plus optional metadata (title,
 * post type, status, design system), insert a post, run the importer,
 * and return a uniform response. The behavior is identical between the
 * two abilities — only their schemas, labels, and subcategory metadata
 * differ. Centralising here keeps that single execution path consistent.
 *
 * Locked dependency list: PageResolver (adapter + post URL) and
 * PostTypeService (auto-correct branch when the requested post type
 * isn't creatable). Statics it reaches (PageImporter, DebugProvider,
 * BeaverBuilderPageAdapter) are not injected.
 */
class PageGenerator {

	private PageResolver $page_resolver;
	private PostTypeService $post_type_service;
	private HashVerifier $hash_verifier;

	public function __construct( PageResolver $page_resolver, PostTypeService $post_type_service, HashVerifier $hash_verifier ) {
		$this->page_resolver     = $page_resolver;
		$this->post_type_service = $post_type_service;
		$this->hash_verifier     = $hash_verifier;
	}

	/**
	 * Generate a page from HTML.
	 *
	 * @param  array $input Validated input (title, html, post_type, status,
	 *                      design_system_uuid, design_system_label).
	 * @return array|\WP_Error
	 */
	public function generate( array $input ) {
		$title     = $input['title'] ?? '';
		$html      = $input['html'] ?? '';
		$post_type = $input['post_type'] ?? 'page';
		$status    = $input['status'] ?? 'draft';
		$ds_uuid   = $input['design_system_uuid'] ?? null;
		$ds_label  = $input['design_system_label'] ?? null;

		if ( '' === $html ) {
			return new \WP_Error(
				'missing_params',
				'HTML content is required.',
				[ 'status' => 400 ]
			);
		}

		// Extract <title> once for both page title and DS label fallback chains.
		$title_tag = '';
		if ( preg_match( '/<title>(.+?)<\/title>/i', $html, $matches ) ) {
			$title_tag = trim( $matches[1] );
		}

		if ( '' === $title ) {
			if ( '' !== $title_tag ) {
				$title = $title_tag;
			} elseif ( $ds_label ) {
				$title = $ds_label;
			}
		}

		// Derive DS label when the agent didn't supply one and a new DS will be created.
		// Fallback chain: explicit label -> page title -> <title> tag.
		// PageImporter's own default ("Imported Design System") covers the final fallback.
		if ( ! $ds_uuid && ! $ds_label ) {
			if ( '' !== $title ) {
				$ds_label = $title;
			} elseif ( '' !== $title_tag ) {
				$ds_label = $title_tag;
			}
		}

		// Auto-correct post type when the requested one is unavailable.
		$post_type_obj = get_post_type_object( $post_type );
		$can_create    = $post_type_obj && current_user_can( $post_type_obj->cap->create_posts );

		if ( ! $can_create ) {
			$available = $this->post_type_service->get_creatable_post_type_slugs();
			if ( ! empty( $available ) ) {
				$post_type     = $available[0];
				$post_type_obj = get_post_type_object( $post_type );
			} else {
				return new \WP_Error(
					'forbidden',
					'You do not have permission to create posts of any type.',
					[ 'status' => 403 ]
				);
			}
		}

		$post_id = wp_insert_post( [
			'post_title'  => sanitize_text_field( $title ),
			'post_type'   => sanitize_text_field( $post_type ),
			'post_status' => sanitize_text_field( $status ),
		], true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$adapter = $this->page_resolver->resolve_adapter( null, $post_type );

		// Enable BB for the post only when the BB adapter is being used.
		if ( $adapter instanceof BeaverBuilderPageAdapter ) {
			update_post_meta( $post_id, '_fl_builder_enabled', true );
		}
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$options = [];
		if ( $ds_uuid ) {
			$options['design_system_uuid'] = $ds_uuid;
		}
		if ( $ds_label ) {
			$options['design_system_label'] = $ds_label;
		}

		$result = PageImporter::import( $html, $post_id, $adapter, $options );

		// Stamp the page's first MCP hash so cross-tool drift (e.g., DS token
		// updates) is detectable on the read path. Without this, the
		// externally_modified flag stays inert until the first update-page-*
		// call lands.
		$content_hash = $this->hash_verifier->store_mcp_hash( $post_id, $adapter );

		// Store debug data for the MCP generation path.
		if ( ( defined( 'FL_DS_DEBUG' ) && FL_DS_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			update_post_meta( $post_id, DebugProvider::META_RAW_HTML, $html );
			update_post_meta( $post_id, DebugProvider::META_DEBUG_META, wp_json_encode( [
				'timestamp'          => gmdate( 'c' ),
				'design_system_uuid' => $ds_uuid,
				'source'             => 'mcp',
			] ) );
		}

		$response = [
			'post_id'      => $post_id,
			'url'          => $this->page_resolver->get_post_url( $post_id ),
			'sections'     => $result['sections'],
			'errors'       => $result['errors'],
			'content_hash' => $content_hash,
		];

		if ( ! empty( $result['warnings'] ) ) {
			$response['warnings'] = $result['warnings'];
		}

		if ( $result['ds_uuid'] ) {
			$response['design_system_uuid'] = $result['ds_uuid'];
		}

		// When a new DS was auto-created, direct the agent to populate brief
		// and guidance via the post-generation flow (analyze + update). Spec
		// input is routed through create-design-system, so the page tools
		// never receive brief/guidance directly; an auto-created DS always
		// needs this follow-up.
		if ( $result['ds_uuid'] && ! $ds_uuid ) {
			$response['next_steps'] = [
				'Share the draft URL with the user and let them preview the result.',
				'Call analyze-page-design with post_id ' . $post_id . ' to get the CSS context and art direction template.',
				'Write observational creative guidance (~1,200 words) based on what you generated -- describe the design in observational tone ("the palette centers on...", "typography uses..."), not prescriptive rules.',
				'Call update-design-system-guidance with uuid "' . $result['ds_uuid'] . '" to save the guidance, passing brief: <one-paragraph identity summary capturing what the brand is, who it is for, and any product family context>.',
			];
		}

		return $response;
	}
}
