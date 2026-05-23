<?php
/**
 * Plugin updater for Beaver Builder AI.
 *
 * Audit gaps tracked from the v1 security audit:
 *
 * - M-8 (full integrity check, deferred). Hash or signature verification of
 *   the downloaded zip is not implemented. Closing it requires the
 *   bb-updates server (updates.wpbeaverbuilder.com) to ship a hash or
 *   signature alongside the `package` URL. No server-side scaffold exists
 *   today; that work is greenfield. The current WP-side defenses are a
 *   host allowlist on the `package` URL, an `https://` scheme requirement,
 *   forced sslverify in UpdateApiClient::api_request, and response-shape
 *   validation in update_check. Together these narrow the window to a
 *   compromised TLS path against the canonical host, but they do not
 *   detect a poisoned zip served from that host.
 *
 * - M-9 (license at rest, partial). UpdateApiClient::api_request now POSTs
 *   parameters in the request body so the license key is no longer in the
 *   outbound URL. The license still ends up in the wp_options
 *   `_site_transient_update_plugins` row because the server embeds it in
 *   the returned `package` URL (class-fl-update-api.php:332-341). Full
 *   removal requires the server to switch to opaque per-request download
 *   tokens; that is server-side work and is deferred.
 */

namespace FL\DesignSystem\Updater;

/**
 * Handles WordPress plugin update checks for the Design System.
 *
 * Hooks into WordPress update transients and plugins_api to check
 * for available updates from the BB update server.
 *
 * @package FL\DesignSystem\Updater
 */
class Updater {

	/**
	 * Default allowlist of hosts permitted to serve update package zips.
	 *
	 * The BB update server embeds the canonical host in every `package`
	 * URL; anything else is treated as malformed and discarded. Filterable
	 * via fl_ds_updater_package_host_allowlist for agencies that proxy the
	 * update server through a private mirror.
	 */
	private const DEFAULT_PACKAGE_HOST_ALLOWLIST = [ 'updates.wpbeaverbuilder.com' ];

	/**
	 * Permissive version regex.
	 *
	 * The BB update server emits four-part versions (2.10.1.6) and pre-release
	 * suffixes (1.6-alpha.1, 2.11-alpha.2), so strict semver would reject
	 * legitimate values. We require at least a major version, allow up to
	 * four dotted numeric segments, and accept an optional pre-release
	 * suffix made of lowercase ASCII alphanumerics, dots, and hyphens.
	 */
	private const VERSION_PATTERN = '/^\d+(\.\d+){0,4}(-[a-z0-9.-]+)?$/i';

	/** @var array Product config: name, version, slug, type. */
	private array $product;

	/** @var callable Returns the active license key string. */
	private $get_license;

	/** @var UpdateApiClient */
	private UpdateApiClient $api;

	/** @var ?object Cached API response for this request cycle. */
	private ?object $response = null;

	/**
	 * @param array    $product     Product config (name, version, slug, type).
	 * @param callable $get_license Callable that returns the license key.
	 */
	public function __construct( array $product, callable $get_license ) {
		$this->product     = $product;
		$this->get_license = $get_license;
		$this->api         = new UpdateApiClient();
	}

