<?php

namespace FL\DesignSystem\Adapters\WordPress;

use FL\DesignSystem\Contracts\AuthInterface;

class WordPressAuth implements AuthInterface {

	public const CAPABILITY_MAP = [
		'manage_settings'     => 'manage_options',
		'edit_design_systems' => 'edit_others_design_systems',
	];

	/**
	 * Check whether a user is authenticated.
	 *
	 * @return bool
	 */
	public function check(): bool {
		return is_user_logged_in();
	}

	/**
	 * Check whether the current user has a given capability.
	 *
	 * Maps abstract capability names to WordPress capabilities.
	 *
	 * @param  string $capability Abstract capability name (e.g., 'manage_settings').
	 * @param  mixed  ...$args    Additional arguments (e.g., post ID for post-level checks).
	 * @return bool
	 */
	public function can( string $capability, ...$args ): bool {
		$wp_cap = self::CAPABILITY_MAP[ $capability ] ?? $capability;
		return current_user_can( $wp_cap, ...$args );
	}

	/**
	 * Permission callback for REST routes requiring authenticated admin access.
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return $this->editor_permission_callback();
	}

	/**
	 * Permission callback for REST routes requiring administrator access.
	 *
	 * @return bool
	 */
	public function admin_permission_callback(): bool {
		return $this->check() && current_user_can( 'manage_options' );
	}

	/**
	 * Permission callback for REST routes requiring editor access.
	 *
	 * Requires the `edit_others_design_systems` capability.
	 *
	 * @return bool
	 */
	public function editor_permission_callback(): bool {
		return $this->check() && current_user_can( 'edit_others_design_systems' );
	}

	/**
	 * Permission callback for REST routes requiring content-creator access.
	 *
	 * Delegates to the filterable `user_can_create_content()` gate so every
	 * DS surface that cares about "trust this user with raw template/css/js"
	 * resolves through a single filter. Consumers include: block-editor chat
	 * and code tab, BB chat and save-guard, BB asset localization, REST/MCP
	 * write routes, read-only MCP abilities, kit imports, and DS admin page
	 * visibility. Site admins can broaden access for a specific role by
	 * filtering `fl_ds_user_can_create_content`.
	 *
	 * @return bool
	 */
	public function content_creator_permission_callback(): bool {
		return $this->check() && self::user_can_create_content();
	}

	/**
	 * Whether the current user is trusted with raw DS block content.
	 *
	 * Wraps `current_user_can( 'unfiltered_html' )` with the
	 * `fl_ds_user_can_create_content` filter so a site admin can broaden
	 * access for a specific role without granting the raw capability.
	 * This is the single gate used across the DS plugin for any surface
	 * that writes, edits, or displays raw template/css/js. See
	 * {@see self::content_creator_permission_callback()} for the full
	 * list of consumers.
	 *
	 * @return bool
	 */
	public static function user_can_create_content(): bool {
		return apply_filters(
			'fl_ds_user_can_create_content',
			current_user_can( 'unfiltered_html' )
		);
	}

	/**
	 * Canonical gate for design system read/edit/delete operations.
	 *
	 * Returns true when the current user authored the post, or has the
	 * `edit_others_design_systems` capability. Used by REST and MCP
	 * surfaces that operate on a specific design system. The "Creator"
	 * tier (unfiltered_html without edit_others_posts) sees only their
	 * own design systems; "Editor" tier sees all. See
	 * {@see \FL\DesignSystem\DesignSystem\DesignSystemPostType::grant_caps_to_roles()}
	 * for how the underlying capabilities are distributed across roles.
	 *
	 * @param  \WP_Post $post Design system post.
	 * @return bool
	 */
	public static function can_edit_design_system( \WP_Post $post ): bool {
		$is_owner = get_current_user_id() === (int) $post->post_author;
		return $is_owner || current_user_can( 'edit_others_design_systems' );
	}

	/**
	 * Permission callback for REST routes readable by any logged-in user.
	 *
	 * @return bool
	 */
	public function read_permission_callback(): bool {
		return $this->check();
	}

	/**
	 * Get the current user's ID.
	 *
	 * @return int
	 */
	public function user_id(): int {
		return get_current_user_id();
	}
}
