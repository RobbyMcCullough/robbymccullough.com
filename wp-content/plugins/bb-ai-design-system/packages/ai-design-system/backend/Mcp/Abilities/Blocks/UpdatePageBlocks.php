<?php

namespace FL\DesignSystem\Mcp\Abilities\Blocks;

use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Mcp\Support\BlockOperations;
use FL\DesignSystem\Mcp\Support\HashVerifier;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Mcp\Support\StagingService;
use FL\DesignSystem\Page\PageOverrideProvider;

/**
 * MCP ability: update-page-blocks.
 *
 * Applies an ordered batch of update/add/remove/move operations against
 * a page. Routes writes to a staging draft for non-draft posts, gates
 * on a page-level content_hash, and rejects unacknowledged drift.
 *
 * Per-op success is independent: a failed op does not abort later ops.
 * The actual op handlers live in {@see BlockOperations}; this class
 * owns the batch envelope (validation, hash-gating, staging, results).
 */
class UpdatePageBlocks extends BaseAbility {

	private PageResolver $page_resolver;
	private HashVerifier $hash_verifier;
	private StagingService $staging_service;
	private BlockOperations $block_operations;

	public function __construct(
		PageResolver $page_resolver,
		HashVerifier $hash_verifier,
		StagingService $staging_service,
		BlockOperations $block_operations
	) {
		$this->page_resolver    = $page_resolver;
		$this->hash_verifier    = $hash_verifier;
		$this->staging_service  = $staging_service;
		$this->block_operations = $block_operations;
	}

	public function name(): string {
		return 'beaver-builder-ai/update-page-blocks';
	}

