<?php

namespace FL\DesignSystem\Services\Parser\AutoAnnotateGaps;

use FL\DesignSystem\Services\Parser\AutoAnnotateGaps;
use FL\DesignSystem\Services\Parser\SvgPreserver;

/**
 * Depth-first walker for the auto-annotate pre-pass. Visits elements,
 * delegates classification to the Classifier, and applies the resulting
 * `data-field` / `data-field-href` annotations. Handles repeater scope
 * via the "first item is the template" model.
 *
 * SVG handling: PHP's SvgPreserver lifts decorative `<svg>` elements into
 * `<!--__BB_SVG_N__-->` placeholder comments before DOMDocument sees the
 * HTML. The walker recognizes those comments and records emission plans
 * (top-level `svgEmissionPlan` plus per-repeater `svgRepeaterEmissionPlan`)
 * so the parser can synthetically emit SVG fields whose serialized bytes
 * match the JS runtime byte-for-byte.
 */
class Walker {

	/**
	 * Depth-first walk. When `$record` is true, every annotation made inside
	 * this subtree is recorded by element-child path into `$plan_out` so a
	 * sibling walk can replay it via `applyPlan` (repeater-item consistency).
	 *
	 * `$svg_emission_plan` accumulates top-level SVG emission decisions.
	 * `$svg_repeater_plan_out` accumulates per-repeater plans (one entry per
	 * repeater encountered during the walk). Both are mutated by reference.
	 *
	 * @param \DOMNode          $node                   Current node.
	 * @param array             $used_keys              Used-keys set for this scope (mutated).
	 * @param int               $count                  Newly-annotated counter (mutated).
	 * @param array             $plan_out               Plan accumulator (mutated when $record is true).
	 * @param bool              $record                 Whether to record annotations into $plan_out.
	 * @param array             $path                   Element-child path from the plan's root.
	 * @param SvgPreserver|null $svg_preserver          Preserver for resolving placeholder comments.
	 * @param array             $svg_emission_plan      Top-level SVG emission entries (mutated).
	 * @param array             $svg_repeater_plan_out  Per-repeater SVG plans (mutated).
	 */
	public static function walk(
		\DOMNode $node,
		array &$used_keys,
		int &$count,
		array &$plan_out,
		bool $record,
		array $path,
		?SvgPreserver $svg_preserver,
		array &$svg_emission_plan,
		array &$svg_repeater_plan_out
	): void {
		// Snapshot defends against any handler that mutates the child list.
		$children = [];
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}

