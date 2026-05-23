<?php

namespace FL\DesignSystem\DesignKit;

/**
 * Composes preview HTML for each page in a design kit.
 *
 * Pages in a kit are complete standalone HTML documents that <link> to
 * design-system/styles.css. For previews, we inline the DS CSS and
 * optionally inject a shared header/footer so thumbnails and lightbox
 * frames render the full look.
 *
 * No WordPress side effects. Pure string composition on disk contents.
 */
class KitPreviewer {

	/**
	 * Build preview response for a kit directory.
	 *
	 * @param string $dir Absolute path to the kit root directory.
	 * @return array {
	 *   pages: Array<int, array{ slug: string, title: string, html: string }>,
	 *   globals: array{ header: bool, footer: bool },
	 * }
	 */
	public static function preview( string $dir ): array {
		$dir = \trailingslashit( $dir );

		$styles_css = self::read_file( $dir . 'design-system/styles.css' );
		$script_js  = self::read_file( $dir . 'design-system/script.js' );
		$header_raw = self::read_file( $dir . 'globals/header.html' );
		$footer_raw = self::read_file( $dir . 'globals/footer.html' );

		$header = '' !== $header_raw ? self::parse_global( $header_raw ) : null;
		$footer = '' !== $footer_raw ? self::parse_global( $footer_raw ) : null;

		$page_files = self::scan_page_files( $dir );

		$pages = [];
		foreach ( $page_files as $path ) {
			$slug  = pathinfo( $path, PATHINFO_FILENAME );
			$html  = self::read_file( $path );
			$title = self::extract_title( $html );
			if ( '' === $title ) {
				$title = ucfirst( str_replace( [ '-', '_' ], ' ', $slug ) );
			}

			$pages[] = [
				'slug'  => $slug,
				'title' => $title,
				'html'  => self::compose( $html, $styles_css, $script_js, $header, $footer ),
			];
		}

		return [
			'pages'   => $pages,
			'globals' => [
				'header' => null !== $header,
				'footer' => null !== $footer,
			],
		];
	}

	/**
	 * Read a file's contents, returning an empty string if missing.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function read_file( string $path ): string {
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$contents = file_get_contents( $path );
		return false === $contents ? '' : $contents;
	}

	/**
	 * Scan the kit directory for page HTML files.
	 *
	 * Mirrors KitParser::scan_pages — pages/ directory first, root-level
	 * .html files (minus header.html/footer.html) as fallback.
	 *
	 * @param string $dir Kit root directory (with trailing slash).
	 * @return string[] Absolute file paths.
	 */
	private static function scan_page_files( string $dir ): array {
		$pages_dir = $dir . 'pages';

		if ( is_dir( $pages_dir ) ) {
			$files = glob( $pages_dir . '/*.html' );
		} else {
			$root_files = glob( $dir . '*.html' );
			$files      = array_filter(
				is_array( $root_files ) ? $root_files : [],
				static function ( $f ) {
					$basename = basename( $f );
					return ! in_array( $basename, [ 'header.html', 'footer.html' ], true );
				}
			);
		}

		return PageOrder::sort( is_array( $files ) ? $files : [] );
	}

	/**
	 * Compose a single preview HTML document from a page plus shared assets.
	 *
	 * Inlines the DS CSS and JS (replacing the <link>/<script src> references
	 * used during authoring), hoists each global's inline <style>/<script> from
	 * its head into the composed <head>, injects the header body content after
	 * the page's <body> open, injects the footer body content before </body>,
	 * and appends a small script that blocks outbound link clicks so the preview
	 * stays contained.
	 *
	 * External resources in globals files (DS styles.css, DS script.js, font
	 * links, charset/viewport meta) are intentionally dropped: the page's own
	 * head already carries them, so pulling them through would just duplicate
	 * or produce broken relative paths inside the srcDoc iframe.
	 *
	 * @param string     $page_html  Raw page HTML from disk.
	 * @param string     $styles_css DS styles.css contents (or empty).
	 * @param string     $script_js  DS script.js contents (or empty).
	 * @param array|null $header     Parsed header globals (or null).
	 * @param array|null $footer     Parsed footer globals (or null).
	 * @return string Complete preview HTML document.
	 */
	private static function compose( string $page_html, string $styles_css, string $script_js, ?array $header, ?array $footer ): string {
		$html = $page_html;

		if ( '' !== $styles_css ) {
			$html = KitImporter::inject_css_into_html( $html, $styles_css );
		}

		$head_extras = self::build_head_extras( $header, $footer );
		if ( '' !== $head_extras ) {
			$html = self::inject_before_head_close( $html, $head_extras );
		}

		if ( $header && '' !== $header['body'] ) {
			$html = self::inject_after_body_open( $html, $header['body'] );
		}

		if ( $footer && '' !== $footer['body'] ) {
			$html = self::inject_before_body_close( $html, $footer['body'] );
		}

		$html = self::inline_design_system_script( $html, $script_js );

		return self::inject_before_body_close( $html, self::click_blocker_script() );
	}

