<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * Section Discovery
 *
 * PHP equivalent of the JS section-discovery helper. Finds section-like
 * elements within a parsed DOMDocument body. Used by PageParser.
 *
 * Two modes:
 *   - Strict (default): tags `header`, `section`, `footer` only.
 *   - Gated (`opts['gated']`): qualifies any body-level element with a
 *     `data-label` OR non-empty `id`. Non-qualifying elements treated as
 *     wrapper candidates and descended into (depth ≤ 3).
 */
class SectionDiscovery {

	private const SEMANTIC_SECTION_TAGS = [ 'header', 'section', 'footer' ];
	private const ALWAYS_FILTERED       = [ 'script', 'style', 'link', 'noscript', 'template' ];
	private const MAX_DESCENT_DEPTH     = 3;

	/**
	 * Discover sections in a body element.
	 *
	 * @param \DOMElement|null $body Body element to scan, or null.
	 * @param array            $opts {
	 *     @type bool $gated When true, use the gated rule and descend into wrappers.
	 * }
	 * @return array<int, array{element: \DOMElement, id: string, label: string, tag: string, depth: int}>
	 */
	public static function discover( ?\DOMElement $body, array $opts = [] ): array {
		if ( null === $body ) {
			return [];
		}

		$gated = ! empty( $opts['gated'] );

		if ( $gated ) {
			return self::discover_gated( $body );
		}

		return self::discover_strict( $body );
	}

	/**
	 * Strict mode: direct children with tag in SEMANTIC_SECTION_TAGS.
	 *
	 * @param \DOMElement $body
	 * @return array<int, array{element: \DOMElement, id: string, label: string, tag: string, depth: int}>
	 */
	private static function discover_strict( \DOMElement $body ): array {
		$results = [];
		foreach ( $body->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, self::SEMANTIC_SECTION_TAGS, true ) ) {
				$results[] = self::make_entry( $child, 0 );
			}
		}
		return $results;
	}

	/**
	 * Gated mode: data-label OR non-empty id qualifies. Non-qualifying body-level
	 * elements get descended into (max depth 3).
	 *
	 * @param \DOMElement $body
	 * @return array<int, array{element: \DOMElement, id: string, label: string, tag: string, depth: int}>
	 */
	private static function discover_gated( \DOMElement $body ): array {
		$results = [];
		foreach ( $body->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, self::ALWAYS_FILTERED, true ) ) {
				continue;
			}

			if ( self::is_gated_qualifier( $child ) ) {
				$results[] = self::make_entry( $child, 0 );
				continue;
			}

			// Wrapper candidate — descend.
			$descended = self::descend_for_qualifiers( $child, 1 );
			foreach ( $descended as $entry ) {
				$results[] = $entry;
			}
		}
		return $results;
	}

	/**
	 * Walk descendants of $parent depth-first, collecting gated qualifiers up
	 * to MAX_DESCENT_DEPTH. Stops descending past a qualifier (qualifier's
	 * children are content, not nested sections).
	 *
	 * @param \DOMElement $parent
	 * @param int         $depth
	 * @return array<int, array{element: \DOMElement, id: string, label: string, tag: string, depth: int}>
	 */
	private static function descend_for_qualifiers( \DOMElement $parent, int $depth ): array {
		if ( $depth > self::MAX_DESCENT_DEPTH ) {
			return [];
		}
		$results = [];
		foreach ( $parent->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, self::ALWAYS_FILTERED, true ) ) {
				continue;
			}

			if ( self::is_gated_qualifier( $child ) ) {
				$results[] = self::make_entry( $child, $depth );
				continue;
			}

			$deeper = self::descend_for_qualifiers( $child, $depth + 1 );
			foreach ( $deeper as $entry ) {
				$results[] = $entry;
			}
		}
		return $results;
	}

	/**
	 * @param \DOMElement $el
	 * @return bool
	 */
	private static function is_gated_qualifier( \DOMElement $el ): bool {
		$data_label = trim( $el->getAttribute( 'data-label' ) );
		$id         = trim( $el->getAttribute( 'id' ) );
		return '' !== $data_label || '' !== $id;
	}

	/**
	 * @param \DOMElement $el
	 * @param int         $depth
	 * @return array{element: \DOMElement, id: string, label: string, tag: string, depth: int}
	 */
	private static function make_entry( \DOMElement $el, int $depth ): array {
		$id    = $el->getAttribute( 'id' ) ?: '';
		$label = $el->getAttribute( 'data-label' ) ?: $id;
		return [
			'element' => $el,
			'id'      => $id,
			'label'   => $label,
			'tag'     => strtolower( $el->tagName ),
			'depth'   => $depth,
		];
	}
}
