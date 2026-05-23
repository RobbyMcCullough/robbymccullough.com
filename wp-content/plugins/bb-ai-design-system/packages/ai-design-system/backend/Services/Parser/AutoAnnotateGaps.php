<?php

namespace FL\DesignSystem\Services\Parser;

use FL\DesignSystem\Services\Parser\AutoAnnotateGaps\Keys;
use FL\DesignSystem\Services\Parser\AutoAnnotateGaps\Walker;

/**
 * Auto-Annotate Gaps
 *
 * Pre-pass that walks the DOM and adds `data-field` / `data-field-href`
 * to obvious editable elements the agent skipped. Explicit annotations
 * are always primary; this is a floor, not a ceiling.
 *
 * Keys are type-based: first in-scope `<h2>` becomes `heading`, the next
 * `heading_2`, then `heading_3`. Same pattern for `text`, `link`, `image`,
 * `quote`, `item`, `caption`, `icon`. The form-label layer turns these into
 * "Heading" / "Heading 2" via the existing `formatLabel` helper.
 *
 * Internally split into Walker, Classifier, and Keys sub-modules.
 * Mirrors `frontend/src/core/services/annotation-codec/auto-annotate-gaps.js`.
 */
class AutoAnnotateGaps {

	/**
	 * Tags whose subtrees are never auto-annotated. Shared across Walker
	 * (skip-and-stop during traversal) and Keys (don't seed used-keys from
	 * elements inside these tags).
	 */
	public const SKIP_ANCESTOR_TAGS = [ 'head', 'script', 'style' ];

	/**
	 * Walks the body and auto-annotates in-scope unannotated elements.
	 *
	 * Returns a structured record:
	 *   - 'count'                    int   Total elements newly annotated.
	 *   - 'svgEmissionPlan'          array Top-level SVG emission entries —
	 *                                       `[ ['comment'=>\DOMComment,
	 *                                          'key'=>'icon',
	 *                                          'placeholderIndex'=>int,
	 *                                          'scope'=>'top'], ... ]`.
	 *                                       The walker only records decisions;
	 *                                       the parser replaces the comment
	 *                                       with a Mustache token and writes
	 *                                       the SVG bytes into settings.
	 *   - 'svgRepeaterEmissionPlan'  array Per-repeater entries —
	 *                                       `[ ['repeaterKey'=>string,
	 *                                          'firstItemSvgPlan'=>[ ['walkOrder'=>int,'key'=>string], ... ],
	 *                                          'itemCommentRefs'=>[ [\DOMComment, ...], ... ],
	 *                                          'itemPlaceholderIndices'=>[ [int, ...], ... ] ], ... ]`.
	 *                                       The parser executes these after
	 *                                       `processNode` has built per-item
	 *                                       settings arrays.
	 *
	 * The walker recognizes `SvgPreserver` placeholder comments as candidates
	 * for synthetic SVG fields. When `$svg_preserver` is null, comments are
	 * left untouched on both planes.
	 *
	 * @param \DOMNode          $body          The body element.
	 * @param SvgPreserver|null $svg_preserver Optional preserver for resolving placeholder comments.
	 * @return array{count: int, svgEmissionPlan: array, svgRepeaterEmissionPlan: array}
	 */
	public static function apply( \DOMNode $body, ?SvgPreserver $svg_preserver = null ): array {
		if ( ! $body instanceof \DOMElement && ! $body instanceof \DOMDocument ) {
			return [
				'count'                   => 0,
				'svgEmissionPlan'         => [],
				'svgRepeaterEmissionPlan' => [],
			];
		}

		$used_keys              = Keys::collectExistingKeys( $body, /* include_repeater_items */ false );
		$count                  = 0;
		$discard_plan           = [];
		$svg_emission_plan      = [];
		$svg_repeater_plan      = [];

		Walker::walk(
			$body,
			$used_keys,
			$count,
			$discard_plan,
			false,
			[],
			$svg_preserver,
			$svg_emission_plan,
			$svg_repeater_plan
		);

		return [
			'count'                   => $count,
			'svgEmissionPlan'         => $svg_emission_plan,
			'svgRepeaterEmissionPlan' => $svg_repeater_plan,
		];
	}
}
