<?php

namespace FL\DesignSystem\Services\Parser\AutoAnnotateGaps;

/**
 * Classify a candidate element. Returns:
 *   ['type' => 'annotate-leaf'|'annotate-editor'|'annotate-href-and-descend'|'skip-and-descend',
 *    'fallback' => string]
 *
 * Pure-function classifier — no DOM mutation, no settings writes. Mirrors
 * `frontend/src/core/services/annotation-codec/auto-annotate-gaps.js classify`.
 */
class Classifier {

	private const TEXT_CONTAINER_TAGS = [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'blockquote', 'li', 'figcaption' ];

	/**
	 * Decide what to do with a candidate element.
	 *
	 * @param \DOMElement $el  The candidate element.
	 * @param string      $tag Lower-cased tag name.
	 * @return array{type: string, fallback?: string}
	 */
	public static function classify( \DOMElement $el, string $tag ): array {
		if ( 'img' === $tag ) {
			return [ 'type' => 'annotate-leaf', 'fallback' => 'image' ];
		}

		// Walker handles `<svg>` via the placeholder-comment path; the
		// Classifier's element-level `'skip-and-descend'` is correct because
		// PHP never sees `<svg>` as an element child (SvgPreserver replaces
		// them with comments before DOMDocument parsing).
		if ( 'svg' === $tag ) {
			return [ 'type' => 'skip-and-descend' ];
		}

		if ( 'a' === $tag && $el->hasAttribute( 'href' ) ) {
			// Icon-only anchors route to href-only + descend so the icon
			// gets its own field. Everything else (anchors with text, or
			// anchors with a mix of icons and non-icon elements) becomes a
			// link compound; `processLinkField` extracts any icon child
			// into a sibling `{linkKey}_icon` field at full-parse time.
			// See decision: link-with-icon-as-compound-plus-svg.
			if ( self::isIconOnlyAnchor( $el ) ) {
				return [ 'type' => 'annotate-href-and-descend', 'fallback' => 'link' ];
			}
			return [ 'type' => 'annotate-leaf', 'fallback' => 'link' ];
		}

		if ( in_array( $tag, self::TEXT_CONTAINER_TAGS, true ) ) {
			$shape    = self::classifyTextContainerShape( $el );
			$fallback = self::textFallbackForTag( $tag );

			if ( 'pure-text' === $shape ) {
				return [ 'type' => 'annotate-leaf', 'fallback' => $fallback ];
			}
			if ( 'mixed' === $shape ) {
				return [ 'type' => 'annotate-editor', 'fallback' => $fallback ];
			}
			// 'pure-elements' and 'svg-only' both → skip-and-descend.
			return [ 'type' => 'skip-and-descend' ];
		}

		if ( 'span' === $tag ) {
			// Spans are common for both content (eyebrows, stats, labels) and
			// decoration (icon wrappers, sr-only). Require non-empty text to
			// avoid annotating empty/decorative spans.
			$shape = self::classifyTextContainerShape( $el );
			if ( 'pure-text' === $shape ) {
				if ( '' === trim( $el->textContent ) ) {
					return [ 'type' => 'skip-and-descend' ];
				}
				return [ 'type' => 'annotate-leaf', 'fallback' => 'text' ];
			}
			if ( 'mixed' === $shape ) {
				return [ 'type' => 'annotate-editor', 'fallback' => 'text' ];
			}
			return [ 'type' => 'skip-and-descend' ];
		}

		if ( 'div' === $tag ) {
			// Divs are mostly structural — only annotate pure-text divs with
			// real content. Mixed-content divs are deferred: a `<p>Hello <strong>x</strong></p>`
			// is unambiguously a paragraph with inline formatting, but a
			// `<div>Hello <h2>Title</h2></div>` is a wrapper with stray text.
			// Phrasing-vs-block child detection is brittle; defer.
			$shape = self::classifyTextContainerShape( $el );
			if ( 'pure-text' === $shape ) {
				if ( '' === trim( $el->textContent ) ) {
					return [ 'type' => 'skip-and-descend' ];
				}
				return [ 'type' => 'annotate-leaf', 'fallback' => 'text' ];
			}
			return [ 'type' => 'skip-and-descend' ];
		}

		return [ 'type' => 'skip-and-descend' ];
	}