	public function definition(): array {
		return [
			'label'               => 'Update Page Blocks',
			'description'         => 'Applies one or more block operations to a page in a single batch: update existing blocks, add new blocks, remove blocks, or move blocks. This is the default write tool for any targeted edit -- prefer it over update-page-html whenever you are not rewriting the entire page. Each operation is an object with an "op" field (one of "update", "add", "remove", "move") plus op-specific fields -- see examples below. Operations apply in the order given, against state-after-prior-ops in the batch for update/remove/move (e.g. an "add" followed by a "move" that targets an existing block works fine). NOTE: "add" ops cannot reference a block added earlier in the same batch -- the position field of an "add" must reference a block that already existed when the batch started. To chain adds with relative positioning, use separate update-page-blocks calls. Each operation succeeds or fails independently: a failed op does not block later ops, and the response surfaces per-op status in the results array. DS-block settings updates can include a per-op `warnings` array on the result entry when the patch contained settings keys the block template does not declare; status remains ok because valid keys still apply. Inspect warnings on the first response: the merge stores unknown keys, so a second call with the same key sees it in stored settings and will not warn again. One content_hash is checked for the whole batch. For published, private, and pending pages, changes are saved to a staging draft. If get-page-html, get-page-outline, or get-page-blocks returned externally_modified: true, you MUST get explicit user confirmation and pass acknowledge_external_changes: true. Always share the preview URL with the user after updating. html is only writable on DS blocks. For native modules, rows, and columns, html is read-only (rendered output for grounding CSS edits) -- the op will fail with html_not_writable if you pass it. Use css, js, or (for native modules) settings instead. Examples: update a DS block -> {"op": "update", "node_id": "abc123", "html": "<section>...</section>", "css": "..."}; update a native BB module text field -> {"op": "update", "node_id": "abc123", "settings": {"heading": "New Title"}} (native settings are restricted to BB\'s inline-editor text fields); restyle a native module -> {"op": "update", "node_id": "abc123", "css": ".my-button { ... }"}; add a DS block after an existing one -> {"op": "add", "position": "after:abc123", "html": "<section>...</section>", "css": "...", "label": "Hero"}; remove -> {"op": "remove", "node_id": "abc123"}; move -> {"op": "move", "node_id": "abc123", "target_node_id": "def456", "position": "before"}.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'post_id'                      => [
						'type'        => 'integer',
						'description' => 'WordPress post ID.',
					],
					'content_hash'                 => [
						'type'        => 'string',
						'description' => 'Hash from get-page-outline, get-page-blocks, or get-page-html response. Required to prevent overwriting changes made since the page was last fetched.',
					],
					'operations'                   => [
						'type'        => 'array',
						'minItems'    => 1,
						'description' => 'Ordered list of operations. Each item is an object with an "op" string field plus op-specific fields. Required fields per op: update -> node_id + at least one of html/css/js/settings. add -> html, css, label (optional: js, position). remove -> node_id. move -> node_id, target_node_id, position.',
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'op'             => [
									'type'        => 'string',
									'enum'        => [ 'update', 'add', 'remove', 'move' ],
									'description' => 'The operation to perform. One of: "update", "add", "remove", "move".',
								],
								'node_id'        => [
									'type'        => 'string',
									'description' => 'Block identifier. Required for update, remove, and move ops.',
								],
								'target_node_id' => [
									'type'        => 'string',
									'description' => 'Reference block for move ops. Required only for move.',
								],
								'position'       => [
									'type'        => 'string',
									'description' => 'For add: a string -- "first", "last" (default), or the literal colon-separated form "before:{nodeId}" / "after:{nodeId}" (e.g. "after:abc123"). Node IDs in `before:`/`after:` must refer to blocks present at the start of the batch -- you cannot chain off a block added earlier in the same call; use a separate `update-page-blocks` call for that. For move: "before" or "after" (relative to target_node_id).',
								],
								'html'           => [
									'type'        => 'string',
									'description' => 'Annotated section HTML. For update on DS blocks: the <section>...</section> markup with data-field annotations, exactly as returned by get-page-blocks. For add: the full <section>...</section> for the new block. For DS-block updates that only change top-level field values (e.g. a heading or a button label), prefer the settings parameter to avoid re-sending the full HTML. Rejected for native BB modules, rows, and columns (the op fails with html_not_writable) -- their html is read-only rendered output.',
								],
								'css'            => [
									'type'        => 'string',
									'description' => 'Complete CSS for the block. For update on DS blocks, replaces all section CSS. For update on native BB modules, sets instance CSS. For add, required. CSS is auto-scoped to this block at render time (BB wraps every selector with a generated .fl-node-{id} prefix). Use bare class selectors from the block HTML; do not include .fl-node-{id} or any wrapper selector yourself. Selectors targeting body, :root, html, or elements outside this block will not match. For page-level CSS, use update-page-assets.',
								],
								'js'             => [
									'type'        => 'string',
									'description' => 'Complete JavaScript for the block. Same semantics as css.',
								],
								'label'          => [
									'type'        => 'string',
									'description' => 'Human-readable label for the section (e.g. "Hero", "Features Grid"). Required for add ops.',
								],
								'settings'       => [
									'type'        => 'object',
									'description' => 'Partial settings patch on update ops. For DS blocks: free-form settings -- send the same shape as the settings object with only the keys you want to change; unspecified keys are preserved. Nested objects are merged recursively (e.g. {"cta": {"text": "Tap"}} updates only cta.text and leaves cta.href alone). For repeaters (arrays of item objects), send the array up through the last item you are editing; use {} as a placeholder for items you are not changing, and any items past the end of your array are preserved. Repeater rows support add, remove, and reorder through the merge: extend past the existing length to add, pass null at an index to remove that row (remaining rows shift down), or write rows into new positions to reorder. Template-deep changes (different fields, different row markup) still require html. Example: to change one feature label inside the second tab of a tabs module, send {"tabs": [{}, {"features": [{}, {"label": "New"}]}]}. To remove the last item of a 5-row repeater, send {"items": [{}, {}, {}, {}, null]}. For native BB modules: a restricted view of editable text fields keyed by field name (e.g. {"heading": "New Title"}); connected fields are rejected and any non-text-field key is rejected. Call get-page-blocks first to discover available fields. Cannot be combined with html in the same op.',
								],
							],
							'required'             => [ 'op' ],
						],
					],
					'acknowledge_external_changes' => [
						'type'        => 'boolean',
						'description' => 'Set to true only after the user has explicitly confirmed they want to overwrite content that was modified outside of MCP. Required when get-page-html, get-page-outline, or get-page-blocks returned externally_modified: true.',
					],
				],
				'required'   => [ 'post_id', 'content_hash', 'operations' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [
					'destructive' => true,
					'idempotent'  => false,
				],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'blocks',
				'summary'     => 'Applies update/add/remove/move operations to blocks in a batch',
			],
		];
	}

	public function execute( array $input ) {
		$post_id      = (int) ( $input['post_id'] ?? 0 );
		$content_hash = $input['content_hash'] ?? '';
		$operations   = $input['operations'] ?? [];
		$acknowledged = ! empty( $input['acknowledge_external_changes'] );

		$error = $this->validate_post( $post_id );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( '' === $content_hash ) {
			return new \WP_Error(
				'missing_content_hash',
				'The content_hash field is required. Call get-page-outline or get-page-blocks first to obtain the current content hash.',
				[ 'status' => 400 ]
			);
		}

		if ( ! is_array( $operations ) || empty( $operations ) ) {
			return new \WP_Error(
				'missing_operations',
				'The operations field is required and must contain at least one operation.',
				[ 'status' => 400 ]
			);
		}

		$adapter = $this->page_resolver->resolve_adapter( $post_id );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		$drift_error = $this->hash_verifier->check_external_modifications(
			$post_id,
			$acknowledged,
			$adapter,
			'Call update-page-blocks again with acknowledge_external_changes: true after getting user confirmation.'
		);
		if ( is_wp_error( $drift_error ) ) {
			return $drift_error;
		}

		$staging = $this->staging_service->resolve_staging_target( $post_id, $adapter );
		if ( is_wp_error( $staging ) ) {
			return $staging;
		}

		$post_id        = $staging['post_id'];
		$adapter        = $staging['adapter'];
		$source_post_id = $staging['source_post_id'];

		$hash_error = $this->hash_verifier->verify_content_hash( $post_id, $content_hash, $adapter, $staging['draft_is_new'] );
		if ( is_wp_error( $hash_error ) ) {
			return $hash_error;
		}

		$ds_uuid       = get_post_meta( $post_id, PageOverrideProvider::DS_REF_META_KEY, true ) ?: null;
		$results       = [];
		$any_succeeded = false;

		foreach ( $operations as $index => $op ) {
			if ( ! is_array( $op ) ) {
				$results[] = [
					'op_index' => $index,
					'op'       => null,
					'status'   => 'error',
					'code'     => 'invalid_op',
					'error'    => 'Operation must be an object.',
				];
				continue;
			}

			$result = $this->block_operations->apply( $post_id, $adapter, $op, $ds_uuid );

			$entry = [
				'op_index' => $index,
				'op'       => $op['op'] ?? null,
			];

			if ( is_wp_error( $result ) ) {
				$entry['status'] = 'error';
				$entry['error']  = $result->get_error_message();
				$entry['code']   = $result->get_error_code();
			} else {
				$entry['status'] = 'ok';
				$any_succeeded   = true;

				if ( is_array( $result ) ) {
					if ( ! empty( $result['warnings'] ) ) {
						$entry['warnings'] = array_values( $result['warnings'] );
					}
					if ( isset( $op['node_id'] ) ) {
						$entry['node_id'] = (string) $op['node_id'];
					}
				} elseif ( is_string( $result ) && '' !== $result ) {
					$entry['node_id'] = $result;
				} elseif ( isset( $op['node_id'] ) ) {
					$entry['node_id'] = (string) $op['node_id'];
				}
			}

			$results[] = $entry;
		}

		$new_hash = $any_succeeded
			? $this->hash_verifier->store_mcp_hash( $post_id, $adapter )
			: $content_hash;

		$response = [
			'post_id'      => $post_id,
			'url'          => $this->page_resolver->get_post_url( $post_id ),
			'content_hash' => $new_hash,
			'results'      => $results,
		];

		if ( ! $any_succeeded ) {
			$response['all_failed'] = true;
		}

		if ( $any_succeeded && $source_post_id ) {
			$response['staging_source'] = $source_post_id;
			$response['message']        = 'Changes saved to a staging draft. The original page has not been modified. Share the preview URL with the user and ask them to publish when ready.';
		}

		return $response;
	}
}
