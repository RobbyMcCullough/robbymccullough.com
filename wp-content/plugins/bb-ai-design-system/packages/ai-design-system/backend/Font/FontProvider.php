<?php

namespace FL\DesignSystem\Font;

use FL\DesignSystem\Contracts\SettingsStoreInterface;
use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\Font\FontEntry;
use FL\DesignSystem\Font\GoogleFontsUrl;

/**
 * Loads Google Fonts on published pages via wp_head link tags.
 *
 * Reads font entries from the active design system post's _fl_ds_fonts
 * meta and outputs preconnect + stylesheet link tags.
 */
class FontProvider {

	private SettingsStoreInterface $settings;

	/**
	 * @param SettingsStoreInterface $settings Settings store.
	 */
	public function __construct( SettingsStoreInterface $settings ) {
		$this->settings = $settings;
	}

	public function boot() {
		$fonts = $this->get_font_entries();
		if ( ! empty( $fonts ) ) {
			add_action( 'wp_head', [ $this, 'output' ], 1 );
			add_action( 'enqueue_block_assets', [ $this, 'enqueue' ] );
		}
	}

	/**
	 * Enqueue Google Fonts stylesheet for the block editor iframe.
	 */
	public function enqueue() {
		$fonts = $this->get_font_entries();
		if ( empty( $fonts ) ) {
			return;
		}

		$url = GoogleFontsUrl::build( $fonts );
		if ( '' === $url ) {
			return;
		}

		wp_enqueue_style( 'bb-design-google-fonts', $url, [], null );
	}

	/**
	 * Output Google Fonts preconnect and stylesheet link tags.
	 */
	public function output() {
		$fonts = $this->get_font_entries();
		if ( empty( $fonts ) ) {
			return;
		}

		$tag = GoogleFontsUrl::build_link_tag( $fonts );
		if ( '' === $tag ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static trusted HTML assembled from sanitized family names.
		echo $tag . "\n";
	}

	/**
	 * Build a Google Fonts CSS2 URL from an array of font entries or names.
	 *
	 * Legacy shim — new code should call GoogleFontsUrl::build directly.
	 * Accepts the historical `string[]` input plus the new
	 * `{family, variants}` shape. The optional `$weights` argument is
	 * honored only when entries have no stored variants.
	 *
	 * @param  array $entries Font entries (string[] or {family, variants}[]).
	 * @param  int[] $weights Fallback numeric weights applied when entries lack variants.
	 * @return string         The Google Fonts URL, or empty string if no valid fonts.
	 */
	public static function build_google_fonts_url( array $entries, array $weights = [ 400, 500, 600, 700 ] ): string {
		$normalized = FontEntry::normalize( $entries );

		if ( [ 400, 500, 600, 700 ] !== $weights ) {
			// Legacy weight override — apply to any entry that lacks its own variants.
			$weight_str = 'wght@' . implode( ';', array_map( 'intval', $weights ) );
			foreach ( $normalized as &$entry ) {
				if ( '' === $entry['variants'] ) {
					$entry['variants'] = $weight_str;
				}
			}
			unset( $entry );
		}

		return GoogleFontsUrl::build( $normalized );
	}

	/**
	 * Sanitize a font family name.
	 *
	 * @param  string $name Raw font name.
	 * @return string Sanitized font name, or empty string if invalid.
	 */
	public static function sanitize_font_name( string $name ): string {
		return GoogleFontsUrl::sanitize_family( $name );
	}

	/**
	 * Read normalized font entries from the active design system post.
	 *
	 * Returns entries from the DS referenced by the current page.
	 *
	 * @return array<int, array{family: string, variants: string}>
	 */
	private function get_font_entries(): array {
		$ds_post = $this->resolve_design_system_post();

		if ( ! $ds_post ) {
			return [];
		}

		$fonts_raw = get_post_meta( $ds_post->ID, DesignSystemPostType::META_FONTS, true );

		if ( empty( $fonts_raw ) ) {
			return [];
		}

		return FontEntry::normalize( $fonts_raw );
	}

	/**
	 * Resolve the design system post for this request.
	 *
	 * Returns the DS referenced by the current page, or null if
	 * the page has no DS reference.
	 *
	 * @return \WP_Post|null
	 */
	private function resolve_design_system_post(): ?\WP_Post {
		return DesignSystemPostType::resolve_for_post( $this->get_current_post_id() );
	}

	/**
	 * Get the current post ID from the main query.
	 *
	 * @return int Post ID, or 0 if not available.
	 */
	private function get_current_post_id(): int {
		global $wp_the_query;

		if ( isset( $wp_the_query->post ) && $wp_the_query->post instanceof \WP_Post ) {
			return (int) $wp_the_query->post->ID;
		}

		return (int) get_queried_object_id();
	}
}
