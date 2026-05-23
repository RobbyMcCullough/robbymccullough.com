<?php

namespace FL\DesignSystem\Mcp\Abilities\Pages;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\FormatSpecLoader;
use FL\DesignSystem\Mcp\Support\HashVerifier;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Page\PageExporter;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * MCP ability: get-page-html.
 *
 * Reads a page's content as a spec-compliant HTML document. Returns the
 * staging-draft version when one exists. Optional `include: ["format_spec"]`
 * appends the editing-mode format spec inline.
 */
class GetPageHtml extends BaseAbility {

	private PageResolver $page_resolver;
	private HashVerifier $hash_verifier;
	private FormatSpecLoader $loader;

	public function __construct( PageResolver $page_resolver, HashVerifier $hash_verifier, FormatSpecLoader $loader ) {
		$this->page_resolver = $page_resolver;
		$this->hash_verifier = $hash_verifier;
		$this->loader        = $loader;
	}

	public function name(): string {
		return 'beaver-builder-ai/get-page-html';
	}

	public function definition(): array {
		return [
			'label'               => 'Get Page HTML',
			'description'         => 'Reads a page\'s current content as a spec-compliant HTML document. Returns the full HTML with all sections, CSS, JS, and design tokens. WARNING: this is a heavy response that can consume a large share of an agent\'s token budget on long pages. For any targeted read or edit, use get-page-outline + get-page-blocks + update-page-blocks instead -- that path uses dramatically fewer tokens and supports multi-block edits in a single call. Only use get-page-html when you genuinely need the full page as one document (e.g., rewriting the entire page with update-page-html). If a staged draft exists for this page, returns the draft version. Returns the HTML, the page\'s design system UUID (pass this to get-design-system to load tokens and creative direction before editing), and a content_hash required by update tools. format_spec is no longer included by default; pass include: ["format_spec"] to request the editing-mode spec inline, otherwise call get-format-spec separately.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id' => [
						'type'        => 'integer',
						'description' => 'WordPress post ID.',
					],
					'include' => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => [ 'format_spec' ],
						],
						'description' => 'Optional. Heavy fields to include beyond the default response. Omit (or pass []) for html, content_hash, and identity fields only. Pass ["format_spec"] to also include the editing-mode format spec, useful when the agent does not already have it cached.',
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
				'summary'     => "Reads a page's content as HTML for editing",
			],
		];
	}

	public function execute( array $input ) {
		$post_id = (int) ( $input['post_id'] ?? 0 );
		$include = ! empty( $input['include'] ) ? $input['include'] : null;

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		// If this post has a staging draft, return the draft instead.
		$staging_source   = null;
		$staging_draft_id = (int) get_post_meta( $post_id, PageOverrideProvider::STAGING_DRAFT_META_KEY, true );

		if ( $staging_draft_id ) {
			$draft = get_post( $staging_draft_id );
			if ( $draft && 'trash' !== $draft->post_status ) {
				$staging_source = $post_id;
				$post_id        = $staging_draft_id;
			} else {
				// Stale reference — clean up.
				delete_post_meta( $post_id, PageOverrideProvider::STAGING_DRAFT_META_KEY );
			}
		}

		// If the requested post is itself a staging draft, note the source.
		if ( ! $staging_source ) {
			$source_id = (int) get_post_meta( $post_id, PageOverrideProvider::STAGING_SOURCE_META_KEY, true );
			if ( $source_id ) {
				$staging_source = $source_id;
			}
		}

		$adapter = $this->page_resolver->resolve_adapter( $post_id );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$result       = PageExporter::export( $post_id, $adapter );
		$post         = get_post( $post_id );
		$content_hash = $this->hash_verifier->compute_content_hash( $result );

		$response = [
			'post_id'            => $post_id,
			'title'              => $result['title'],
			'status'             => $result['status'],
			'post_type'          => $post ? $post->post_type : 'page',
			'design_system_uuid' => $result['design_system_uuid'],
			'html'               => $result['html'],
			'content_hash'       => $content_hash,
		];

		if ( null !== $include && in_array( 'format_spec', $include, true ) ) {
			$response['format_spec'] = $this->loader->load_format_spec( 'editing', [ 'has_design_system' => true ] );
		}

		if ( $staging_source ) {
			$response['staging_source'] = $staging_source;
			$response['message']        = 'This page has a staging draft from a previous editing session. The draft content is shown above. Let the user know and ask if they would like to continue editing it. If they do not want this draft, they can delete it manually in WordPress.';
		}

		// Detect external modifications since the last MCP edit.
		if ( $this->hash_verifier->detect_external_modification( $post_id, $content_hash ) ) {
			$response['externally_modified'] = true;
			$response['message']             = HashVerifier::EXTERNAL_MODIFICATION_READ_MESSAGE;
		}

		return $response;
	}
}
