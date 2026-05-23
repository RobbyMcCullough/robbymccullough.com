<?php

namespace FL\DesignSystem\Mcp\Support;

/**
 * Assemble the MCP format spec from shared markdown sources.
 *
 * The spec is composed from `data/spec/` so MCP consumers see the
 * same rules as the chat-based generation specs. Repeated calls for
 * the same shape are memoized via {@see $cache} — the registry must
 * pass a single shared instance to every ability that loads specs
 * (today: GetDesignSystem, GetFormatSpec, GetPageHtml).
 */
class FormatSpecLoader {

	private array $cache = [];

	/**
	 * Load the format spec, optionally narrowed to a mode and section list.
	 *
	 * @param  string     $mode    'creation' for new pages, 'editing' for existing.
	 * @param  array      $context Optional. Supports 'has_design_system' (bool).
	 * @param  array|null $include Optional list of optional sections to include
	 *                             (annotations, forms, javascript, google-fonts,
	 *                             reset, example). Null returns all.
	 * @return string Assembled format spec content.
	 */
	public function load_format_spec( string $mode = 'creation', array $context = [], ?array $include = null ): string {
		$spec_dir = FL_DESIGN_SYSTEM_DIR . 'packages/ai-design-system/data/spec/';
		$has_ds   = ! empty( $context['has_design_system'] );

		$cache_key = $mode
			. ':' . ( $has_ds ? '1' : '0' )
			. ':' . md5( null === $include ? '*' : implode( ',', $include ) );

		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		$want = static function ( string $key ) use ( $include ): bool {
			return null === $include || in_array( $key, $include, true );
		};

		$parts = [];

		$parts[] = $this->load_spec_file( $spec_dir . 'consumer/format-spec-intro.md' );

		$parts[] = $this->load_spec_file( $spec_dir . 'core/body-elements.md' );
		$parts[] = $this->load_spec_file( $spec_dir . 'core/inner-structure.md' );
		$parts[] = $this->load_spec_file( $spec_dir . 'core/stylesheet-format.md' );
		if ( $want( 'reset' ) ) {
			$parts[] = $this->load_spec_file( $spec_dir . 'core/reset.md' );
		}
		$parts[] = $this->load_spec_file( $spec_dir . 'core/design-tokens.md' );
		if ( $want( 'javascript' ) ) {
			$parts[] = $this->load_spec_file( $spec_dir . 'core/javascript.md' );
		}
		if ( $want( 'annotations' ) ) {
			$parts[] = $this->load_spec_file( $spec_dir . 'core/annotations.md' );
		}
		$parts[] = $this->load_spec_file( $spec_dir . 'core/layout-patterns.md' );
		if ( $want( 'forms' ) ) {
			$parts[] = $this->load_spec_file( $spec_dir . 'core/forms.md' );
		}
		if ( $want( 'google-fonts' ) ) {
			$parts[] = $this->load_spec_file( $spec_dir . 'core/google-fonts.md' );
		}
		$parts[] = $this->load_spec_file( $spec_dir . 'core/quality-standards.md' );

		if ( 'editing' === $mode ) {
			$parts[] = $this->load_spec_file( $spec_dir . 'core/mixed-content.md' );
		}

		if ( $has_ds ) {
			$parts[] = $this->load_spec_file( $spec_dir . 'context/existing-ds.md' );
		} else {
			$parts[] = $this->load_spec_file( $spec_dir . 'context/new-ds.md' );
		}

		$parts[] = $this->load_spec_file( $spec_dir . 'context/creative-gathering.md' );

		if ( 'editing' === $mode ) {
			$parts[] = $this->load_spec_file( $spec_dir . 'context/editing-existing-page.md' );
		}

		if ( $want( 'example' ) ) {
			$parts[] = $this->load_spec_file( $spec_dir . 'consumer/format-spec-example.md' );
		}

		$result = implode( "\n\n", array_filter( $parts ) );

		$this->cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Load a single spec section file.
	 *
	 * @param  string $path Absolute path to the spec file.
	 * @return string File contents, or empty string on failure.
	 */
	public function load_spec_file( string $path ): string {
		if ( ! file_exists( $path ) ) {
			return '';
		}

		$content = file_get_contents( $path );
		return false !== $content ? trim( $content ) : '';
	}
}
