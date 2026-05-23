<?php

namespace FL\DesignSystem\BlockEditor;

use FL\DesignSystem\Contracts\PageEditorAdapterInterface;
use FL\DesignSystem\Services\Parser\AnnotationParser;
use FL\DesignSystem\Settings\SettingsMerger;

/**
 * Block Editor (Gutenberg) implementation of PageEditorAdapterInterface.
 *
 * Bridges the design system's section model with WordPress post_content
 * block grammar. DS blocks (fl-ds/*) are exported as editable sections;
 * all other blocks become opaque preserved markers.
 */
class BlockEditorPageAdapter implements PageEditorAdapterInterface {

	/**
	 * Import sections into a Gutenberg-managed post.
	 *
	 * DS sections become fl-ds/custom blocks. Preserved markers are matched
	 * back to existing blocks in post_content and kept as-is.
	 *
	 * @param int   $post_id  WordPress post ID.
	 * @param array $sections Array of section arrays and preserved markers.
	 * @return array Array of created block identifiers.
	 */
	public function import_sections( int $post_id, array $sections ): array {
		$post            = get_post( $post_id );
		$existing_blocks = $post ? parse_blocks( $post->post_content ) : [];

		// Filter out empty parser artifacts and reindex.
		$existing_blocks = array_values( array_filter( $existing_blocks, function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );

		// Build lookup of existing DS blocks by blockId for reconciliation.
		$existing_by_id = [];
		foreach ( $existing_blocks as $block ) {
			if ( str_starts_with( $block['blockName'] ?? '', 'fl-ds/' ) ) {
				$bid = $block['attrs']['blockId'] ?? '';
				if ( '' !== $bid ) {
					$existing_by_id[ $bid ] = $block;
				}
			}
		}

		$output_blocks = [];
		$created_ids   = [];

		foreach ( $sections as $index => $section ) {
			if ( ( $section['type'] ?? '' ) === 'preserved' ) {
				$preserved_block = $this->find_preserved_block( $section, $existing_blocks );
				if ( $preserved_block ) {
					$output_blocks[] = $preserved_block;
				}
				continue;
			}

			$incoming_node_id = $section['node_id'] ?? '';

			// Reuse existing blockId when the incoming section matches an existing DS block.
			$reuse_block_id = null;
			if ( '' !== $incoming_node_id && isset( $existing_by_id[ $incoming_node_id ] ) ) {
				$reuse_block_id = $incoming_node_id;
			}

			$block           = $this->build_ds_block( $section, $reuse_block_id );
			$output_blocks[] = $block;
			$created_ids[]   = $block['attrs']['blockId'];
		}

		$content = serialize_blocks( $output_blocks );

		// Preserve AI-generated block definitions through the save pipeline.
		// Content originates from our system, not user input.
		kses_remove_filters();
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		] );
		kses_init_filters();

