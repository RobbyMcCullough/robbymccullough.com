<?php

namespace FL\DesignSystem\Mcp\Abilities\Blocks;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\HashVerifier;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Page\PageExporter;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\Services\BlockService;

/**
 * MCP ability: get-page-blocks.
 *
 * Reads one or more blocks by node_id. Cheaper than get-page-html for
 * targeted reads because only the requested blocks are returned. When
 * a staging draft exists, reads from the draft.
 */
class GetPageBlocks extends BaseAbility {

	private PageResolver $page_resolver;
	private HashVerifier $hash_verifier;
	private BlockService $block_service;

	public function __construct( PageResolver $page_resolver, HashVerifier $hash_verifier, BlockService $block_service ) {
		$this->page_resolver = $page_resolver;
		$this->hash_verifier = $hash_verifier;
		$this->block_service = $block_service;
	}

	public function name(): string {
		return 'beaver-builder-ai/get-page-blocks';
	}

	public function definition(): array {
		return [
			'label'               => 'Get Page Blocks',
			'description'         => 'Reads one or more blocks from a page by node_id. This is the default read tool for inspecting specific blocks before editing -- call it after get-page-outline, passing the node_ids you need. Works with DS blocks (returns html, css, js, settings), native BB modules (returns rendered html, instance css/js, a restricted settings map of editable text fields computed from BB\'s inline-editor filter, plus global status), and structural rows/columns (returns rendered html plus instance css/js). For DS blocks, html is annotated HTML following the format spec (with data-field, data-repeater, etc.) and is read-write -- pass it back through update-page-blocks for structural edits, or pass settings for cheap top-level field updates without re-sending HTML. For native modules, rows, and columns, html is rendered output (read-only) intended to ground CSS edits in the actual markup; the capabilities object reports it as "read" and update-page-blocks will reject html for these block types. Rendered html for a row includes its nested columns and modules. Single-block reads are just node_ids: [one_id]. Much cheaper than get-page-html on large pages because only the requested blocks are returned. Missing or invalid node_ids are surfaced in the response errors array without failing the whole call. Optional include array (any of html, css, js, settings) returns only those fields per block to trim payload -- omit or pass [] for the full default response. Unsupported fields per block type are silently omitted; each block ships a capabilities object whose values are "read-write" (readable and writable), "read" (readable but rejected by update-page-blocks), or "none" (neither readable nor writable).',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'  => [
						'type'        => 'integer',
						'description' => 'WordPress post ID.',
					],
					'node_ids' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'minItems'    => 1,
						'description' => 'One or more block identifiers from get-page-outline. Pass a single-element array for a single-block read.',
					],
					'include'  => [
						'type'        => 'array',
						'items'       => [
							'type' => 'string',
							'enum' => [ 'html', 'css', 'js', 'settings' ],
						],
						'description' => 'Optional. Fields to return per block. Omit (or pass []) to return every field the block type supports -- current behavior. Common patterns: ["settings"] for cheap top-level field updates without re-fetching HTML (DS blocks return free-form settings; native BB modules return a restricted settings view of editable text fields), ["css"] for styling work without the html cost, ["html", "css"] when grounding CSS edits in the actual markup, ["css", "js"] for code-only inspection. For DS blocks ["html"] returns annotated source (read-write); for native modules and rows/columns ["html"] returns rendered output (read-only -- update-page-blocks rejects html for these block types). Unsupported fields per block type are silently omitted from each block; the capabilities object on each block signals which fields are read-write, read-only, or unavailable.',
					],
				],
				'required'   => [ 'post_id', 'node_ids' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [ 'readonly' => true ],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'blocks',
				'summary'     => 'Reads one or more blocks by node_id',
			],
		];
	}

	public function execute( array $input ) {
		$post_id  = (int) ( $input['post_id'] ?? 0 );
		$node_ids = $input['node_ids'] ?? [];
		$include  = ! empty( $input['include'] ) && is_array( $input['include'] ) ? $input['include'] : null;

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( ! is_array( $node_ids ) || empty( $node_ids ) ) {
			return new \WP_Error(
				'missing_node_ids',
				'The node_ids field is required and must contain at least one block ID.',
				[ 'status' => 400 ]
			);
		}

		// If this post has a staging draft, read from the draft instead.
		$staging_draft_id = (int) get_post_meta( $post_id, PageOverrideProvider::STAGING_DRAFT_META_KEY, true );
		if ( $staging_draft_id ) {
			$draft = get_post( $staging_draft_id );
			if ( $draft && 'trash' !== $draft->post_status ) {
				$post_id = $staging_draft_id;
			}
		}

		$adapter = $this->page_resolver->resolve_adapter( $post_id );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$blocks = [];
		$errors = [];

		foreach ( $node_ids as $node_id ) {
			$node_id = (string) $node_id;

			if ( '' === $node_id ) {
				$errors[] = [
					'node_id' => '',
					'error'   => 'Empty node_id.',
					'code'    => 'missing_node_id',
				];
				continue;
			}

			$data = $this->block_service->get_block_data( $post_id, $node_id, $adapter, $include );

			if ( is_wp_error( $data ) ) {
				$errors[] = [
					'node_id' => $node_id,
					'error'   => $data->get_error_message(),
					'code'    => $data->get_error_code(),
				];
				continue;
			}

			$blocks[] = array_merge( [ 'node_id' => $node_id ], $data );
		}

		$export       = PageExporter::export( $post_id, $adapter );
		$content_hash = $this->hash_verifier->compute_content_hash( $export );

		$response = [
			'post_id'      => $post_id,
			'content_hash' => $content_hash,
			'blocks'       => $blocks,
		];

		if ( $this->hash_verifier->detect_external_modification( $post_id, $content_hash ) ) {
			$response['externally_modified'] = true;
			$response['message']             = HashVerifier::EXTERNAL_MODIFICATION_READ_MESSAGE;
		}

		if ( ! empty( $errors ) ) {
			$response['errors'] = $errors;
		}

		return $response;
	}
}
