<?php

namespace FL\DesignSystem\Mcp;

use FL\DesignSystem\Services\AdapterResolver;
use FL\DesignSystem\Services\BlockService;
use FL\DesignSystem\Mcp\Abilities\Blocks\GetPageBlocks;
use FL\DesignSystem\Mcp\Abilities\Blocks\UpdatePageBlocks;
use FL\DesignSystem\Mcp\Abilities\DesignSystems\AnalyzePageDesign;
use FL\DesignSystem\Mcp\Abilities\DesignSystems\CreateDesignSystem;
use FL\DesignSystem\Mcp\Abilities\DesignSystems\GenerateStyleGuide;
use FL\DesignSystem\Mcp\Abilities\DesignSystems\GetDesignSystem;
use FL\DesignSystem\Mcp\Abilities\DesignSystems\ListDesignSystems;
use FL\DesignSystem\Mcp\Abilities\DesignSystems\UpdateDesignSystemAssets;
use FL\DesignSystem\Mcp\Abilities\DesignSystems\UpdateDesignSystemGuidance;
use FL\DesignSystem\Mcp\Abilities\DesignSystems\UpdateDesignSystemTokens;
use FL\DesignSystem\Mcp\Abilities\Discovery\ListPages;
use FL\DesignSystem\Mcp\Abilities\Discovery\ListPostTypes;
use FL\DesignSystem\Mcp\Abilities\Pages\DiscardStagedPage;
use FL\DesignSystem\Mcp\Abilities\Pages\GeneratePage;
use FL\DesignSystem\Mcp\Abilities\Pages\GetFormatSpec;
use FL\DesignSystem\Mcp\Abilities\Pages\GetPageDebug;
use FL\DesignSystem\Mcp\Abilities\Pages\GetPageHtml;
use FL\DesignSystem\Mcp\Abilities\Pages\GetPageOutline;
use FL\DesignSystem\Mcp\Abilities\Pages\PublishStagedPage;
use FL\DesignSystem\Mcp\Abilities\Pages\UpdatePageAssets;
use FL\DesignSystem\Mcp\Abilities\Pages\UpdatePageHtml;
use FL\DesignSystem\Mcp\Support\BlockOperations;
use FL\DesignSystem\Mcp\Support\FontOverridesService;
use FL\DesignSystem\Mcp\Support\FormatSpecLoader;
use FL\DesignSystem\Mcp\Support\HashVerifier;
use FL\DesignSystem\Mcp\Support\PageGenerator;
use FL\DesignSystem\Mcp\Support\PageResolver;
use FL\DesignSystem\Mcp\Support\PostTypeService;
use FL\DesignSystem\Mcp\Support\StagingService;

/**
 * Boots the MCP integration: wires shared services, builds every ability,
 * and bridges admin metadata into the WordPress admin UI.
 *
 * Each ability lives under {@see Abilities/} and registers itself through
 * {@see AbilityRegistry}. Cross-cutting helpers (hashing, staging, page
 * resolution, etc.) live under {@see Support/} and are constructed once
 * here so every ability shares a single instance. Skips Abilities-API
 * registration when the API isn't loaded.
 */
class McpProvider {

	private const SUBCATEGORY_LABELS = [
		'discovery'      => 'Discovery',
		'design-systems' => 'Design Systems',
		'pages'          => 'Pages',
		'blocks'         => 'Blocks',
	];

	/**
	 * Public bridge for the admin tools table.
	 *
	 * Read by {@see Admin\AdminAssetProvider} via {@see get_admin_tool_list()}.
	 * Written by {@see AbilityRegistry::register()} through {@see push_admin_tool()}
	 * as each ability registers. The static + bridge shape keeps the admin
	 * surface stable while the ability classes own their own metadata.
	 */
	private static array $admin_tools = [];

	private AbilityRegistry $registry;

