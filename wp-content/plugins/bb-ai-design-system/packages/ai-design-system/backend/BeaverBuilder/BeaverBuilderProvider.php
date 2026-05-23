<?php

namespace FL\DesignSystem\BeaverBuilder;

use FL\DesignSystem\Contracts\AuthInterface;
use FL\DesignSystem\Contracts\SettingsStoreInterface;
use FL\DesignSystem\Plugin;

class BeaverBuilderProvider {

	private Plugin $plugin;
	private SettingsStoreInterface $settings;
	private AuthInterface $auth;
	private LayoutManager $layout;
	private ModuleTypeRegistrar $registrar;
	private string $module_namespace;

	public function __construct(
		Plugin $plugin,
		SettingsStoreInterface $settings,
		AuthInterface $auth,
		LayoutManager $layout,
		ModuleTypeRegistrar $registrar,
		string $module_namespace = 'ds',
	) {
		$this->plugin           = $plugin;
		$this->settings         = $settings;
		$this->auth             = $auth;
		$this->layout           = $layout;
		$this->registrar        = $registrar;
		$this->module_namespace = $module_namespace;
	}

	private const ADVANCED_TAB_ICON = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8.02393 11.6167C7.33219 11.6167 6.70133 11.4645 6.13135 11.1602C5.56136 10.8503 5.10482 10.4186 4.76172 9.86523C4.42415 9.31185 4.25537 8.67269 4.25537 7.94775C4.25537 7.22282 4.42415 6.58366 4.76172 6.03027C5.10482 5.47689 5.56136 5.04801 6.13135 4.74365C6.70133 4.43376 7.33219 4.27881 8.02393 4.27881H15.9761C16.6678 4.27881 17.2987 4.43376 17.8687 4.74365C18.4386 5.04801 18.8924 5.47689 19.23 6.03027C19.5731 6.58366 19.7446 7.22282 19.7446 7.94775C19.7446 8.67269 19.5731 9.31185 19.23 9.86523C18.8924 10.4186 18.4386 10.8503 17.8687 11.1602C17.2987 11.4645 16.6678 11.6167 15.9761 11.6167H8.02393ZM8.02393 10.6538H15.9761C16.4797 10.6538 16.9417 10.5404 17.3623 10.3135C17.7829 10.0811 18.1177 9.76286 18.3667 9.35889C18.6157 8.94938 18.7402 8.479 18.7402 7.94775C18.7402 7.4165 18.6157 6.94889 18.3667 6.54492C18.1177 6.13542 17.7829 5.81722 17.3623 5.59033C16.9417 5.35791 16.4797 5.2417 15.9761 5.2417H8.02393C7.52035 5.2417 7.05827 5.35791 6.6377 5.59033C6.21712 5.81722 5.87956 6.13542 5.625 6.54492C5.37598 6.94889 5.25146 7.4165 5.25146 7.94775C5.25146 8.479 5.37598 8.94938 5.625 9.35889C5.87956 9.76286 6.21712 10.0811 6.6377 10.3135C7.05827 10.5404 7.52035 10.6538 8.02393 10.6538ZM8.02393 10.0977C7.61995 10.0977 7.25195 10.0063 6.91992 9.82373C6.58789 9.64111 6.3195 9.38932 6.11475 9.06836C5.91553 8.74186 5.81592 8.36556 5.81592 7.93945C5.81592 7.51335 5.91553 7.13981 6.11475 6.81885C6.3195 6.49788 6.58789 6.24609 6.91992 6.06348C7.25195 5.88086 7.61995 5.78955 8.02393 5.78955H9.57617C9.98014 5.78955 10.3481 5.88086 10.6802 6.06348C11.0122 6.24609 11.2778 6.50065 11.4771 6.82715C11.6818 7.14811 11.7842 7.52165 11.7842 7.94775C11.7842 8.37386 11.6818 8.7474 11.4771 9.06836C11.2778 9.38932 11.0122 9.64111 10.6802 9.82373C10.3481 10.0063 9.98014 10.0977 9.57617 10.0977H8.02393ZM7.66699 19.6934C7.02507 19.6934 6.44678 19.555 5.93213 19.2783C5.41748 19.0016 5.00798 18.617 4.70361 18.1245C4.40479 17.6375 4.25537 17.0703 4.25537 16.4229C4.25537 15.7754 4.40479 15.2082 4.70361 14.7212C5.00798 14.2287 5.41748 13.8441 5.93213 13.5674C6.44678 13.2907 7.02507 13.1523 7.66699 13.1523H16.333C16.9749 13.1523 17.5505 13.2907 18.0596 13.5674C18.5742 13.8441 18.9837 14.2287 19.2881 14.7212C19.5924 15.2082 19.7446 15.7754 19.7446 16.4229C19.7446 17.0703 19.5924 17.6375 19.2881 18.1245C18.9837 18.617 18.5742 19.0016 18.0596 19.2783C17.5505 19.555 16.9749 19.6934 16.333 19.6934H7.66699ZM14.8057 18.689H16.4492C16.8753 18.689 17.2627 18.5921 17.6113 18.3984C17.9655 18.2103 18.245 17.9474 18.4497 17.6099C18.66 17.2668 18.7651 16.8711 18.7651 16.4229C18.7651 15.9746 18.66 15.5817 18.4497 15.2441C18.245 14.901 17.9655 14.6354 17.6113 14.4473C13.2949 14.6354 13.0155 14.901 12.8052 15.2441C12.5949 15.5817 12.4897 15.9718 12.4897 16.4146C12.4897 16.8683 12.5949 17.2668 12.8052 17.6099C13.0155 17.9474 13.2949 18.2103 13.6436 18.3984C13.9977 18.5921 14.3851 18.689 14.8057 18.689Z" fill="currentColor"></path></svg>';

	/**
	 * Replace the Advanced tab title with an icon for all modules.
	 *
	 * @param  array  $form Form config array.
	 * @param  string $id   Form or module slug.
	 * @return array Modified form config.
	 */
	public static function filter_advanced_tab_icon( array $form, string $id ): array {
		if ( 'module_advanced' === $id && isset( $form['title'] ) ) {
			$form['title'] = self::ADVANCED_TAB_ICON;
		}
		return $form;
	}

	public function boot() {
		if ( ! class_exists( 'FLBuilderModel' ) ) {
			return;
		}

		$rest = new BeaverBuilderRestController( $this->auth, $this->layout, $this->module_namespace );
		$rest->boot();

		$assets = new BeaverBuilderAssetProvider( $this->plugin, $this->settings, $this->module_namespace );
		$assets->boot();

		$template_save_handler = new TemplateSaveHandler();
		$template_save_handler->boot();

		$template_apply_handler = new TemplateApplyHandler();
		$template_apply_handler->boot();

		add_action( 'wp', [ $this->registrar, 'register_module_types' ], 1 );
		add_action( 'fl_builder_before_render_ajax_layout', [ $this->registrar, 'register_module_types' ] );
		add_filter( 'fl_builder_filter_settings_form', array( __CLASS__, 'filter_advanced_tab_icon' ), 10, 2 );
	}
}