		$el_index = -1;
		foreach ( $children as $child ) {
			if ( XML_COMMENT_NODE === $child->nodeType ) {
				if ( $child instanceof \DOMComment ) {
					self::processSvgPlaceholderComment(
						$child,
						$used_keys,
						$count,
						$svg_preserver,
						$svg_emission_plan,
						'top'
					);
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$el_index++;
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, AutoAnnotateGaps::SKIP_ANCESTOR_TAGS, true ) ) {
				continue;
			}

			$child_path = array_merge( $path, [ $el_index ] );

			if ( $child->hasAttribute( 'data-repeater' ) ) {
				// Repeaters reset scope: their items are walked through walkRepeater,
				// which manages first-item-template key consistency and per-item
				// SVG emissions.
				self::walkRepeater( $child, $count, $svg_preserver, $svg_repeater_plan_out );
				continue;
			}

			$should_descend = self::processElement( $child, $tag, $used_keys, $count, $plan_out, $record, $child_path );
			if ( $should_descend ) {
				self::walk(
					$child,
					$used_keys,
					$count,
					$plan_out,
					$record,
					$child_path,
					$svg_preserver,
					$svg_emission_plan,
					$svg_repeater_plan_out
				);
			}
		}
	}

	/**
	 * Record a top-level SVG emission decision for a placeholder comment.
	 * Resolves the preserved SVG and respects `aria-hidden="true"` on the
	 * original opening tag. The comment node is left in the DOM unchanged;
	 * the parser executes the plan after `apply()` returns.
	 */
	private static function processSvgPlaceholderComment(
		\DOMComment $comment,
		array &$used_keys,
		int &$count,
		?SvgPreserver $svg_preserver,
		array &$svg_emission_plan,
		string $scope
	): void {
		if ( null === $svg_preserver ) {
			return;
		}
		$marker = '<!--' . $comment->data . '-->';
		if ( ! SvgPreserver::isPlaceholder( $marker ) ) {
			return;
		}
		$index = SvgPreserver::placeholderIndex( $marker );
		if ( null === $index ) {
			return;
		}
		$svg_string = $svg_preserver->get( $index );
		if ( null === $svg_string ) {
			return;
		}
		if ( SvgPreserver::isAriaHidden( $svg_string ) ) {
			return;
		}

		$key                 = Keys::disambiguate( 'icon', $used_keys );
		$svg_emission_plan[] = [
			'comment'          => $comment,
			'key'              => $key,
			'placeholderIndex' => $index,
			'scope'            => $scope,
		];
		$count++;
	}

	/**
	 * Classify and annotate a single element. Returns true if descent into
	 * its children is appropriate (skip-and-descend or annotate-href-and-descend),
	 * false otherwise (annotate-leaf, annotate-editor, already-annotated, or
	 * skip-and-stop conditions like aria-hidden / inside SKIP_ANCESTOR_TAGS).
	 */
	private static function processElement(
		\DOMElement $el,
		string $tag,
		array &$used_keys,
		int &$count,
		array &$plan_out,
		bool $record,
		array $path
	): bool {
		// No-nested-fields rule: data-field stops descent; data-field-href
		// alone descends so an inner element (e.g. <svg>) can still pick up
		// its own field (mirrors processHrefOnlyField).
		if ( $el->hasAttribute( 'data-field' ) ) {
			return false;
		}
		if ( $el->hasAttribute( 'data-field-href' ) ) {
			return true;
		}

		if ( $el->getAttribute( 'aria-hidden' ) === 'true' ) {
			return false;
		}

		$classification = Classifier::classify( $el, $tag );

		switch ( $classification['type'] ) {
			case 'annotate-leaf':
				$key = Keys::disambiguate( $classification['fallback'], $used_keys );
				$el->setAttribute( 'data-field', $key );
				$count++;
				if ( $record ) {
					$plan_out[] = [ 'path' => $path, 'attr' => 'data-field', 'key' => $key, 'editor' => false ];
				}
				return false;

			case 'annotate-editor':
				$key = Keys::disambiguate( $classification['fallback'], $used_keys );
				$el->setAttribute( 'data-field', $key );
				$el->setAttribute( 'data-field-type', 'editor' );
				$count++;
				if ( $record ) {
					$plan_out[] = [ 'path' => $path, 'attr' => 'data-field', 'key' => $key, 'editor' => true ];
				}
				return false;

			case 'annotate-href-and-descend':
				$key = Keys::disambiguate( $classification['fallback'], $used_keys );
				$el->setAttribute( 'data-field-href', $key );
				$count++;
				if ( $record ) {
					$plan_out[] = [ 'path' => $path, 'attr' => 'data-field-href', 'key' => $key, 'editor' => false ];
				}
				return true;

			case 'skip-and-descend':
			default:
				return true;
		}
	}

	/**
	 * Process a `data-repeater` subtree. Mirrors the parser's "first item is the
	 * template" model — annotate the first item, then copy the resulting
	 * annotations onto matching DOM positions in sibling items so keys agree.
	 *
	 * Phase 2: also records a per-repeater SVG plan covering placeholder
	 * comments inside items. The first item's placeholder comments establish
	 * the plan (with walk-order indexing); siblings are enumerated by
	 * walk-order, so their corresponding placeholder gets the same key. The
	 * parser executes the plan after `apply()` returns.
	 */
	private static function walkRepeater(
		\DOMElement $repeater,
		int &$count,
		?SvgPreserver $svg_preserver,
		array &$svg_repeater_plan_out
	): void {
		$items = [];
		foreach ( $repeater->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType
				&& $child instanceof \DOMElement
				&& $child->hasAttribute( 'data-repeater-item' )
			) {
				$items[] = $child;
			}
		}

		if ( empty( $items ) ) {
			return;
		}

		$first_item = $items[0];
		$first_tag  = strtolower( $first_item->tagName );
		// Per-item keys live in their own scope (the parser writes them under
		// the repeater key, not at top level). Seed from the first item only.
		$item_used                      = Keys::collectExistingKeys( $first_item, /* include_repeater_items */ true );
		$attr_plan                      = [];
		$first_item_svg_plan            = [];
		$first_item_comment_refs        = [];
		$first_item_placeholder_indices = [];
		$walk_order                     = 0;

		// Process the item element itself first. `data-repeater-item` can sit
		// on in-scope leaf elements (anchor-as-item is a common nav/CTA
		// pattern), so the item must be classified before any descent into
		// its children. An empty path in the plan resolves to the item itself
		// when applied to siblings.
		$should_descend = self::processElement( $first_item, $first_tag, $item_used, $count, $attr_plan, true, [] );
		if ( $should_descend ) {
			self::walkRepeaterFirstItem(
				$first_item,
				$item_used,
				$count,
				$attr_plan,
				$svg_preserver,
				$first_item_svg_plan,
				$first_item_comment_refs,
				$first_item_placeholder_indices,
				$walk_order,
				[]
			);
		}

		$item_comment_refs        = [ $first_item_comment_refs ];
		$item_placeholder_indices = [ $first_item_placeholder_indices ];

		for ( $i = 1, $n = count( $items ); $i < $n; $i++ ) {
			self::applyPlan( $items[ $i ], $attr_plan, $count );

			$sib_refs    = [];
			$sib_indices = [];
			self::enumerateSiblingPlaceholderComments( $items[ $i ], $svg_preserver, $sib_refs, $sib_indices );
			$item_comment_refs[]        = $sib_refs;
			$item_placeholder_indices[] = $sib_indices;
		}

		// Surface the repeater plan only when (a) the first item established
		// at least one SVG decision AND (b) the container has a repeater key
		// the parser can write per-item settings against.
		if ( ! empty( $first_item_svg_plan ) ) {
			$repeater_key = $repeater->getAttribute( 'data-repeater' );
			if ( '' !== $repeater_key ) {
				$svg_repeater_plan_out[] = [
					'repeaterKey'            => $repeater_key,
					'firstItemSvgPlan'       => $first_item_svg_plan,
					'itemCommentRefs'        => $item_comment_refs,
					'itemPlaceholderIndices' => $item_placeholder_indices,
				];
			}
		}
	}

	/**
	 * Walk a repeater's first item. Mirrors `walk` but records SVG decisions
	 * with walk-order indexing instead of into the top-level plan, so a
	 * sibling walk can re-locate the same positions across structurally
	 * comparable items.
	 *
	 * @param int $walk_order Mutates as placeholder comments are encountered;
	 *                        the index is shared between the first-item plan
	 *                        and the sibling enumeration.
	 */
	private static function walkRepeaterFirstItem(
		\DOMNode $node,
		array &$used_keys,
		int &$count,
		array &$attr_plan_out,
		?SvgPreserver $svg_preserver,
		array &$svg_plan_out,
		array &$comment_refs_out,
		array &$placeholder_indices_out,
		int &$walk_order,
		array $path
	): void {
		$children = [];
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}

		$el_index = -1;
		foreach ( $children as $child ) {
			if ( XML_COMMENT_NODE === $child->nodeType ) {
				if ( $child instanceof \DOMComment ) {
					self::recordFirstItemPlaceholderComment(
						$child,
						$used_keys,
						$count,
						$svg_preserver,
						$svg_plan_out,
						$comment_refs_out,
						$placeholder_indices_out,
						$walk_order
					);
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			$el_index++;
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, AutoAnnotateGaps::SKIP_ANCESTOR_TAGS, true ) ) {
				continue;
			}

			$child_path = array_merge( $path, [ $el_index ] );

			if ( $child->hasAttribute( 'data-repeater' ) ) {
				// Nested repeaters: walk normally so attribute-plan annotations
				// fire, but discard any nested SVG emission plan — out of
				// scope for Phase 2.
				$nested_plan = [];
				self::walkRepeater( $child, $count, $svg_preserver, $nested_plan );
				continue;
			}

			$should_descend = self::processElement( $child, $tag, $used_keys, $count, $attr_plan_out, true, $child_path );
			if ( $should_descend ) {
				self::walkRepeaterFirstItem(
					$child,
					$used_keys,
					$count,
					$attr_plan_out,
					$svg_preserver,
					$svg_plan_out,
					$comment_refs_out,
					$placeholder_indices_out,
					$walk_order,
					$child_path
				);
			}
		}
	}

	/**
	 * Record a first-item placeholder comment: track the ref + placeholder
	 * index regardless of eligibility (so the walk-order axis stays aligned
	 * with siblings), and add a plan entry only when the SVG isn't
	 * `aria-hidden="true"`.
	 */
	private static function recordFirstItemPlaceholderComment(
		\DOMComment $comment,
		array &$used_keys,
		int &$count,
		?SvgPreserver $svg_preserver,
		array &$svg_plan_out,
		array &$comment_refs_out,
		array &$placeholder_indices_out,
		int &$walk_order
	): void {
		if ( null === $svg_preserver ) {
			return;
		}
		$marker = '<!--' . $comment->data . '-->';
		if ( ! SvgPreserver::isPlaceholder( $marker ) ) {
			return;
		}
		$index = SvgPreserver::placeholderIndex( $marker );
		if ( null === $index ) {
			return;
		}
		$svg_string = $svg_preserver->get( $index );

		$comment_refs_out[]        = $comment;
		$placeholder_indices_out[] = $index;

		if ( null !== $svg_string && ! SvgPreserver::isAriaHidden( $svg_string ) ) {
			$key            = Keys::disambiguate( 'icon', $used_keys );
			$svg_plan_out[] = [
				'walkOrder' => $walk_order,
				'key'       => $key,
			];
			$count++;
		}

		$walk_order++;
	}

	/**
	 * Enumerate placeholder comments inside a sibling item in document order.
	 * Mirrors the first-item traversal shape (depth-first, skipping
	 * annotated ancestors and nested repeaters) so walk-order indexes align
	 * across items. No classification happens here — the plan is set by the
	 * first item; siblings just supply the comment refs at matching
	 * positions.
	 */
	private static function enumerateSiblingPlaceholderComments(
		\DOMNode $node,
		?SvgPreserver $svg_preserver,
		array &$comment_refs_out,
		array &$placeholder_indices_out
	): void {
		if ( null === $svg_preserver ) {
			return;
		}

		foreach ( $node->childNodes as $child ) {
			if ( XML_COMMENT_NODE === $child->nodeType && $child instanceof \DOMComment ) {
				$marker = '<!--' . $child->data . '-->';
				if ( SvgPreserver::isPlaceholder( $marker ) ) {
					$index = SvgPreserver::placeholderIndex( $marker );
					if ( null !== $index ) {
						$comment_refs_out[]        = $child;
						$placeholder_indices_out[] = $index;
					}
				}
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType || ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( in_array( $tag, AutoAnnotateGaps::SKIP_ANCESTOR_TAGS, true ) ) {
				continue;
			}
			// Annotated ancestors and nested repeaters define their own
			// scopes; don't enumerate placeholder comments inside them.
			if ( $child->hasAttribute( 'data-field' ) || $child->hasAttribute( 'data-repeater' ) ) {
				continue;
			}

			self::enumerateSiblingPlaceholderComments(
				$child,
				$svg_preserver,
				$comment_refs_out,
				$placeholder_indices_out
			);
		}
	}

	/**
	 * Replay the first-item annotation plan onto a sibling item, skipping
	 * any element that already carries an annotation (respect the agent).
	 */
	private static function applyPlan( \DOMElement $item, array $plan, int &$count ): void {
		foreach ( $plan as $step ) {
			$target = self::resolvePath( $item, $step['path'] );
			if ( ! $target instanceof \DOMElement ) {
				continue;
			}
			if ( $target->hasAttribute( 'data-field' ) || $target->hasAttribute( 'data-field-href' ) ) {
				continue;
			}
			$target->setAttribute( $step['attr'], $step['key'] );
			if ( $step['editor'] ) {
				$target->setAttribute( 'data-field-type', 'editor' );
			}
			$count++;
		}
	}

	/**
	 * Resolve a path of element-child indices to a node, ignoring non-element
	 * nodes so siblings with whitespace differences still align.
	 */
	private static function resolvePath( \DOMNode $root, array $path ): ?\DOMNode {
		$node = $root;
		foreach ( $path as $idx ) {
			$found      = null;
			$count_seen = -1;
			foreach ( $node->childNodes as $child ) {
				if ( XML_ELEMENT_NODE !== $child->nodeType ) {
					continue;
				}
				$count_seen++;
				if ( $count_seen === $idx ) {
					$found = $child;
					break;
				}
			}
			if ( null === $found ) {
				return null;
			}
			$node = $found;
		}
		return $node;
	}
}
