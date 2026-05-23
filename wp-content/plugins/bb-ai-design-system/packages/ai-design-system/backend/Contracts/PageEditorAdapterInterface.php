<?php

namespace FL\DesignSystem\Contracts;

/**
 * Interface for page editor adapters that handle section import/export.
 *
 * Each adapter bridges the design system's section model with a specific
 * page editor's storage format (Beaver Builder, Block Editor, etc.).
 */
interface PageEditorAdapterInterface {

	/**
	 * Import parsed sections into the editor's storage for a given post.
	 *
	 * Each section has: name, label, html (annotated), css, js
	 *
	 * @param int   $post_id  WordPress post ID.
	 * @param array $sections Array of section arrays.
	 * @return array Array of created node/block IDs.
	 */
	public function import_sections( int $post_id, array $sections ): array;

	/**
	 * Export all design system blocks from a post as section arrays.
	 *
	 * Each section should have: node_id, label, template, css, js, settings
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array Array of section arrays.
	 */
	public function export_sections( int $post_id ): array;

	/**
	 * Remove all design system blocks from a post.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public function clear_layout( int $post_id ): void;

	/**
	 * Export a single section by node ID.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $node_id Block/node identifier.
	 * @return array|null Section data or null if not found.
	 */
	public function export_section( int $post_id, string $node_id ): ?array;

	/**
	 * Return a lightweight outline of all blocks on a page.
	 *
	 * Each entry describes a block with its node ID, type, label,
	 * and capabilities -- enough for discovery without full content.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array Array of block descriptor arrays.
	 */
	public function get_outline( int $post_id ): array;

	/**
	 * Update a single section's content in place.
	 *
	 * Only the fields present in $updates are changed; omitted fields
	 * keep their current values.
	 *
	 * Supported keys in $updates:
	 * - 'html'     — Annotated HTML; parsed into template + settings.
	 * - 'css'      — Complete CSS for the block.
	 * - 'js'       — Complete JavaScript for the block.
	 * - 'settings' — Partial settings merge (only when 'html' is absent).
	 *                When 'html' is provided, settings come from annotation parsing.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $node_id Block/node identifier.
	 * @param array  $updates Partial section data (html, css, js, settings -- only changed fields).
	 * @return true|\WP_Error True on success, WP_Error if node not found.
	 */
	public function update_section( int $post_id, string $node_id, array $updates ): true|\WP_Error;

	/**
	 * Add a single section to the layout at a given position.
	 *
	 * @param int    $post_id  WordPress post ID.
	 * @param array  $section  Section data (html, css, js, label).
	 * @param string $position Position descriptor ("first", "last", "before:{nodeId}", "after:{nodeId}").
	 * @return string|\WP_Error New node/block ID, or WP_Error on failure.
	 */
	public function add_section( int $post_id, array $section, string $position = 'last' ): string|\WP_Error;

	/**
	 * Remove a single section from the layout.
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $node_id Block/node identifier.
	 * @return true|\WP_Error True on success, WP_Error if not found.
	 */
	public function remove_section( int $post_id, string $node_id ): true|\WP_Error;

	/**
	 * Move a section relative to another section.
	 *
	 * @param int    $post_id        WordPress post ID.
	 * @param string $node_id        Block/node to move.
	 * @param string $target_node_id Reference block/node.
	 * @param string $position       "before" or "after".
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function move_section( int $post_id, string $node_id, string $target_node_id, string $position ): true|\WP_Error;
}
