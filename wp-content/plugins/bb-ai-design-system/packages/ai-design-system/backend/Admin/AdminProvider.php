<?php

namespace FL\DesignSystem\Admin;

use FL\DesignSystem\Adapters\WordPress\WordPressAuth;

class AdminProvider {

	const PARENT_SLUG = 'edit.php?post_type=fl-builder-template';
	const PAGE_SLUG   = 'fl-design-systems';

	/**
	 * Return the effective capability to require for admin page visibility.
	 *
	 * Resolves through the filterable DS trust gate so that site admins
	 * who broaden `fl_ds_user_can_create_content` also broaden admin-page
	 * access. When the filter grants access, we pass `read` to WordPress
	 * (a capability every logged-in user has); otherwise `do_not_allow`
	 * prevents registration from being user-visible. Per-section gates
	 * inside the admin page (Usage, Settings) stay on `manage_options`.
	 */
	private static function capability(): string {
		return WordPressAuth::user_can_create_content() ? 'read' : 'do_not_allow';
	}

	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function boot() {
		add_filter( 'fl_builder_user_templates_admin_menu', [ $this, 'filter_submenu' ] );
		add_action( 'admin_menu', [ $this, 'register_menu' ], 1000 );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', [ $this, 'reorder_admin_menu' ] );
		add_filter( 'parent_file', [ $this, 'parent_file' ] );
		add_filter( 'submenu_file', [ $this, 'submenu_file' ], 10, 2 );
	}

	/**
	 * Insert "Beaver Builder AI" at the top of BB's submenu.
	 *
	 * Hooked to the fl_builder_user_templates_admin_menu filter
	 * which BB Plugin fires after rebuilding its submenu array.
	 * Key 25 places this above Themer (50) and all BB items (100+).
	 *
	 * @param array $menu The submenu items array.
	 * @return array
	 */
	public function filter_submenu( $menu ) {
		$menu[25] = array(
			__( 'Beaver Builder AI', 'fl-design-system' ),
			self::capability(),
			self::PAGE_SLUG,
			__( 'Beaver Builder AI', 'fl-design-system' ),
		);

		ksort( $menu );

		return $menu;
	}

	/**
	 * Register the Beaver Builder AI admin menu page.
	 *
	 * Runs at priority 1000 so other plugins have finished adding
	 * or removing menus. When BB is active, the submenu entry is
	 * already positioned via filter_submenu() -- this just registers
	 * the page callback. When BB is absent, creates a standalone
	 * top-level menu.
	 */
	public function register_menu() {
		$has_bb_menu    = $this->has_beaver_builder_menu();
		$has_bb_admin   = class_exists( 'FLBuilderUserAccess' ) && \FLBuilderUserAccess::current_user_can( 'builder_admin' );

		if ( $has_bb_menu && $has_bb_admin ) {
			$this->register_submenu_page_callback();
		} else {
			add_menu_page(
				__( 'Beaver Builder AI', 'fl-design-system' ),
				__( 'Beaver Builder', 'fl-design-system' ),
				self::capability(),
				self::PAGE_SLUG,
				[ $this, 'render' ],
				'dashicons-welcome-widgets-menus',
				61
			);
		}
	}

	/**
	 * Position the Beaver Builder menu right after Appearance.
	 *
	 * @param array $menu_order Default menu order (array of menu slugs).
	 * @return array Reordered menu.
	 */
	public function reorder_admin_menu( array $menu_order ): array {
		$appearance_index = array_search( 'themes.php', $menu_order, true );
		if ( false === $appearance_index ) {
			return $menu_order;
		}

		$bb_slug = $this->has_beaver_builder_menu() ? self::PARENT_SLUG : self::PAGE_SLUG;

		$menu_order = array_values( array_diff( $menu_order, [ $bb_slug ] ) );

		// Recalculate after removal.
		$appearance_index = array_search( 'themes.php', $menu_order, true );

		array_splice( $menu_order, $appearance_index + 1, 0, [ $bb_slug ] );

		return $menu_order;
	}

	/**
	 * Manually register the page callback for the submenu entry.
	 *
	 * Since we use the fl_builder_user_templates_admin_menu filter
	 * instead of add_submenu_page(), WordPress doesn't know about
	 * the page callback. This registers it the same way BB Plugin
	 * handles its "Add New" page.
	 */
	private function register_submenu_page_callback() {
		global $_registered_pages;

		$hookname = get_plugin_page_hookname( self::PAGE_SLUG, self::PARENT_SLUG );

		if ( current_user_can( self::capability() ) ) {
			add_action( $hookname, [ $this, 'render' ] );
			$_registered_pages[ $hookname ] = true;
		}
	}

	/**
	 * Set the parent menu as active when on the Beaver Builder AI page.
	 *
	 * @param string $parent_file The current parent file.
	 * @return string
	 */
	public function parent_file( $parent_file ) {
		if ( $this->is_design_system_page() ) {
			return self::PARENT_SLUG;
		}
		return $parent_file;
	}

	/**
	 * Set the submenu item as active when on the Beaver Builder AI page.
	 *
	 * @param string $submenu_file The current submenu file.
	 * @param string $parent_file  The current parent file.
	 * @return string
	 */
	public function submenu_file( $submenu_file, $parent_file ) {
		if ( $this->is_design_system_page() ) {
			return self::PAGE_SLUG;
		}
		return $submenu_file;
	}

	/**
	 * Check if the current admin page is the Beaver Builder AI page.
	 *
	 * @return bool
	 */
	private function is_design_system_page() {
		global $pagenow;
		return 'admin.php' === $pagenow && isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'];
	}

	/**
	 * Check whether Beaver Builder's top-level admin menu exists.
	 *
	 * @return bool
	 */
	private function has_beaver_builder_menu() {
		global $menu;

		if ( empty( $menu ) ) {
			return false;
		}

		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) && self::PARENT_SLUG === $item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the admin URL for the settings page.
	 *
	 * When Beaver Builder is active the page lives under BB's
	 * submenu; otherwise it's a standalone top-level page.
	 *
	 * @param string $hash Optional hash fragment (e.g. '#/settings').
	 * @return string Full admin URL.
	 */
	public static function page_url( string $hash = '' ): string {
		$base = class_exists( 'FLBuilderModel' )
			? self::PARENT_SLUG . '&page=' . self::PAGE_SLUG
			: 'admin.php?page=' . self::PAGE_SLUG;

		return admin_url( $base . $hash );
	}

	/**
	 * Render the root element for the React app.
	 */
	public function render() {
		echo '<div id="fl-design-systems-root"></div>';
	}
}