	/**
	 * Extract the reusable fragments from a globals file (header or footer).
	 *
	 * Globals files are authored as complete standalone HTML documents so they
	 * can be previewed individually during design. For composition we only
	 * want the pieces unique to the global:
	 *
	 * - Inline <style> blocks from <head> (component CSS)
	 * - Inline <script> blocks from <head> with no src attribute (component JS)
	 * - Inner <body> content (the <header>/<footer> element itself, plus any
	 *   inline scripts the author placed inside body)
	 *
	 * Everything else (DS stylesheet/script links, font links, meta) is
	 * provided by the page or is non-functional inside a srcDoc iframe.
	 *
	 * @param string $html Raw file contents.
	 * @return array{ head_css: string, head_js: string, body: string }
	 */
	private static function parse_global( string $html ): array {
		$head_css = '';
		$head_js  = '';

		if ( preg_match( '/<head\b[^>]*>(.*?)<\/head>/is', $html, $head_match ) ) {
			$head = $head_match[1];

			if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $head, $style_matches ) ) {
				$head_css = implode( "\n", $style_matches[1] );
			}

			if ( preg_match_all( '/<script\b(?![^>]*\bsrc=)[^>]*>(.*?)<\/script>/is', $head, $script_matches ) ) {
				$head_js = implode( "\n", $script_matches[1] );
			}
		}

		if ( preg_match( '/<body\b[^>]*>(.*?)<\/body>/is', $html, $body_match ) ) {
			$body = trim( $body_match[1] );
		} else {
			// File is already a fragment (no <body> wrapper).
			$body = trim( $html );
		}

		return [
			'head_css' => $head_css,
			'head_js'  => $head_js,
			'body'     => $body,
		];
	}

	/**
	 * Strip the page's <script src="../design-system/script.js"> reference
	 * and replace it with an inline <script> containing the kit's DS JS.
	 *
	 * Relative script paths don't resolve inside a srcDoc iframe, so without
	 * inlining the DS JS never runs. Mirrors KitImporter::inject_css_into_html
	 * for CSS.
	 *
	 * The inlined tag is dropped in where the author's <script src> originally
	 * lived. script.js is authored as part of the kit and may define helpers
	 * (e.g. onReady) that the kit's own inline <script> tags — on the page and
	 * in hoisted globals — call during parse. Those helpers must exist before
	 * the inline scripts execute, otherwise the calls throw ReferenceError and
	 * break everything downstream. Kits place the <script src> reference near
	 * the top of <head>, so replacing in place preserves author-intended order.
	 *
	 * @param string $html      Page HTML (DS CSS already inlined).
	 * @param string $script_js DS script.js contents (or empty).
	 * @return string
	 */
	private static function inline_design_system_script( string $html, string $script_js ): string {
		$src_pattern = '/<script[^>]*design-system\/script\.js[^>]*>\s*<\/script>\s*/i';

		if ( '' === $script_js ) {
			// Drop every relative reference — none of them will resolve in srcDoc.
			return preg_replace( $src_pattern, '', $html );
		}

		$script_tag = '<script>' . $script_js . '</script>';

		// Replace the first <script src> in place, so the inlined contents run
		// in the same head position the author chose.
		$replaced = preg_replace( $src_pattern, $script_tag, $html, 1, $count );
		if ( $count > 0 ) {
			// Drop any additional references (e.g. copied in from globals).
			return preg_replace( $src_pattern, '', $replaced );
		}

		// No <script src> to replace — insert at the start of <head> so the
		// helpers it defines are available before any inline <script> in <head>.
		$head_open = stripos( $html, '<head' );
		if ( false !== $head_open ) {
			$head_end = strpos( $html, '>', $head_open );
			if ( false !== $head_end ) {
				$insert_at = $head_end + 1;
				return substr( $html, 0, $insert_at ) . "\n" . $script_tag . substr( $html, $insert_at );
			}
		}

		// No <head> either — prepend so the script still runs first.
		return $script_tag . "\n" . $html;
	}

	/**
	 * Build the combined <head> additions from parsed globals.
	 *
	 * @param array|null $header Parsed header globals.
	 * @param array|null $footer Parsed footer globals.
	 * @return string
	 */
	private static function build_head_extras( ?array $header, ?array $footer ): string {
		$parts = [];

		foreach ( [ $header, $footer ] as $global ) {
			if ( $global && '' !== $global['head_css'] ) {
				$parts[] = '<style>' . $global['head_css'] . '</style>';
			}
		}

		foreach ( [ $header, $footer ] as $global ) {
			if ( $global && '' !== $global['head_js'] ) {
				$parts[] = '<script>' . $global['head_js'] . '</script>';
			}
		}

		return '' === implode( '', $parts ) ? '' : implode( "\n", $parts ) . "\n";
	}

	/**
	 * Inject a fragment immediately before the closing </head> tag.
	 *
	 * @param string $html     Document HTML.
	 * @param string $fragment Fragment to insert.
	 * @return string
	 */
	private static function inject_before_head_close( string $html, string $fragment ): string {
		$pos = stripos( $html, '</head>' );
		if ( false !== $pos ) {
			return substr( $html, 0, $pos ) . $fragment . substr( $html, $pos );
		}
		return $fragment . $html;
	}

	/**
	 * Inject a fragment directly after the opening <body> tag.
	 *
	 * @param string $html     Document HTML.
	 * @param string $fragment Fragment to insert.
	 * @return string
	 */
	private static function inject_after_body_open( string $html, string $fragment ): string {
		$offset = self::body_search_offset( $html );
		if ( preg_match( '/<body\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$pos = $match[0][1] + strlen( $match[0][0] );
			return substr( $html, 0, $pos ) . "\n" . $fragment . substr( $html, $pos );
		}
		return $fragment . "\n" . $html;
	}

	/**
	 * Inject a fragment immediately before the closing </body> tag.
	 *
	 * @param string $html     Document HTML.
	 * @param string $fragment Fragment to insert.
	 * @return string
	 */
	private static function inject_before_body_close( string $html, string $fragment ): string {
		$offset = self::body_search_offset( $html );
		$pos    = stripos( $html, '</body>', $offset );
		if ( false !== $pos ) {
			return substr( $html, 0, $pos ) . $fragment . "\n" . substr( $html, $pos );
		}
		return $html . "\n" . $fragment;
	}

	/**
	 * Position from which to begin scanning for <body>/</body> tags.
	 *
	 * Starts after </head> so that literal <body> strings inside inlined
	 * CSS comments or other head content can't produce false matches. Falls
	 * back to 0 when no </head> is present.
	 *
	 * @param string $html Document HTML.
	 * @return int
	 */
	private static function body_search_offset( string $html ): int {
		$head_close = stripos( $html, '</head>' );
		return false === $head_close ? 0 : $head_close;
	}

	/**
	 * Extract a <title> value for page labeling.
	 *
	 * @param string $html Document HTML.
	 * @return string
	 */
	private static function extract_title( string $html ): string {
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $matches ) ) {
			return trim( $matches[1] );
		}
		return '';
	}

	/**
	 * Script that blocks outbound link clicks inside the preview iframe.
	 *
	 * @return string
	 */
	private static function click_blocker_script(): string {
		return "<script>document.addEventListener('click',function(e){var a=e.target.closest('a');if(a){e.preventDefault();}});</script>";
	}
}