	public function __construct( AdapterResolver $adapter_resolver, string $module_namespace = 'ds' ) {
		$block_service          = new BlockService( $module_namespace );
		$hash_verifier          = new HashVerifier();
		$staging_service        = new StagingService( $adapter_resolver, $hash_verifier );
		$format_spec_loader     = new FormatSpecLoader();
		$post_type_service      = new PostTypeService();
		$page_resolver          = new PageResolver( $adapter_resolver );
		$page_generator         = new PageGenerator( $page_resolver, $post_type_service, $hash_verifier );
		$font_overrides_service = new FontOverridesService();
		$block_operations       = new BlockOperations( $block_service );

		$this->registry = new AbilityRegistry();

		// Order within each subcategory matches the legacy registration sequence
		// so {@see get_admin_tool_list()} output stays byte-identical against the
		// pre-refactor snapshot.

		// design-systems group:
		$this->registry->register( new ListDesignSystems() );
		$this->registry->register( new GetDesignSystem( $format_spec_loader, $hash_verifier ) );
		$this->registry->register( new CreateDesignSystem() );
		$this->registry->register( new GenerateStyleGuide( $page_generator, $post_type_service, $format_spec_loader ) );
		$this->registry->register( new AnalyzePageDesign( $page_resolver, $format_spec_loader ) );
		$this->registry->register( new UpdateDesignSystemGuidance( $hash_verifier ) );
		$this->registry->register( new UpdateDesignSystemTokens( $hash_verifier, $font_overrides_service ) );
		$this->registry->register( new UpdateDesignSystemAssets( $hash_verifier, $font_overrides_service ) );

		// pages group:
		$this->registry->register( new GetFormatSpec( $format_spec_loader ) );
		$this->registry->register( new GeneratePage( $page_generator, $post_type_service ) );
		$this->registry->register( new GetPageOutline( $page_resolver, $hash_verifier ) );
		$this->registry->register( new GetPageHtml( $page_resolver, $hash_verifier, $format_spec_loader ) );
		$this->registry->register( new UpdatePageHtml( $page_resolver, $hash_verifier, $staging_service ) );
		$this->registry->register( new UpdatePageAssets( $page_resolver, $hash_verifier, $staging_service ) );
		$this->registry->register( new PublishStagedPage( $page_resolver, $hash_verifier, $staging_service ) );
		$this->registry->register( new DiscardStagedPage( $page_resolver, $staging_service ) );

		// Gate get-page-debug on the same debug flags its execute() checks.
		// Registration is the discovery gate; the runtime check inside the
		// ability remains as defense in depth.
		$debug_on = ( defined( 'FL_DS_DEBUG' ) && FL_DS_DEBUG )
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		if ( $debug_on ) {
			$this->registry->register( new GetPageDebug() );
		}

		// discovery group:
		$this->registry->register( new ListPostTypes( $post_type_service ) );
		$this->registry->register( new ListPages( $page_resolver ) );

		// blocks group:
		$this->registry->register( new GetPageBlocks( $page_resolver, $hash_verifier, $block_service ) );
		$this->registry->register( new UpdatePageBlocks( $page_resolver, $hash_verifier, $staging_service, $block_operations ) );
	}

	/**
	 * Bridge for {@see AbilityRegistry} so per-ability classes can populate
	 * the admin tools table while the static reader on this class stays the
	 * canonical surface for {@see Admin\AdminAssetProvider}.
	 *
	 * @internal
	 */
	public static function push_admin_tool( array $entry ): void {
		self::$admin_tools[] = $entry;
	}

	public function boot(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	public function register_category(): void {
		wp_register_ability_category( 'beaver-builder-ai', [
			'label'       => 'Beaver Builder AI',
			'description' => 'Create and edit WordPress pages using Beaver Builder AI. Design with AI-generated HTML, design system tokens, typography, and creative direction. Works with both the WordPress block editor and Beaver Builder page builder.',
		] );
	}

	public function register_abilities(): void {
		$this->registry->register_with_abilities_api();
	}

	/**
	 * Returns grouped admin-tool data for the admin UI, ordered by subcategory.
	 *
	 * @return array<int, array{label: string, tools: array}>
	 */
	public static function get_admin_tool_list(): array {
		$groups = [];
		foreach ( self::SUBCATEGORY_LABELS as $slug => $label ) {
			$tools = array_values( array_filter(
				self::$admin_tools,
				fn( $tool ) => $tool['subcategory'] === $slug
			) );
			if ( $tools ) {
				$groups[] = [
					'label' => $label,
					'tools' => $tools,
				];
			}
		}
		return $groups;
	}
}
