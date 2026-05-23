<?php

namespace FL\DesignSystem;

use FL\DesignSystem\Adapters\WordPress\BeaverBuilderFormBlockSettingsResolver;
use FL\DesignSystem\Adapters\WordPress\BlockEditorFormBlockSettingsResolver;
use FL\DesignSystem\Adapters\WordPress\WordPressAuth;
use FL\DesignSystem\Adapters\WordPress\WordPressChatStore;
use FL\DesignSystem\Adapters\WordPress\WordPressEmailSender;
use FL\DesignSystem\Adapters\WordPress\WordPressFormActionHook;
use FL\DesignSystem\Adapters\WordPress\WordPressFormBlockSettingsResolver;
use FL\DesignSystem\Adapters\WordPress\WordPressHttpClient;
use FL\DesignSystem\Adapters\WordPress\WordPressSettingsStore;
use FL\DesignSystem\BlockEditor\BlockEditorProvider;
use FL\DesignSystem\BlockEditor\BlockEditorSettingsFilter;
use FL\DesignSystem\BlockEditor\CustomBlockRenderer;
use FL\DesignSystem\BlockEditor\KsesFallback;
use FL\DesignSystem\BlockEditor\PatternSaveHandler;
use FL\DesignSystem\BeaverBuilder\BlockModuleRenderer;
use FL\DesignSystem\BeaverBuilder\BeaverBuilderProvider;
use FL\DesignSystem\BeaverBuilder\LayoutManager;
use FL\DesignSystem\BeaverBuilder\ModuleTypeRegistrar;
use FL\DesignSystem\BeaverBuilder\SaveGuard;
use FL\DesignSystem\Middleware\ErrorFormatter;
use FL\DesignSystem\Form\CustomHandler;
use FL\DesignSystem\Form\EmailHandler;
use FL\DesignSystem\Form\FormActionRegistry;
use FL\DesignSystem\Form\FormSubmissionProvider;
use FL\DesignSystem\Form\RedirectHandler;
use FL\DesignSystem\Form\SpamGuard;
use FL\DesignSystem\Form\WebhookHandler;
use FL\DesignSystem\Chat\ChatHistoryProvider;
use FL\DesignSystem\Generation\GenerationJobProvider;
use FL\DesignSystem\DesignSystem\DesignSystemAssetProvider;
use FL\DesignSystem\DesignSystem\LayoutCssProvider;
use FL\DesignSystem\Font\FontProvider;
use FL\DesignSystem\Page\PageOverrideProvider;
use FL\DesignSystem\Generation\DebugProvider;
use FL\DesignSystem\Mcp\McpProvider;
use FL\DesignSystem\Media\MediaProvider;
use FL\DesignSystem\Settings\SettingsProvider;
use FL\DesignSystem\Usage\UsageProvider;
use FL\DesignSystem\Services\AdapterResolver;
use FL\DesignSystem\Settings\SettingsSanitizer;
use FL\DesignSystem\Usage\TokenQuotaService;
use FL\DesignSystem\DesignSystem\DesignSystemProvider;
use FL\DesignSystem\DesignSystem\DesignSystemUsageQuery;
use FL\DesignSystem\Page\PageProvider;
use FL\DesignSystem\DesignKit\BuiltInKitRegistry;
use FL\DesignSystem\DesignKit\DesignKitProvider;
use FL\DesignSystem\DataSources\DataSourceProvider;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;

final class Plugin {

	public $filepath = '';

	public $metadata = [];

	public function __construct( $filepath ) {
		$this->filepath = $filepath;
		$this->metadata = get_plugin_data( $this->filepath, false, false );

		add_action( 'init', [ $this, 'register_providers' ] );
	}

