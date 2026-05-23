<?php
/**
 * Bootstrap for the ai-design-system package.
 *
 * Instantiates the core Plugin. Provider registration
 * happens on `init` via Plugin::register_providers().
 *
 * @package FL\DesignSystem
 */

namespace FL\DesignSystem;

use FL\DesignSystem\Chat\ChatTable;
use FL\DesignSystem\Generation\ThrottleTable;
use FL\DesignSystem\Usage\TokenUsageTable;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;

new Plugin( FL_DESIGN_SYSTEM_FILE );

/**
 * Handle ChatTable installation on plugin activation and upgrades.
 */
$fl_ds_chat_table_version_key = 'fl_ds_chat_table_version';
$fl_ds_chat_table_version     = '1.1';

$fl_ds_token_usage_table_version_key = 'fl_ds_token_usage_table_version';
$fl_ds_token_usage_table_version     = '1.2';

$fl_ds_throttle_table_version_key = 'fl_ds_throttle_table_version';
$fl_ds_throttle_table_version     = '1.0';

register_activation_hook(
	FL_DESIGN_SYSTEM_FILE,
	function () use (
		$fl_ds_chat_table_version_key,
		$fl_ds_chat_table_version,
		$fl_ds_token_usage_table_version_key,
		$fl_ds_token_usage_table_version,
		$fl_ds_throttle_table_version_key,
		$fl_ds_throttle_table_version
	) {
		ChatTable::create_table();
		update_option( $fl_ds_chat_table_version_key, $fl_ds_chat_table_version );
		TokenUsageTable::create_table();
		update_option( $fl_ds_token_usage_table_version_key, $fl_ds_token_usage_table_version );
		ThrottleTable::create_table();
		update_option( $fl_ds_throttle_table_version_key, $fl_ds_throttle_table_version );
		// M-15: copy in-flight concurrency state from transients into the
		// new SQL table so jobs that increment under the old code path
		// release correctly under the new code path post-deploy.
		ThrottleTable::migrate_transients();
		DesignSystemPostType::grant_caps_to_roles( true );
	}
);

add_action( 'admin_init', function () {
	DesignSystemPostType::grant_caps_to_roles();
} );

add_action( 'admin_init', function () use ( $fl_ds_chat_table_version_key, $fl_ds_chat_table_version ) {
	$current_version = get_option( $fl_ds_chat_table_version_key, '0' );

	if ( version_compare( $current_version, $fl_ds_chat_table_version, '<' ) ) {
		ChatTable::create_table();
		update_option( $fl_ds_chat_table_version_key, $fl_ds_chat_table_version );
	}
} );

add_action( 'admin_init', function () use ( $fl_ds_token_usage_table_version_key, $fl_ds_token_usage_table_version ) {
	$current_version = get_option( $fl_ds_token_usage_table_version_key, '0' );

	if ( version_compare( $current_version, $fl_ds_token_usage_table_version, '<' ) ) {
		TokenUsageTable::create_table();
		update_option( $fl_ds_token_usage_table_version_key, $fl_ds_token_usage_table_version );
	}
} );

add_action( 'admin_init', function () use ( $fl_ds_throttle_table_version_key, $fl_ds_throttle_table_version ) {
	// M-15 schema + transient migration on first admin-side request after
	// an in-place upgrade (no activation hook fires for those).
	$current_version = get_option( $fl_ds_throttle_table_version_key, '0' );

	if ( version_compare( $current_version, $fl_ds_throttle_table_version, '<' ) ) {
		ThrottleTable::create_table();
		ThrottleTable::migrate_transients();
		update_option( $fl_ds_throttle_table_version_key, $fl_ds_throttle_table_version );
	}
} );

$fl_ds_active_setting_cleanup_version_key = 'fl_ds_active_setting_cleanup_version';
$fl_ds_active_setting_cleanup_version     = '1.0';

add_action( 'admin_init', function () use ( $fl_ds_active_setting_cleanup_version_key, $fl_ds_active_setting_cleanup_version ) {
	// One-shot tombstone: the `designSystem.active` setting was retired
	// alongside the default-DS cleanup. Delete the orphan wp_option so
	// existing alpha installs don't carry it.
	$current_version = get_option( $fl_ds_active_setting_cleanup_version_key, '0' );

	if ( version_compare( $current_version, $fl_ds_active_setting_cleanup_version, '<' ) ) {
		delete_option( 'fl_design_system_active' );
		update_option( $fl_ds_active_setting_cleanup_version_key, $fl_ds_active_setting_cleanup_version );
	}
} );
