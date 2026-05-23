<?php

namespace FL\DesignSystem\Admin;

use FL\DesignSystem\Mcp\McpProvider;
use FL\DesignSystem\Services\PostTypeAccess;

class AdminAssetProvider {

	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	public function boot() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'fl-design-systems' !== $page ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Enqueue admin scripts and styles for the Design Systems page.
	 */
	public function enqueue() {
		$dir = $this->plugin->dir;
		$url = $this->plugin->url;

		$js_file = $dir . 'packages/ai-design-system/frontend/build/admin.js';
		$ver     = file_exists( $js_file ) ? (string) filemtime( $js_file ) : $this->plugin->version;

		wp_enqueue_script(
			'fl-design-systems',
			src: $url . 'packages/ai-design-system/frontend/build/admin.js',
			deps: [
				'react',
				'react-dom',
				'wp-i18n',
			],
			ver: $ver,
			args: [ 'strategy' => 'defer' ],
		);

		$current_user = wp_get_current_user();

		$is_ssl  = is_ssl();
		$host    = wp_parse_url( site_url(), PHP_URL_HOST );
		$is_local = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );

		if ( ! $is_local ) {
			foreach ( array( '.local', '.test' ) as $tld ) {
				if ( str_ends_with( $host, $tld ) ) {
					$is_local = true;
					break;
				}
			}
		}

		$is_local_or_insecure = $is_local || ! $is_ssl;

		// Local dev override: the same flag that lets registration skip the
		// AS verification callback also unlocks the Authorize button on local
		// hosts so devs can exercise the OAuth flow against `wrangler dev`.
		if ( defined( 'FL_DS_MCP_OAUTH_SKIP_VERIFY' ) && FL_DS_MCP_OAUTH_SKIP_VERIFY
			&& ( 'local' === wp_get_environment_type() || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) ) {
			$is_local_or_insecure = false;
		}

		if ( $is_local ) {
			$local_reason = 'BB AI authorization requires a publicly accessible site. Local development sites cannot be reached by the authorization server.';
		} elseif ( ! $is_ssl ) {
			$local_reason = 'BB AI authorization requires HTTPS. Please enable SSL on your site to use this feature.';
		} else {
			$local_reason = '';
		}

		wp_localize_script( 'fl-design-systems', 'FLDesignSystems', [
			'apiBaseUrl'        => rest_url( 'fl-design-system/v1' ),
			'restNonce'         => wp_create_nonce( 'wp_rest' ),
			'userId'            => get_current_user_id(),
			'user'              => [
				'name' => ! empty( $current_user->display_name ) ? $current_user->display_name : ( ! empty( $current_user->user_nicename ) ? $current_user->user_nicename : $current_user->user_login ),
			],
			'restUrl'           => rest_url(),
			'siteUrl'           => site_url(),
			'mcpEndpoint'       => rest_url( 'mcp/mcp-adapter-default-server' ),
			'adminUrl'          => admin_url(),
			'wpVersionReady'    => version_compare( get_bloginfo( 'version' ), '6.9', '>=' ),
			'mcpAdapterActive'  => is_plugin_active( 'mcp-adapter/mcp-adapter.php' ),
			'username'          => $current_user->user_login,
			'isAdmin'           => current_user_can( 'manage_options' ),
			'isEditor'          => current_user_can( 'edit_others_design_systems' ),
			'bbUpdaterActive'   => isset( $GLOBALS['fl_ds_license_provider'] )
				? $GLOBALS['fl_ds_license_provider']->is_bb_updater_active()
				: false,
			'pluginVersion'     => $this->plugin->version,
			'logoUrl'           => $url . 'packages/ai-design-system/frontend/build/img/beaver.png',
			'mcpTools'          => McpProvider::get_admin_tool_list(),
			'buildPageUrl'      => ( new PostTypeAccess() )->get_build_page_url(),
			'mcpOAuth'          => [
				'connected'        => (bool) get_option( 'fl_ds_mcp_oauth_connected', false ),
				'siteHost'         => wp_parse_url( home_url(), PHP_URL_HOST ),
				'isLicensed'       => isset( $GLOBALS['fl_ds_license_provider'] )
					? $GLOBALS['fl_ds_license_provider']->is_licensed()
					: false,
				'mcpEndpointUrl'   => defined( 'FL_DS_MCP_OAUTH_SITE_URL' )
					? rtrim( FL_DS_MCP_OAUTH_SITE_URL, '/' ) . '/wp-json/mcp/mcp-adapter-default-server'
					: rest_url( 'mcp/mcp-adapter-default-server' ),
				'registerUrl'      => rest_url( 'fl-design-system/v1/mcp-oauth/register' ),
				'disconnectUrl'    => rest_url( 'fl-design-system/v1/mcp-oauth/disconnect' ),
				'isLocalOrInsecure' => $is_local_or_insecure,
				'localReason'      => $local_reason,
			],
		] );

		wp_enqueue_style(
			'fl-design-systems',
			src: $url . 'packages/ai-design-system/frontend/build/admin.css',
			deps: [],
			ver: $ver,
		);
	}

	/**
	 * Append the page body class for style scoping.
	 *
	 * @param  string $classes Existing body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		return $classes . ' fl-design-systems-page';
	}
}
