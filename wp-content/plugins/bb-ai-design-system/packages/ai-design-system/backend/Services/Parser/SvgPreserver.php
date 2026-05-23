<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * SVG Preserver
 *
 * Extracts SVGs from HTML before DOMDocument processing and restores them
 * after, preventing DOMDocument's HTML4 parser from mangling SVG-specific
 * attributes and structure.
 */
class SvgPreserver {

	private const PLACEHOLDER_TPL    = '<!--__BB_SVG_%d__-->';
	private const PLACEHOLDER_RE     = '/<!--__BB_SVG_(\d+)__-->/';
	private const SVG_RE             = '/<svg\b[\s\S]*?<\/svg>/i';
	private const SVG_OPEN_TAG_RE    = '/<svg\b[^>]*>/i';
	private const ANNOTATED_OPEN_RE  = '/\bdata-field(?:-type|-href)?\s*=/i';

	/** @var string[] */
	private array $extracted = [];

	/**
	 * Replace `<svg>...</svg>` blocks with comment placeholders.
	 *
	 * When $skip_annotated is true, SVGs whose opening tag carries a
	 * `data-field`, `data-field-type`, or `data-field-href` attribute are
	 * left in place so AnnotationParser can process them as fields.
	 *
	 * @param string $html           HTML that may contain SVG elements.
	 * @param bool   $skip_annotated Skip SVGs that carry annotation attributes.
	 * @return string HTML with non-annotated SVGs replaced by comment placeholders.
	 */
	public function extract( string $html, bool $skip_annotated = false ): string {
		$this->extracted = [];

		return preg_replace_callback( self::SVG_RE, function ( $match ) use ( $skip_annotated ) {
			if ( $skip_annotated && self::isAnnotatedSvg( $match[0] ) ) {
				return $match[0];
			}

			$index             = count( $this->extracted );
			$this->extracted[] = $match[0];
			return sprintf( self::PLACEHOLDER_TPL, $index );
		}, $html );
	}

	/**
	 * Determine whether an SVG fragment's opening tag carries an annotation
	 * attribute (`data-field`, `data-field-type`, or `data-field-href`).
	 * Only the opening `<svg ...>` tag is inspected, so annotation attributes
	 * on inner elements (e.g. a child `<path data-field="...">`) don't count.
	 *
	 * @param string $svg The full `<svg>...</svg>` fragment.
	 * @return bool
	 */
	private static function isAnnotatedSvg( string $svg ): bool {
		if ( ! preg_match( self::SVG_OPEN_TAG_RE, $svg, $open ) ) {
			return false;
		}
		return 1 === preg_match( self::ANNOTATED_OPEN_RE, $open[0] );
	}

	/**
	 * Restore original SVG strings from placeholders.
	 *
	 * @param string $html HTML with SVG placeholders.
	 * @return string HTML with original SVGs restored.
	 */
	public function restore( string $html ): string {
		return preg_replace_callback( self::PLACEHOLDER_RE, function ( $match ) {
			$index = (int) $match[1];
			$svg   = $this->extracted[ $index ] ?? $match[0];
			return self::sanitize_svg( $svg );
		}, $html );
	}

	/**
	 * Sanitize an SVG string by removing dangerous elements and attributes.
	 *
	 * Strips script tags, event handler attributes, and foreignObject elements
	 * that could be used for XSS attacks.
	 *
	 * @param string $svg Raw SVG string.
	 * @return string Sanitized SVG.
	 */
	public static function sanitize_svg( string $svg ): string {
		// Remove <script> tags and their contents.
		$svg = preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $svg );

		// Remove event handler attributes (on*="...").
		$svg = preg_replace( '/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $svg );

		// Remove <foreignObject> elements and their contents.
		$svg = preg_replace( '/<foreignObject\b[\s\S]*?<\/foreignObject>/i', '', $svg );

		return $svg;
	}

	/**
	 * Get a specific extracted SVG by index.
	 *
	 * @param int $index The placeholder index.
	 * @return string|null The SVG string, or null if not found.
	 */
	public function get( int $index ): ?string {
		return $this->extracted[ $index ] ?? null;
	}

	/**
	 * Check if a string is an SVG placeholder.
	 *
	 * @param string $text The text to check.
	 * @return bool True if the text matches the placeholder pattern.
	 */
	public static function isPlaceholder( string $text ): bool {
		return 1 === preg_match( self::PLACEHOLDER_RE, trim( $text ) );
	}

	/**
	 * Extract the index from a placeholder string.
	 *
	 * @param string $text The placeholder text.
	 * @return int|null The index, or null if not a placeholder.
	 */
	public static function placeholderIndex( string $text ): ?int {
		if ( preg_match( self::PLACEHOLDER_RE, trim( $text ), $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Whether an SVG fragment carries `aria-hidden="true"` on its opening tag.
	 * Centralizes the regex used by the auto-annotate pre-pass (which needs
	 * to make this call against the *preserved* SVG string, after the SVG has
	 * already been lifted out of the DOM).
	 *
	 * @param string $svg The full `<svg>...</svg>` fragment.
	 * @return bool
	 */
	public static function isAriaHidden( string $svg ): bool {
		if ( ! preg_match( self::SVG_OPEN_TAG_RE, $svg, $open ) ) {
			return false;
		}
		return 1 === preg_match( '/\baria-hidden\s*=\s*(?:"true"|\'true\')/i', $open[0] );
	}
}