	/**
	 * True if the anchor's content is exclusively icons (`<svg>`, `<img>`,
	 * or SvgPreserver placeholder comments) with no outer text. Such
	 * anchors get href-only annotation so descent can pick up the icon as
	 * its own field. Any outer text or any non-icon element child
	 * disqualifies — those anchors become link compounds and let
	 * `processLinkField` handle any icon extraction at full-parse time.
	 */
	private static function isIconOnlyAnchor( \DOMElement $a ): bool {
		$has_any_icon = false;

		foreach ( $a->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( $child->textContent ) ) {
					return false;
				}
				continue;
			}
			if ( XML_COMMENT_NODE === $child->nodeType ) {
				// SvgPreserver placeholder comments stand in for decorative
				// SVGs that were extracted before the pre-pass. Treat as icon.
				if ( self::isSvgPlaceholderComment( $child ) ) {
					$has_any_icon = true;
				}
				continue;
			}
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$child_tag = strtolower( $child->tagName );
				if ( 'svg' === $child_tag || 'img' === $child_tag ) {
					$has_any_icon = true;
				} else {
					return false;
				}
			}
		}

		return $has_any_icon;
	}

	/**
	 * Text-container shape — one of:
	 *   - 'pure-text': only text node children (or text + SVG, since SVG is
	 *     ignored). Annotate as text leaf; downstream `processTextField` handles
	 *     any inline SVG via the existing mixed-SVG-text split.
	 *   - 'mixed': outer text + non-SVG element children. Annotate as editor.
	 *   - 'pure-elements': only non-SVG element children, no outer text. Skip.
	 *   - 'svg-only': only SVG (or SVG-placeholder) children, no outer text,
	 *     no real elements. Skip — content is purely decorative.
	 *
	 * The SVG carve-out keeps PHP and JS aligned: PHP's SvgPreserver replaces
	 * decorative SVGs with comments *before* this pre-pass, so we cannot let
	 * `<svg>` (JS) or its placeholder comment (PHP) count as a "real" element
	 * child — that would diverge the two runtimes' classifications.
	 */
	private static function classifyTextContainerShape( \DOMElement $el ): string {
		$has_outer_text = false;
		$has_elements   = false;
		$has_svg_like   = false;

		foreach ( $el->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' !== trim( $child->textContent ) ) {
					$has_outer_text = true;
				}
				continue;
			}
			if ( XML_COMMENT_NODE === $child->nodeType ) {
				if ( self::isSvgPlaceholderComment( $child ) ) {
					$has_svg_like = true;
				}
				continue;
			}
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$child_tag = strtolower( $child->tagName );
				if ( 'svg' === $child_tag ) {
					$has_svg_like = true;
					continue;
				}
				$has_elements = true;
			}
		}

		if ( ! $has_elements ) {
			if ( $has_outer_text ) {
				return 'pure-text';
			}
			if ( $has_svg_like ) {
				return 'svg-only';
			}
			// Genuinely empty — fall through to pure-text so legacy text
			// containers (`<p></p>`, `<h2></h2>`) still get the type-based key.
			// Span and div have their own empty-text guard upstream.
			return 'pure-text';
		}
		if ( ! $has_outer_text ) {
			return 'pure-elements';
		}
		return 'mixed';
	}

	public static function isSvgPlaceholderComment( \DOMNode $comment ): bool {
		$data = $comment->nodeValue;
		if ( null === $data ) {
			return false;
		}
		return (bool) preg_match( '/^__BB_SVG_\d+__$/', $data );
	}

	private static function textFallbackForTag( string $tag ): string {
		if ( in_array( $tag, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ) {
			return 'heading';
		}
		if ( 'blockquote' === $tag ) {
			return 'quote';
		}
		if ( 'li' === $tag ) {
			return 'item';
		}
		if ( 'figcaption' === $tag ) {
			return 'caption';
		}
		return 'text';
	}
}