	public function register_providers() {
		$settings   = new WordPressSettingsStore();
		$auth       = new WordPressAuth();
		$chat_store = new WordPressChatStore();

		$ds_post_type = new DesignSystemPostType();
		$ds_post_type->register();

		$module_ns = self::resolve_module_namespace( 'none' );

		$error_formatter = new ErrorFormatter();
		$error_formatter->boot();

		$form_action_registry = $this->build_form_action_registry();
		$spam_guard           = new SpamGuard( wp_salt( 'auth' ) );
		$form_block_resolver  = new WordPressFormBlockSettingsResolver(
			new BeaverBuilderFormBlockSettingsResolver(),
			new BlockEditorFormBlockSettingsResolver()
		);
		$custom_renderer      = new CustomBlockRenderer( $spam_guard );

		$layout_manager   = new LayoutManager();
		$adapter_resolver = new AdapterResolver( $layout_manager, $module_ns );
		$renderer         = new BlockModuleRenderer( $spam_guard );
		$registrar        = new ModuleTypeRegistrar( $renderer, $module_ns );

		// Shared by SaveGuard and KsesFallback — both close the same
		// stored-XSS gap across two different storage shapes.
		$settings_sanitizer = new SettingsSanitizer();

		$kses_fallback = new KsesFallback( $settings_sanitizer );
		$kses_fallback->boot();

		$save_guard = new SaveGuard( $settings_sanitizer );
		$save_guard->boot();

		DataSourceProvider::boot();

		$providers = [
			new GenerationJobProvider( $settings, $auth ),
			new ChatHistoryProvider( $chat_store, $auth ),
			new FormSubmissionProvider( $form_action_registry, $spam_guard, $form_block_resolver ),
			new MediaProvider( $settings, $auth ),
			new SettingsProvider( $settings, $auth ),
			new UsageProvider( $auth ),
			new PageOverrideProvider( $auth ),
			new DesignSystemProvider( $auth, new DesignSystemUsageQuery() ),
			new DesignSystemAssetProvider(),
			new LayoutCssProvider( $this ),
			new FontProvider( $settings ),
			new BlockEditorProvider( $this, $settings, $custom_renderer ),
			new BlockEditorSettingsFilter(),
			new PatternSaveHandler(),
			new PageProvider( $auth, $adapter_resolver ),
			new McpProvider( $adapter_resolver, $module_ns ),
			new BeaverBuilderProvider( $this, $settings, $auth, $layout_manager, $registrar, $module_ns ),
		];

		foreach ( $providers as $provider ) {
			$provider->boot();
		}

		( new DebugProvider() )->boot();
		$built_in_kits = new BuiltInKitRegistry( __DIR__ . '/../data/design-kits' );
		( new DesignKitProvider( $auth, $built_in_kits, $adapter_resolver ) )->boot();
		( new TokenQuotaService() )->boot();
		( new Admin\AdminProvider( $this ) )->boot();
		( new Admin\AdminAssetProvider( $this ) )->boot();
		( new Admin\AdminRestController( $auth ) )->boot();

		/**
		 * Fires after core providers are booted.
		 *
		 * Other packages hook here to register their own providers
		 * using the shared Plugin instance and settings store.
		 *
		 * @param Plugin                 $plugin     The plugin instance.
		 * @param SettingsStoreInterface $settings   The settings store.
		 * @param ChatStoreInterface     $chat_store The chat store.
		 */
		do_action( 'fl_design_system_booted', $this, $settings, $chat_store );
	}

	/**
	 * Build the FormActionRegistry seeded with built-in handlers.
	 *
	 * Third-party packages can add or override handlers via the
	 * `fl_ds_form_action_registry` filter (e.g. Mailchimp, HubSpot, Slack).
	 *
	 * @return FormActionRegistry
	 */
	private function build_form_action_registry(): FormActionRegistry {
		$registry = new FormActionRegistry();

		$registry->register( 'email', new EmailHandler( new WordPressEmailSender() ) );
		$registry->register( 'webhook', new WebhookHandler( new WordPressHttpClient() ) );
		$registry->register( 'custom', new CustomHandler( new WordPressFormActionHook() ) );
		$registry->register( 'redirect', new RedirectHandler() );

		/**
		 * Filter the form submission action registry.
		 *
		 * @param FormActionRegistry $registry The registry with built-in handlers.
		 */
		$filtered = apply_filters( 'fl_ds_form_action_registry', $registry );

		return $filtered instanceof FormActionRegistry ? $filtered : $registry;
	}

	/**
	 * Resolve the BB module namespace prefix for a design system.
	 *
	 * @param  string $ds_slug Design system slug (e.g. 'none', 'tailwind').
	 * @return string Module namespace prefix (e.g. 'ds', 'tw').
	 */
	public static function resolve_module_namespace( string $ds_slug ): string {
		$map = [
			'tailwind' => 'tw',
		];
		return $map[ $ds_slug ] ?? 'ds';
	}

	public function __get( $name ) {
		switch ( $name ) {
			case 'slug':
				return basename( $this->filepath, '.php' );
			case 'dir':
				return trailingslashit( wp_normalize_path( dirname( $this->filepath ) ) );
			case 'url':
				return trailingslashit( plugins_url( '', $this->filepath ) );
			case 'version':
				return $this->metadata['Version'];
		}
		return;
	}
}