	/**
	 * Register WordPress hooks for update checks.
	 */
	public function boot(): void {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'update_check' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 99, 3 );
	}

	/**
	 * Check for available updates when WordPress refreshes plugin transients.
	 *
	 * @param object $transient WordPress update transient.
	 * @return object Modified transient.
	 */
	public function update_check( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}
		if ( ! isset( $transient->checked ) ) {
			$transient->checked = [];
		}

		$response = $this->get_response();
		$plugin   = $this->get_plugin_file();

		if ( isset( $response->error ) && 'No update available.' !== $response->error ) {
			return $transient;
		}

		$transient->last_checked                      = time();
		$transient->checked[ $this->product['slug'] ] = $this->product['version'];

		// Response-shape validation (audit finding M-8 partial). The version
		// must look like a recognizable version string and the package URL,
		// when present, must use https against an allowlisted host. An empty
		// package is a valid "no update available for unlicensed sites"
		// signal and is treated as a no-op rather than a malformed response.
		$validation = $this->validate_response_shape( $response );
		if ( is_wp_error( $validation ) ) {
			$this->log_invalid_response( $validation );
			return $transient;
		}

		if ( isset( $response->new_version ) && version_compare( $response->new_version, $this->product['version'], '>' ) ) {
			$transient->response[ $plugin ]               = new \stdClass();
			$transient->response[ $plugin ]->slug         = $response->slug;
			$transient->response[ $plugin ]->plugin       = $plugin;
			$transient->response[ $plugin ]->new_version  = $response->new_version;
			$transient->response[ $plugin ]->url          = $response->homepage ?? '';
			$transient->response[ $plugin ]->package      = $response->package ?? '';
			$transient->response[ $plugin ]->tested       = $response->tested ?? '';
			$transient->response[ $plugin ]->requires_php = $response->requires_php ?? '';

			if ( empty( $response->package ) ) {
				$transient->response[ $plugin ]->upgrade_notice = esc_html__(
					'Please enter a valid license key to enable automatic updates.',
					'fl-design-system'
				);
			}
		} else {
			$transient->no_update[ $plugin ] = (object) [
				'id'            => $plugin,
				'slug'          => $this->product['slug'],
				'plugin'        => $plugin,
				'new_version'   => $this->product['version'],
				'url'           => '',
				'package'       => '',
				'icons'         => [],
				'banners'       => [],
				'banners_rtl'   => [],
				'tested'        => '',
				'requires_php'  => '',
				'compatibility' => new \stdClass(),
			];
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the WordPress updates detail lightbox.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action API action.
	 * @param object $args   Request args.
	 * @return mixed Plugin info object or default result.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== $this->product['slug'] ) {
			return $result;
		}

		$response = $this->get_response();

		if ( isset( $response->error ) ) {
			$info              = new \stdClass();
			$info->name        = $this->product['name'];
			$info->version     = $this->product['version'];
			$info->slug        = $this->product['slug'];
			$info->plugin_name = $this->product['name'];
			$info->homepage    = 'https://www.wpbeaverbuilder.com/';
			$info->sections    = [];
			return $info;
		}

		$info                = new \stdClass();
		$info->name          = $this->product['name'];
		$info->version       = $response->new_version ?? $this->product['version'];
		$info->slug          = $response->slug ?? $this->product['slug'];
		$info->plugin_name   = $response->plugin_name ?? $this->product['name'];
		$info->author        = $response->author ?? '';
		$info->homepage      = $response->homepage ?? '';
		$info->requires      = $response->requires ?? '';
		$info->requires_php  = $response->requires_php ?? '';
		$info->tested        = $response->tested ?? '';
		$info->last_updated  = $response->last_updated ?? '';
		$info->download_link = $response->package ?? '';
		$info->sections      = isset( $response->sections ) ? (array) $response->sections : [];

		return $info;
	}

	/**
	 * Get or cache the API response for this product.
	 *
	 * @return object API response.
	 */
	private function get_response(): object {
		if ( null !== $this->response ) {
			return $this->response;
		}

		$license = call_user_func( $this->get_license );

		if ( empty( $license ) ) {
			$error          = new \stdClass();
			$error->error   = 'No license key.';
			$this->response = $error;
			return $this->response;
		}

		$this->response = $this->api->check_update(
			$license,
			network_home_url(),
			$this->product
		);

		return $this->response;
	}

	/**
	 * Resolve the main plugin file path relative to the plugins directory.
	 *
	 * @return string Plugin file path (e.g. "bb-ai-design-system/bb-ai-design-system.php").
	 */
	private function get_plugin_file(): string {
		return $this->product['slug'] . '/' . $this->product['slug'] . '.php';
	}

	/**
	 * Validate the shape of an update API response.
	 *
	 * Audit finding M-8 (partial). A poisoned or malformed response could
	 * persuade WordPress to fetch a download from an unintended host or
	 * present an attacker-controlled version number to admins. The checks
	 * here cover the WP-side defense surface: scheme, host, and version
	 * format. Hash and signature verification of the downloaded zip is
	 * deferred (see top-of-file comment).
	 *
	 * @param object $response Raw API response.
	 * @return true|\WP_Error
	 */
	private function validate_response_shape( object $response ) {
		if ( isset( $response->new_version ) ) {
			if ( ! is_string( $response->new_version ) || ! preg_match( self::VERSION_PATTERN, $response->new_version ) ) {
				return new \WP_Error(
					'fl_ds_updater_invalid_version',
					'Update API returned an invalid version string.'
				);
			}
		}

		if ( ! empty( $response->package ) ) {
			if ( ! is_string( $response->package ) ) {
				return new \WP_Error(
					'fl_ds_updater_invalid_package_type',
					'Update API returned a non-string package value.'
				);
			}

			if ( 0 !== strpos( $response->package, 'https://' ) ) {
				return new \WP_Error(
					'fl_ds_updater_insecure_package',
					'Update API package URL must use https.'
				);
			}

			$host = wp_parse_url( $response->package, PHP_URL_HOST );
			if ( empty( $host ) || ! in_array( strtolower( $host ), $this->get_package_host_allowlist(), true ) ) {
				return new \WP_Error(
					'fl_ds_updater_unallowed_package_host',
					'Update API package URL points to a host that is not on the allowlist.'
				);
			}
		}

		return true;
	}

	/**
	 * Get the host allowlist for the `package` URL.
	 *
	 * @return array
	 */
	private function get_package_host_allowlist(): array {
		/**
		 * Filter the allowlist of hosts permitted to serve update zips.
		 *
		 * Agencies that proxy the BB update server through a private mirror
		 * can append their host here. Adding an attacker-controlled host
		 * defeats the M-8 mitigation; treat this filter as a security
		 * boundary.
		 *
		 * @param array $hosts Lowercase hostnames.
		 */
		$hosts = apply_filters(
			'fl_ds_updater_package_host_allowlist',
			self::DEFAULT_PACKAGE_HOST_ALLOWLIST
		);

		if ( ! is_array( $hosts ) ) {
			return self::DEFAULT_PACKAGE_HOST_ALLOWLIST;
		}

		return array_map( 'strtolower', array_filter( $hosts, 'is_string' ) );
	}

	/**
	 * Log a malformed-update-response event.
	 *
	 * Surfaces the failure to operators without blocking the rest of the
	 * update transient (which already serves the no-update branch).
	 *
	 * @param \WP_Error $error Validation error.
	 * @return void
	 */
	private function log_invalid_response( \WP_Error $error ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic.
			error_log( sprintf(
				'[fl-design-system updater] Discarded malformed update response: %s (%s)',
				$error->get_error_message(),
				$error->get_error_code()
			) );
		}
	}
}
