<?php

namespace FL\DesignSystem\Updater;

use FL\DesignSystem\Contracts\SettingsStoreInterface;

/**
 * Manages licensing and update registration for the Design System.
 *
 * When bb-plugin's FLUpdater is available, defers to it for update checks.
 * Otherwise, registers a standalone updater that talks directly to the
 * BB update server.
 *
 * @package FL\DesignSystem\Updater
 */
class LicenseProvider {

	/** @var SettingsStoreInterface */
	private SettingsStoreInterface $settings;

	/** @var array Product config for update registration. */
	private array $product;

	/** @var bool Whether FLUpdater is handling updates. */
	private bool $bb_updater_active = false;

	/**
	 * @param SettingsStoreInterface $settings Settings store instance.
	 * @param array                  $product  Product config (name, version, slug, type).
	 */
	public function __construct( SettingsStoreInterface $settings, array $product ) {
		$this->settings = $settings;
		$this->product  = $product;
	}

	/**
	 * Register the appropriate updater based on environment.
	 */
	public function boot(): void {
		if ( class_exists( 'FLUpdater' ) ) {
			$this->bb_updater_active = true;
			\FLUpdater::add_product( $this->product );
		} else {
			$updater = new Updater( $this->product, [ $this, 'get_license_key' ] );
			$updater->boot();
		}
	}

	/**
	 * Whether FLUpdater is handling updates for this product.
	 *
	 * @return bool
	 */
	public function is_bb_updater_active(): bool {
		return $this->bb_updater_active;
	}

	/**
	 * Whether a valid license key exists from any source.
	 *
	 * @return bool
	 */
	public function is_licensed(): bool {
		return ! empty( $this->get_license_key() );
	}

	/**
	 * Get the active license key.
	 *
	 * When BB is active, reads from BB's shared license option.
	 * Otherwise, reads from the DS settings store.
	 *
	 * @return ?string
	 */
	public function get_license_key(): ?string {
		if ( $this->bb_updater_active ) {
			$key = get_site_option( 'fl_themes_subscription_email', '' );
			return $key ?: null;
		}

		$key = $this->settings->get( 'license.key' );
		return $key ?: null;
	}
}
