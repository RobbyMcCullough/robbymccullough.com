<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * CSS Over-Claim Auditor
 *
 * Symmetric completion of orphan recovery: when a CSS rule lives under one
 * `/* @section X * /` marker but its selectors target classes shared by 2+
 * sections, the rule is "over-claimed" — the marker correctly placed it in X,
 * but authorial intent (the LLM's) was to share the rule. Lifts those rules
 * to page-level CSS so all matching sections inherit them.
 *
 * Conservative criterion: a rule lifts only if EVERY root class in its
 * selectors appears in 2+ blocks' HTML. If any root is unique to the source
 * section (e.g., `.experience-header` in `.experience-header .section-title`),
 * the rule is treated as intentionally scoped and stays put.
 *
 * ## Cascade-order matrix (page CSS vs per-block CSS across the 5 render paths)
 *
 *   | Path                | Per-block CSS emission            | Page CSS emission       |
 *   |---------------------|-----------------------------------|-------------------------|
 *   | BB editor           | wp_head priority 8 (compiled file)| wp_head priority 1001   |
 *   | BB frontend         | wp_head priority 8 (compiled file)| wp_head priority 1001   |
 *   | Gutenberg editor    | inline <style> in block render    | wp_head priority 1001   |
 *   | Gutenberg frontend  | inline <style> in block render    | wp_head priority 1001   |
 *   | Kit export          | after /* @page * / in bundle      | first in bundle         |
 *
 * BB paths emit per-block CSS BEFORE page CSS in document source order; the
 * other 3 paths emit page CSS first. The audit is safe regardless.
 *
 * ## Safety guarantee: specificity, not source order
 *
 * The "ancestor-uniqueness" lift criterion structurally guarantees:
 *
 *   1. A lifted rule has all-shared roots → its selector contains only
 *      shared class chains → specificity is bounded by class-count alone.
 *   2. A rule kept in a section has at least one unique-to-section root →
 *      its selector contains that anchor + (optionally) shared classes →
 *      specificity ≥ lifted rule's specificity + 1 (the anchor adds one
 *      class to the count).
 *   3. Therefore on any element targeted by both, the kept rule's specificity
 *      strictly exceeds the lifted rule's, so the kept rule wins regardless
 *      of which CSS landed first in the cascade.
 *
 * Test (h) in PageParserTest exercises this against an adversarial source
 * order (BB-style: per-block CSS authored to come "before" the lifted page
 * rule) and asserts the higher-specificity override still wins. Source order
 * is a non-load-bearing tie-breaker, included only for completeness.
 *
 * ## Out of scope
 *
 * - At-rules (`@keyframes`, `@media`, `@font-face`, `@supports`) stay in the
 *   source section. The LLM is responsible for placing those at `@page` if
 *   they're cross-cutting.
 * - Native CSS nesting and other unparseable shapes are skipped (left in the
 *   source section); this matches the simple-class-chain scope already used
 *   by CSS Background Image Promotion.
 * - `:root` rules carry no class-chain root, so extract_root_selectors
 *   returns empty for them and they're never lift candidates.
 */
class CssOverClaimAuditor {

	/**
	 * Audit parsed sections for over-claimed CSS and lift shared rules to
	 * page-level CSS.
	 *
	 * @param array  $sections Parsed sections array (post-routing). Each entry
	 *                         must have 'html' and 'css' string fields. Entries
	 *                         with type === 'preserved' are skipped.
	 * @param string $page_css Current `/* @page * /` CSS string.
	 * @return array{
	 *     sections: array,
	 *     page_css: string,
	 *     lifted_count: int
	 * }
	 */
	public static function audit( array $sections, string $page_css ): array {
		$class_to_blocks = self::build_class_to_blocks_map( $sections );
		if ( empty( $class_to_blocks ) ) {
			return [
				'sections'     => $sections,
				'page_css'     => $page_css,
				'lifted_count' => 0,
			];
		}

		$lifted_rules = [];
		$lifted_count = 0;

		foreach ( $sections as $idx => $section ) {
			if ( 'preserved' === ( $section['type'] ?? '' ) ) {
				continue;
			}
			$css = $section['css'] ?? '';
			if ( '' === $css ) {
				continue;
			}

			$source_id = self::source_id_for( $section, $idx );
			$result    = self::audit_section_css( $css, $source_id, $class_to_blocks );

			if ( $result['lifted_count'] > 0 ) {
				$sections[ $idx ]['css'] = $result['remaining_css'];
				foreach ( $result['lifted_rules'] as $rule ) {
					$lifted_rules[] = $rule;
				}
				$lifted_count += $result['lifted_count'];
			}
		}

		if ( $lifted_count > 0 ) {
			$lifted_block = implode( "\n", $lifted_rules );
			$page_css     = '' !== $page_css ? $page_css . "\n\n" . $lifted_block : $lifted_block;
		}

		return [
			'sections'     => $sections,
			'page_css'     => $page_css,
			'lifted_count' => $lifted_count,
		];
	}

	/**
	 * Build a map of class name → set of section ids that contain that class
	 * in their HTML. Section id falls back to numeric index for stability when
	 * no id attribute is present.
	 *
	 * @param array $sections
	 * @return array<string, array<int|string, true>>
	 */
	private static function build_class_to_blocks_map( array $sections ): array {
		$map = [];
		foreach ( $sections as $idx => $section ) {
			if ( 'preserved' === ( $section['type'] ?? '' ) ) {
				continue;
			}
			$source_id = self::source_id_for( $section, $idx );
			$html      = $section['html'] ?? '';
			if ( '' === $html ) {
				continue;
			}

			if ( preg_match_all( '/\bclass=["\']([^"\']*)["\']/', $html, $matches ) ) {
				foreach ( $matches[1] as $class_attr ) {
					foreach ( preg_split( '/\s+/', $class_attr ) as $cls ) {
						if ( '' === $cls ) {
							continue;
						}
						$cls_lower                       = strtolower( $cls );
						$map[ $cls_lower ][ $source_id ] = true;
					}
				}
			}
		}
		return $map;
	}

	/**
	 * Stable identifier for a parsed section. Prefers the id attribute, falls
	 * back to a numeric-index marker when absent. Different sections without
	 * ids stay distinguishable.
	 *
	 * @param array      $section
	 * @param int|string $idx
	 * @return string
	 */
	private static function source_id_for( array $section, $idx ): string {
		$id = $section['id'] ?? '';
		if ( '' !== $id ) {
			return strtolower( $id );
		}
		return '#idx-' . $idx;
	}

	/**
	 * Walk a section's CSS rule by rule, separate over-claimed rules from
	 * intentionally-scoped ones.
	 *
	 * @param string                                 $css             Section CSS.
	 * @param string                                 $source_id       Source section id (for ancestor-uniqueness check).
	 * @param array<string, array<int|string, true>> $class_to_blocks Map from build_class_to_blocks_map.
	 * @return array{
	 *     remaining_css: string,
	 *     lifted_rules: string[],
	 *     lifted_count: int
	 * }
	 */
	private static function audit_section_css( string $css, string $source_id, array $class_to_blocks ): array {
		$rules        = CssRuleSplitter::split( $css );
		$kept_rules   = [];
		$lifted_rules = [];

		foreach ( $rules as $rule ) {
			$trimmed = trim( $rule );
			if ( '' === $trimmed ) {
				continue;
			}

			// Skip at-rules wholesale (keyframes, media, font-face, supports). The LLM
			// is responsible for placing genuinely-shared at-rules under @page.
			if ( '@' === $trimmed[0] ) {
				$kept_rules[] = $rule;
				continue;
			}

			// Native CSS nesting and other unparseable shapes — leave in source.
			$brace_pos = strpos( $rule, '{' );
			if ( false === $brace_pos ) {
				$kept_rules[] = $rule;
				continue;
			}

			$selector_text = substr( $rule, 0, $brace_pos );
			$roots         = CssSelectorUtils::extract_root_selectors( $selector_text . ' { }' );

			if ( empty( $roots ) ) {
				// :root, type-only, or pseudo-only selectors — never lift candidates.
				$kept_rules[] = $rule;
				continue;
			}

			if ( self::should_lift( $roots, $source_id, $class_to_blocks ) ) {
				$lifted_rules[] = $trimmed;
			} else {
				$kept_rules[] = $rule;
			}
		}

		$remaining = trim( implode( '', $kept_rules ) );

		return [
			'remaining_css' => $remaining,
			'lifted_rules'  => $lifted_rules,
			'lifted_count'  => count( $lifted_rules ),
		];
	}

	/**
	 * Decide whether a rule should be lifted based on its root selectors.
	 *
	 * Lift only if EVERY root class is shared by 2+ sections. If any root
	 * is unique to the source section, the rule is treated as intentionally
	 * scoped (ancestor-uniqueness invariant — see header doc).
	 *
	 * @param string[]                               $roots
	 * @param string                                 $source_id
	 * @param array<string, array<int|string, true>> $class_to_blocks
	 * @return bool
	 */
	private static function should_lift( array $roots, string $source_id, array $class_to_blocks ): bool {
		foreach ( $roots as $root ) {
			$blocks = $class_to_blocks[ $root ] ?? [];
			$count  = count( $blocks );
			if ( $count < 2 ) {
				// Unique to source (or unused in any HTML) → intentionally scoped.
				return false;
			}
			if ( $count >= 2 && ! isset( $blocks[ $source_id ] ) ) {
				// Class is shared, but not by the source section itself. Treat as
				// intentionally scoped — the rule lives in a section that doesn't
				// even contain its target class, which suggests authorial intent
				// to style elsewhere via descendant or compound selectors.
				return false;
			}
		}
		return true;
	}
}
