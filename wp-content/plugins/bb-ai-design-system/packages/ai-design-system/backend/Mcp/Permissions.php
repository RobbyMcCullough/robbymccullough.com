<?php

namespace FL\DesignSystem\Mcp;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;

/**
 * Shared permission gates for MCP abilities.
 *
 * Reads and writes both gate on the same trust today: any surface that
 * exposes raw template/css/js — whether returning it or accepting it —
 * requires the same `unfiltered_html` trust. Site admins can broaden
 * access for a specific role via the `fl_ds_user_can_create_content`
 * filter without granting the raw capability. Per-resource checks
 * (e.g. `edit_post` for a page, ownership for a design system) are
 * applied inside each ability's execute callback.
 */
final class Permissions {

	/**
	 * Default permission gate for every MCP ability.
	 */
	public static function content_creator(): bool {
		return is_user_logged_in() && WordPressAuth::user_can_create_content();
	}
}
