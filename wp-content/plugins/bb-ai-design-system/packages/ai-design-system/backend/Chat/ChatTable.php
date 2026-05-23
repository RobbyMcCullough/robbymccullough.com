<?php

namespace FL\DesignSystem\Chat;

class ChatTable {

	/**
	 * Get the full table name including the WordPress prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'fl_ds_chat';
	}

	/**
	 * Create or update the chat table using dbDelta.
	 *
	 * Safe to call on every activation and upgrade — dbDelta only
	 * applies changes when the schema differs.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			item_type varchar(20) NOT NULL,
			item_id bigint(20) unsigned NOT NULL,
			messages longtext NOT NULL,
			summary longtext DEFAULT NULL,
			mode varchar(32) DEFAULT NULL,
			active_job longtext DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_type_item (user_id, item_type, item_id),
			KEY item_type_id (item_type, item_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