		return $created_ids;
	}

	/**
	 * Export all blocks from a Gutenberg-managed post.
	 *
	 * DS blocks (fl-ds/*) are returned as editable sections with template,
	 * CSS, JS, and settings. All other blocks are returned as preserved
	 * marker entries.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array Array of section arrays and preserved markers.
	 */
	public function export_sections( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$blocks   = parse_blocks( $post->post_content );
		$sections = [];

		foreach ( $blocks as $index => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			if ( str_starts_with( $block['blockName'], 'fl-ds/' ) ) {
				$section = $this->export_ds_block( $block, $index );
				if ( $section ) {
					$sections[] = $section;
				}
			} else {
				$sections[] = [
					'type'           => 'preserved',
					'preserved_type' => 'wp',
					'block_name'     => $block['blockName'],
					'label'          => $this->derive_block_label( $block ),
					'block_index'    => $index,
					'raw_block'      => $block,
				];
			}
		}

		return $sections;
	}

	/**
	 * Return a lightweight outline of all blocks on a Gutenberg post.
	 *
	 * Walks parsed blocks including innerBlocks for nesting. DS blocks
	 * are typed as ds-custom; all others are wp-block.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array Array of block descriptor arrays.
	 */
	public function get_outline( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$blocks = parse_blocks( $post->post_content );

		$outline = [];
		foreach ( $blocks as $index => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$entry = $this->build_outline_entry( $block, $index );
			if ( $entry ) {
				$outline[] = $entry;
			}
		}

		return $outline;
	}

	/**
	 * Build an outline entry for a single parsed block.
	 *
	 * @param array $block Parsed block array.
	 * @param int   $index Block index in the post.
	 * @return array|null Block descriptor or null.
	 */
	private function build_outline_entry( array $block, int $index ): ?array {
		$block_name = $block['blockName'] ?? '';
		if ( '' === $block_name ) {
			return null;
		}

		$attrs   = $block['attrs'] ?? [];
		$node_id = $attrs['blockId'] ?? 'block-' . $index;

		if ( str_starts_with( $block_name, 'fl-ds/' ) ) {
			$label = $attrs['metadata']['name'] ?? $attrs['name'] ?? '';

			if ( '' === $label ) {
				$label = str_replace( 'fl-ds/', '', $block_name );
				$label = ucfirst( str_replace( [ '-', '_' ], ' ', $label ) );
			}

			$entry = [
				'node_id'     => $node_id,
				'type'        => 'ds-custom',
				'module_type' => $block_name,
				'label'       => $label,
			];
		} else {
			$label = $this->derive_block_label( $block );

			$entry = [
				'node_id'     => $node_id,
				'type'        => 'wp-block',
				'module_type' => $block_name,
				'label'       => $label,
			];
		}

		// Recurse into innerBlocks.
		$inner_blocks = $block['innerBlocks'] ?? [];
		if ( ! empty( $inner_blocks ) ) {
			$children = [];
			foreach ( $inner_blocks as $child_index => $child ) {
				if ( empty( $child['blockName'] ) ) {
					continue;
				}
				$child_entry = $this->build_outline_entry( $child, $child_index );
				if ( $child_entry ) {
					$children[] = $child_entry;
				}
			}
			if ( ! empty( $children ) ) {
				$entry['children'] = $children;
			}
		}

		return $entry;
	}

	/**
	 * Remove all DS blocks from a Gutenberg-managed post.
	 *
	 * Filters out fl-ds/* blocks and preserves everything else.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public function clear_layout( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$blocks = parse_blocks( $post->post_content );

		$filtered = array_filter( $blocks, function ( $block ) {
			$name = $block['blockName'] ?? '';
			return '' !== $name && ! str_starts_with( $name, 'fl-ds/' );
		} );

		$content = serialize_blocks( array_values( $filtered ) );

		// Preserve AI-generated block definitions through the save pipeline.
		// Content originates from our system, not user input.
		kses_remove_filters();
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		] );
		kses_init_filters();
	}

	/**
	 * Export a DS block as an editable section.
	 *
	 * @param array $block Parsed block array.
	 * @param int   $index Block index in the post.
	 * @return array|null Section data or null.
	 */
	private function export_ds_block( array $block, int $index ): ?array {
		$attrs    = $block['attrs'] ?? [];
		$settings = (array) ( $attrs['settings'] ?? [] );
		$label    = $attrs['metadata']['name'] ?? $attrs['name'] ?? '';

		$block_id = $attrs['blockId'] ?? 'block-' . $index;

		$inner  = $block['innerHTML'] ?? '';
		$parsed = ContentParser::parse( $inner );

		return [
			'node_id'  => $block_id,
			'label'    => $label,
			'template' => $parsed['template'],
			'css'      => $parsed['css'],
			'js'       => $parsed['js'],
			'settings' => $settings,
		];
	}

	/**
	 * Derive a human-readable label from a WP block.
	 *
	 * @param array $block Parsed block array.
	 * @return string Label string.
	 */
	private function derive_block_label( array $block ): string {
		$inner = $block['innerHTML'] ?? '';

		$text = trim( wp_strip_all_tags( $inner ) );
		if ( '' !== $text ) {
			$label = mb_substr( $text, 0, 50 );
			if ( mb_strlen( $text ) > 50 ) {
				$label .= '...';
			}
			return $label;
		}

		$name  = $block['blockName'] ?? 'Unknown';
		$parts = explode( '/', $name );
		return ucfirst( end( $parts ) );
	}

	/**
	 * Build an fl-ds/custom block array from a DS section.
	 *
	 * @param array       $section        Section data with label, template, css, js, settings.
	 * @param string|null $reuse_block_id Existing blockId to reuse instead of generating a new UUID.
	 * @return array Block array for serialize_blocks().
	 */
	private function build_ds_block( array $section, ?string $reuse_block_id = null ): array {
		$label = $section['label'] ?? '';
		$css   = $section['css'] ?? '';
		$js    = $section['js'] ?? '';

		// Sections from PageImporter arrive with annotated HTML in 'html'.
		// Parse it into a Mustache template + settings (same as the BB adapter).
		$html = $section['html'] ?? '';
		if ( '' !== $html ) {
			$parsed   = AnnotationParser::parse( $html );
			$template = $parsed['template'];
			$settings = $parsed['settings'];
		} else {
			$template = $section['template'] ?? '';
			$settings = $section['settings'] ?? [];
		}

		$attrs = [
			'blockId'  => $reuse_block_id ?? wp_generate_uuid4(),
			'name'     => '',
			'metadata' => [ 'name' => $label ],
			'settings' => (object) $settings,
		];

		$inner_parts = [];
		if ( '' !== $template ) {
			$inner_parts[] = "<template>\n{$template}\n</template>";
		}
		if ( '' !== $css ) {
			$inner_parts[] = '<style>' . $css . '</style>';
		}
		if ( '' !== $js ) {
			$inner_parts[] = '<script type="text/fl-ds">' . $js . '</script>';
		}

		$innerHTML = implode( "\n", $inner_parts );

		return [
			'blockName'    => 'fl-ds/custom',
			'attrs'        => $attrs,
			'innerBlocks'  => [],
			'innerHTML'    => $innerHTML,
			'innerContent' => [ $innerHTML ],
		];
	}

	/**
	 * Export a single section by its blockId.
	 *
	 * Walks the full block tree (including innerBlocks) so DS blocks nested
	 * inside core block containers (e.g. core/group) are reachable.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $node_id Block identifier (blockId UUID).
	 * @return array|null Section data or null if not found.
	 */
	public function export_section( int $post_id, string $node_id ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$blocks = parse_blocks( $post->post_content );
		$found  = $this->find_ds_block_in_tree( $blocks, $node_id );

		if ( null === $found ) {
			return null;
		}

		// Index is only used as a fallback blockId when one is missing; nested
		// DS blocks have UUIDs by construction, so 0 is fine.
		return $this->export_ds_block( $found, 0 );
	}

	/**
	 * Update a single section's content in place by blockId.
	 *
	 * Walks the full block tree (including innerBlocks) so DS blocks nested
	 * inside core block containers can be updated. The matched block is
	 * rebuilt 1:1 in its parent's innerBlocks array; innerBlocks count is
	 * unchanged so no innerContent sync is needed.
	 *
	 * Only the fields present in $updates are changed; omitted fields
	 * keep their current values.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $node_id Block identifier (blockId UUID).
	 * @param array  $updates Partial section data (html, css, js).
	 * @return true|\WP_Error True on success, WP_Error if not found.
	 */
	public function update_section( int $post_id, string $node_id, array $updates ): true|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post', 'Post not found.', [ 'status' => 404 ] );
		}

		$blocks  = parse_blocks( $post->post_content );

		$rebuild = function ( array $matched ) use ( $updates, $node_id ): array {
			$current = $this->export_ds_block( $matched, 0 );
			if ( ! $current ) {
				return $matched;
			}

			$merged = [
				'label' => $current['label'],
				'css'   => array_key_exists( 'css', $updates ) ? $updates['css'] : $current['css'],
				'js'    => array_key_exists( 'js', $updates ) ? $updates['js'] : $current['js'],
			];

			// Handle HTML/template: if html is provided, use it (will be parsed by build_ds_block).
			// If only settings is provided (no html), merge into existing settings.
			// Otherwise keep existing template.
			if ( array_key_exists( 'html', $updates ) ) {
				$merged['html'] = $updates['html'];
			} elseif ( array_key_exists( 'settings', $updates ) && is_array( $updates['settings'] ) ) {
				$merged['template'] = $current['template'];
				$merged['settings'] = SettingsMerger::merge( $current['settings'] ?? [], $updates['settings'] );
			} else {
				$merged['template'] = $current['template'];
				$merged['settings'] = $current['settings'];
			}

			$rebuilt = $this->build_ds_block( $merged, $node_id );

			// Preserve block metadata from the original.
			$rebuilt['attrs']['metadata'] = $matched['attrs']['metadata'] ?? $rebuilt['attrs']['metadata'];

			return $rebuilt;
		};

		$found = $this->update_ds_block_in_tree( $blocks, $node_id, $rebuild );

		if ( ! $found ) {
			return new \WP_Error( 'node_not_found', 'No DS block found with the given node_id.', [ 'status' => 404 ] );
		}

		$content = serialize_blocks( $blocks );

		kses_remove_filters();
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		] );
		kses_init_filters();

		return true;
	}

	/**
	 * Add a single section to a Gutenberg-managed post at a given position.
	 *
	 * @param int    $post_id  WordPress post ID.
	 * @param array  $section  Section data (label, template, css, js, settings).
	 * @param string $position Position descriptor ("first", "last", "before:{blockId}", "after:{blockId}").
	 * @return string|\WP_Error New blockId, or WP_Error on failure.
	 */
	public function add_section( int $post_id, array $section, string $position = 'last' ): string|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post', 'Post not found.', [ 'status' => 404 ] );
		}

		$blocks = parse_blocks( $post->post_content );
		$blocks = array_values( array_filter( $blocks, function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );

		// Defense-in-depth: reject nested anchor IDs explicitly. The MCP path
		// catches this earlier in validate_add_position; this guard exists for
		// direct adapter callers (e.g. tests, internal REST controllers).
		if ( str_contains( $position, ':' ) ) {
			[ $rel, $ref_id ] = explode( ':', $position, 2 );
			if ( in_array( $rel, [ 'before', 'after' ], true ) && '' !== $ref_id ) {
				if ( 'nested' === $this->locate_ds_block_in_tree( $blocks, $ref_id ) ) {
					return new \WP_Error(
						'nested_anchor_not_supported',
						sprintf( 'Block "%s" is nested inside another block. Nested add/move is not currently supported -- use a top-level block as the anchor.', $ref_id ),
						[ 'status' => 400 ]
					);
				}
			}
		}

		$block = $this->build_ds_block( $section );
		$index = $this->resolve_block_position( $blocks, $position );

		array_splice( $blocks, $index, 0, [ $block ] );

		$content = serialize_blocks( $blocks );

		kses_remove_filters();
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		] );
		kses_init_filters();

		return $block['attrs']['blockId'];
	}

	/**
	 * Remove a single DS block from a Gutenberg-managed post.
	 *
	 * Walks the full block tree (including innerBlocks) so DS blocks nested
	 * inside core block containers can be removed. When matched inside a
	 * parent's innerBlocks, the corresponding null in the parent's
	 * innerContent is also spliced out to keep the WP block grammar invariant
	 * (count(innerBlocks) === count(nulls in innerContent)).
	 *
	 * Only fl-ds/* blocks can be removed. Non-DS blocks are rejected.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $node_id Block identifier (blockId UUID).
	 * @return true|\WP_Error True on success, WP_Error if not found or not a DS block.
	 */
	public function remove_section( int $post_id, string $node_id ): true|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post', 'Post not found.', [ 'status' => 404 ] );
		}

		$blocks = parse_blocks( $post->post_content );
		$found  = $this->remove_ds_block_from_tree( $blocks, $node_id );

		if ( ! $found ) {
			return new \WP_Error( 'node_not_found', 'No block found with the given node_id.', [ 'status' => 404 ] );
		}

		$content = serialize_blocks( array_values( $blocks ) );

		kses_remove_filters();
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		] );
		kses_init_filters();

		return true;
	}

	/**
	 * Move a block relative to another block in a Gutenberg-managed post.
	 *
	 * @param int    $post_id        WordPress post ID.
	 * @param string $node_id        Block to move (blockId).
	 * @param string $target_node_id Reference block (blockId).
	 * @param string $position       "before" or "after".
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function move_section( int $post_id, string $node_id, string $target_node_id, string $position ): true|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'invalid_post', 'Post not found.', [ 'status' => 404 ] );
		}

		$blocks = parse_blocks( $post->post_content );
		$blocks = array_values( array_filter( $blocks, function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );

		// Reject nested anchors (source or target). Move only operates between
		// top-level blocks today.
		foreach ( [ $node_id, $target_node_id ] as $candidate ) {
			if ( 'nested' === $this->locate_ds_block_in_tree( $blocks, $candidate ) ) {
				return new \WP_Error(
					'nested_anchor_not_supported',
					sprintf( 'Block "%s" is nested inside another block. Nested add/move is not currently supported -- use a top-level block as the anchor.', $candidate ),
					[ 'status' => 400 ]
				);
			}
		}

		// Find and remove the source block.
		$source_block = null;
		foreach ( $blocks as $i => $block ) {
			$block_id = $block['attrs']['blockId'] ?? '';
			if ( $block_id === $node_id ) {
				$source_block = $block;
				array_splice( $blocks, $i, 1 );
				break;
			}
		}

		if ( ! $source_block ) {
			return new \WP_Error( 'node_not_found', sprintf( 'Source block "%s" not found.', $node_id ), [ 'status' => 404 ] );
		}

		// Find the target block and determine insertion index.
		$target_index = null;
		foreach ( $blocks as $i => $block ) {
			$block_id = $block['attrs']['blockId'] ?? '';
			if ( $block_id === $target_node_id ) {
				$target_index = 'before' === $position ? $i : $i + 1;
				break;
			}
		}

		if ( null === $target_index ) {
			return new \WP_Error( 'target_not_found', sprintf( 'Target block "%s" not found.', $target_node_id ), [ 'status' => 404 ] );
		}

		array_splice( $blocks, $target_index, 0, [ $source_block ] );

		$content = serialize_blocks( $blocks );

		kses_remove_filters();
		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => wp_slash( $content ),
		] );
		kses_init_filters();

		return true;
	}

	/**
	 * Resolve a position descriptor to a numeric index in a blocks array.
	 *
	 * @param array  $blocks   Parsed blocks array.
	 * @param string $position Position descriptor ("first", "last", "before:{blockId}", "after:{blockId}").
	 * @return int Numeric index.
	 */
	private function resolve_block_position( array $blocks, string $position ): int {
		if ( 'first' === $position ) {
			return 0;
		}

		if ( 'last' === $position ) {
			return count( $blocks );
		}

		if ( str_contains( $position, ':' ) ) {
			[ $rel, $ref_id ] = explode( ':', $position, 2 );
			foreach ( $blocks as $i => $block ) {
				$block_id = $block['attrs']['blockId'] ?? '';
				if ( $block_id === $ref_id ) {
					return 'before' === $rel ? $i : $i + 1;
				}
			}
		}

		// Fallback: append.
		return count( $blocks );
	}

	/**
	 * Find the matching existing block for a preserved marker.
	 *
	 * @param array $marker         Preserved marker data.
	 * @param array $existing_blocks Parsed blocks from current post_content.
	 * @return array|null Matching block or null.
	 */
	private function find_preserved_block( array $marker, array $existing_blocks ): ?array {
		$block_index = $marker['block_index'] ?? null;
		$block_name  = $marker['block_name'] ?? '';

		// Try exact index match first.
		if ( null !== $block_index && isset( $existing_blocks[ $block_index ] ) ) {
			$candidate = $existing_blocks[ $block_index ];
			if ( ( $candidate['blockName'] ?? '' ) === $block_name ) {
				return $candidate;
			}
		}

		// Fallback: find first block with matching name.
		foreach ( $existing_blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === $block_name ) {
				return $block;
			}
		}

		return null;
	}

	/**
	 * Find a DS block by its blockId, recursing into innerBlocks.
	 *
	 * Depth-first; first match wins. Only matches blocks whose blockName
	 * starts with `fl-ds/` to avoid colliding with non-DS attribute IDs.
	 *
	 * @param array  $blocks   Parsed blocks array.
	 * @param string $block_id Target blockId UUID.
	 * @return array|null The matching block array, or null.
	 */
	private function find_ds_block_in_tree( array $blocks, string $block_id ): ?array {
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			if ( str_starts_with( $block['blockName'], 'fl-ds/' ) ) {
				$bid = $block['attrs']['blockId'] ?? '';
				if ( $bid === $block_id ) {
					return $block;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = $this->find_ds_block_in_tree( $block['innerBlocks'], $block_id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	/**
	 * Replace a DS block in the tree by its blockId, recursing into innerBlocks.
	 *
	 * The walker is metadata-agnostic. The `$rebuild` callback receives the
	 * matched block array and is responsible for any in-block carry-over
	 * (e.g. attrs.metadata) that the caller wants preserved. innerBlocks
	 * count is unchanged on update -- the matched block is replaced 1:1 in
	 * its containing array, so no innerContent sync is needed.
	 *
	 * @param array    $blocks   Parsed blocks array (mutated by reference).
	 * @param string   $block_id Target blockId UUID.
	 * @param callable $rebuild  Function( array $matched ): array — returns the new block.
	 * @return bool True if a match was found and replaced.
	 */
	private function update_ds_block_in_tree( array &$blocks, string $block_id, callable $rebuild ): bool {
		foreach ( $blocks as $i => &$block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			if ( str_starts_with( $block['blockName'], 'fl-ds/' ) ) {
				$bid = $block['attrs']['blockId'] ?? '';
				if ( $bid === $block_id ) {
					$blocks[ $i ] = $rebuild( $block );
					return true;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				if ( $this->update_ds_block_in_tree( $block['innerBlocks'], $block_id, $rebuild ) ) {
					return true;
				}
			}
		}
		unset( $block );

		return false;
	}

	/**
	 * Remove a DS block from the tree by its blockId, recursing into innerBlocks.
	 *
	 * On a match found inside a parent's innerBlocks at index $j, splices
	 * both the matched innerBlocks[$j] AND the $j-th null in the parent's
	 * innerContent so the WP block grammar invariant
	 * (count(innerBlocks) === count(nulls in innerContent)) holds.
	 * `serialize_block()` consumes one entry from innerBlocks per null in
	 * innerContent — without this sync, the post_content would corrupt
	 * surrounding siblings on round-trip.
	 *
	 * Top-level removes are the caller's responsibility (no parent
	 * innerContent exists at the post level).
	 *
	 * @param array  $blocks   Parsed blocks array (mutated by reference).
	 * @param string $block_id Target blockId UUID.
	 * @return bool True if a match was found and removed.
	 */
	private function remove_ds_block_from_tree( array &$blocks, string $block_id ): bool {
		// Pass 1: top-level match in the array we were given.
		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			if ( ! str_starts_with( $block['blockName'], 'fl-ds/' ) ) {
				continue;
			}
			$bid = $block['attrs']['blockId'] ?? '';
			if ( $bid === $block_id ) {
				array_splice( $blocks, $i, 1 );
				return true;
			}
		}

		// Pass 2: recurse into each block's innerBlocks. When a match is found
		// inside one of these, we must sync that parent's innerContent here
		// (the recursion can't reach back up to the parent's innerContent on
		// its own).
		foreach ( $blocks as &$block ) {
			if ( empty( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
				continue;
			}

			$child_index = $this->find_ds_block_index_in_array( $block['innerBlocks'], $block_id );
			if ( null !== $child_index ) {
				array_splice( $block['innerBlocks'], $child_index, 1 );
				$this->splice_nth_null_from_inner_content( $block, $child_index );
				return true;
			}

			// Match might be deeper.
			if ( $this->remove_ds_block_from_tree( $block['innerBlocks'], $block_id ) ) {
				return true;
			}
		}
		unset( $block );

		return false;
	}

	/**
	 * Find the immediate-children index of a DS block by blockId, or null.
	 *
	 * @param array  $blocks   Blocks array (single level — does not recurse).
	 * @param string $block_id Target blockId UUID.
	 * @return int|null Index in $blocks, or null if not present at this level.
	 */
	private function find_ds_block_index_in_array( array $blocks, string $block_id ): ?int {
		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			if ( ! str_starts_with( $block['blockName'], 'fl-ds/' ) ) {
				continue;
			}
			$bid = $block['attrs']['blockId'] ?? '';
			if ( $bid === $block_id ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Splice the nth null from a block's innerContent in place.
	 *
	 * No-op if innerContent is absent or has fewer than $n+1 nulls. Used by
	 * remove to keep WP block grammar in sync after splicing innerBlocks.
	 *
	 * @param array $block Block array (mutated by reference).
	 * @param int   $n     Zero-based null index to drop.
	 */
	private function splice_nth_null_from_inner_content( array &$block, int $n ): void {
		if ( empty( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
			return;
		}

		$null_seen = 0;
		foreach ( $block['innerContent'] as $i => $piece ) {
			if ( null !== $piece ) {
				continue;
			}
			if ( $null_seen === $n ) {
				array_splice( $block['innerContent'], $i, 1 );
				return;
			}
			$null_seen++;
		}
	}

	/**
	 * Determine where a DS block lives in the tree.
	 *
	 * Returns one of: 'top-level' (matched in $blocks directly), 'nested'
	 * (matched inside some block's innerBlocks at any depth), or 'not-found'.
	 *
	 * Used by the add/move guard to give callers a clear "this block exists
	 * but isn't a top-level anchor" signal.
	 *
	 * @param array  $blocks   Parsed blocks array.
	 * @param string $block_id Target blockId UUID.
	 * @return string One of 'top-level', 'nested', 'not-found'.
	 */
	private function locate_ds_block_in_tree( array $blocks, string $block_id ): string {
		// Top-level scan.
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			if ( ! str_starts_with( $block['blockName'], 'fl-ds/' ) ) {
				continue;
			}
			$bid = $block['attrs']['blockId'] ?? '';
			if ( $bid === $block_id ) {
				return 'top-level';
			}
		}

		// Nested scan.
		foreach ( $blocks as $block ) {
			if ( empty( $block['innerBlocks'] ) || ! is_array( $block['innerBlocks'] ) ) {
				continue;
			}
			if ( null !== $this->find_ds_block_in_tree( $block['innerBlocks'], $block_id ) ) {
				return 'nested';
			}
		}

		return 'not-found';
	}
}
