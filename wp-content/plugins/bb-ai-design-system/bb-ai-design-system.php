<?php
/**
 * Plugin Name: Beaver Builder AI
 * Version: 0.6.1
 * Description: AI-powered block, layout, and design system creation for Beaver Builder and the WordPress Block Editor, with static HTML export.
 * Author: The Beaver Builder Team
 * Copyright: (c) 2026 Beaver Builder
 * License: GNU General Public License v2.0
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: fl-design-system
 * Requires at least: 6.7
 * Requires PHP: 8.2
 */
namespace FL\DesignSystem;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$autoloader = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p>Beaver Builder AI: Run <code>composer install</code> to install dependencies.</p></div>';
	});
	return;
}
require_once $autoloader;

if ( ! defined( 'FL_DESIGN_SYSTEM_FILE' ) ) {
	define( 'FL_DESIGN_SYSTEM_FILE', __FILE__ );
}

if ( ! defined( 'FL_DESIGN_SYSTEM_DIR' ) ) {
	define( 'FL_DESIGN_SYSTEM_DIR', trailingslashit( wp_normalize_path( __DIR__ ) ) );
}

// Load each package's bootstrap file.
foreach ( glob( __DIR__ . '/packages/*/backend/bootstrap.php' ) as $bootstrap ) {
	require_once $bootstrap;
}
