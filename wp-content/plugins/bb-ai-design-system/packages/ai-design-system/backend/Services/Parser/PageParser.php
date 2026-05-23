<?php

namespace FL\DesignSystem\Services\Parser;

/**
 * Page Parser
 *
 * Parses a build agent's HTML output into structured data ready for import.
 * Pure static class — no side effects, no store access.
 *
 * Input: complete HTML document string from the build agent.
 * Output: ['designSystem' => ..., 'sections' => [...]] ready for block creation and DS storage.
 */
class PageParser {

	/**
	 * Parse a build agent's HTML output into structured data.
	 *
	 * @param string $html Complete HTML document from the build agent.
	 * @return array{
	 *     designSystem: array{tokens: string, reset: string, base: string, page: string, fonts: string[], baseJs: string, pageJs: string},
	 *     sections: array<int, array{id: string, label: string, tag: string, html: string, css: string, js: string}>,
	 *     warnings: string[],
	 *     diagnostic: ?string,
	 *     parseHealth: array{
	 *         state: string,
	 *         declaredSections: int,
	 *         matchedSections: int,
	 *         recoveredSections: int,
	 *         promotedSections: int,
	 *         orphanCssToPage: bool,
	 *         overClaimLifted: int,
	 *         discoveryTier: string,
	 *         markerless: bool,
	 *         warnings: string[],
	 *         diagnostic: ?string
	 *     }
	 * }
	 */
	public static function parse( string $html ): array {
		$warnings = [];

		$svg_preserver = new SvgPreserver();
		$html          = $svg_preserver->extract( $html );

		$doc = self::load_html( $html );

		// Extract and parse the stylesheet.
		$raw_css    = self::extract_head_styles( $doc );
		$parsed_css = CssSectionParser::parse( $raw_css );

		// Extract and parse <body> <style> siblings as their own pass, then
		// route the buckets back into the head-CSS pipeline (see merge_body_css).
		// Each <style> element is parsed independently — concatenating would let
		// markers from one element bleed into the next, attributing unmarked
		// content in a later <style> to a preceding marker's section.
		// This must run before the body-element scan below so removed <style>
		// elements don't flip body_has_non_semantic_elements.
		foreach ( self::extract_body_styles( $doc ) as $body_css_chunk ) {
			$body_parsed = CssSectionParser::parse( $body_css_chunk );
			self::merge_body_css( $parsed_css, $body_parsed, $warnings );
		}

		// Extract and parse inline JS.
		$raw_js    = self::extract_head_scripts( $doc );
		$parsed_js = JsSectionParser::parse( $raw_js );

		// Build case-insensitive lookup maps for section matching.
		// Preserve original-cased labels in parallel maps for diagnostic messages.
		$css_section_map     = [];
		$css_original_labels = [];
		foreach ( $parsed_css['sections'] as $section_label => $section_css ) {
			$trimmed = trim( $section_label );
			$key     = strtolower( $trimmed );

			$css_section_map[ $key ]     = $section_css;
			$css_original_labels[ $key ] = $trimmed;
		}

		$js_section_map     = [];
		$js_original_labels = [];
		foreach ( $parsed_js['sections'] as $section_label => $section_js ) {
			$trimmed = trim( $section_label );
			$key     = strtolower( $trimmed );

			$js_section_map[ $key ]     = $section_js;
			$js_original_labels[ $key ] = $trimmed;
		}

		// Track declared count BEFORE consumption for the marker oracle.
		$declared_count = count( $css_section_map );

		// Extract font families.
		$fonts = self::extract_fonts( $doc );

		$design_system = [
			'tokens' => $parsed_css['tokens'],
			'reset'  => $parsed_css['reset'],
			'base'   => $parsed_css['base'],
			'page'   => $parsed_css['page'],
			'fonts'  => $fonts,
			'baseJs' => $parsed_js['base'],
			'pageJs' => $parsed_js['page'],
		];

		// Extract top-level semantic elements from <body>.
		$sections                       = [];
		$body_list                      = $doc->getElementsByTagName( 'body' );
		$body_has_content               = false;
		$body_has_non_semantic_elements = false;
		$diagnostic                     = null;
		$matched_elements               = [];
		$promoted_sections              = 0;

		if ( $body_list->length > 0 ) {
			$body = $body_list->item( 0 );

			foreach ( $body->childNodes as $child ) {
				if ( $child instanceof \DOMElement ) {
					$body_has_content = true;
					$child_tag        = strtolower( $child->tagName );
					if ( ! in_array( $child_tag, [ 'header', 'section', 'footer' ], true ) ) {
						$body_has_non_semantic_elements = true;
					}
				} elseif ( $child instanceof \DOMComment ) {
					$comment = trim( $child->textContent );
					if ( preg_match( '/^(bb|wp):preserved\s+/', $comment ) ) {
						$body_has_content = true;
					}
				} elseif ( $child instanceof \DOMText && '' !== trim( $child->textContent ) ) {
					$body_has_content = true;
				}
			}

			// Preserved markers (bb:preserved / wp:preserved) — handled separately.
			foreach ( $body->childNodes as $child ) {
				if ( $child instanceof \DOMComment ) {
					$comment = trim( $child->textContent );
					if ( preg_match( '/^(bb|wp):preserved\s+/', $comment, $prefix_match ) ) {
						$preserved_type = $prefix_match[1];
						$attrs          = [];
						if ( preg_match_all( '/(\w+)="([^"]*)"/', $comment, $attr_matches, PREG_SET_ORDER ) ) {
							foreach ( $attr_matches as $m ) {
								$attrs[ $m[1] ] = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
							}
						}
						$sections[] = [
							'type'           => 'preserved',
							'preserved_type' => $preserved_type,
							'node_id'        => $attrs['node'] ?? '',
							'block_name'     => $attrs['type'] ?? '',
							'module_type'    => $attrs['type'] ?? '',
							'label'          => $attrs['label'] ?? '',
							'block_index'    => isset( $attrs['index'] ) ? (int) $attrs['index'] : null,
						];
					}
				}
			}

			// Discover sections via the gated rule (data-label OR id qualifies; descend
			// into non-qualifying wrappers up to depth 3).
			$discovered = SectionDiscovery::discover( $body, [ 'gated' => true ] );

			foreach ( $discovered as $entry ) {
				$child = $entry['element'];
				$tag   = $entry['tag'];
				$id    = $entry['id'];
				$label = $entry['label'];
				$depth = $entry['depth'];
				if ( $depth > 0 ) {
					$promoted_sections++;
				}

				$node_id = $child->getAttribute( 'data-node' ) ?: '';

				// Match CSS by label first, then by id (case-insensitive).
				$css         = '';
				$label_lower = strtolower( trim( $label ) );
				$id_lower    = strtolower( trim( $id ) );

				if ( '' !== $label_lower && isset( $css_section_map[ $label_lower ] ) ) {
					$css = $css_section_map[ $label_lower ];
					unset( $css_section_map[ $label_lower ] );
				} elseif ( '' !== $id_lower && isset( $css_section_map[ $id_lower ] ) ) {
					$css = $css_section_map[ $id_lower ];
					unset( $css_section_map[ $id_lower ] );
				} elseif ( '' !== $id_lower ) {
					// Tier 3: Slug-fallback on id (slugify both sides).
					$id_slug = self::to_slug( $id );
					foreach ( $css_section_map as $map_label => $map_css ) {
						if ( self::to_slug( $map_label ) === $id_slug ) {
							$css = $map_css;
							unset( $css_section_map[ $map_label ] );
							break;
						}
					}
				}

				// Tier 4: Slug-fallback on label (slugify both sides).
				if ( '' === $css && '' !== $label_lower ) {
					$label_slug = self::to_slug( $label );
					foreach ( $css_section_map as $map_label => $map_css ) {
						if ( self::to_slug( $map_label ) === $label_slug ) {
							$css = $map_css;
							unset( $css_section_map[ $map_label ] );
							break;
						}
					}
				}

				// Match JS by label first, then by id (case-insensitive).
				$js = '';
				if ( '' !== $label_lower && isset( $js_section_map[ $label_lower ] ) ) {
					$js = $js_section_map[ $label_lower ];
					unset( $js_section_map[ $label_lower ] );
				} elseif ( '' !== $id_lower && isset( $js_section_map[ $id_lower ] ) ) {
					$js = $js_section_map[ $id_lower ];
					unset( $js_section_map[ $id_lower ] );
				} elseif ( '' !== $id_lower ) {
					$id_slug = self::to_slug( $id );
					foreach ( $js_section_map as $map_label => $map_js ) {
						if ( self::to_slug( $map_label ) === $id_slug ) {
							$js = $map_js;
							unset( $js_section_map[ $map_label ] );
							break;
						}
					}
				}

				// Tier 4 for JS: Slug-fallback on label.
				if ( '' === $js && '' !== $label_lower ) {
					$label_slug = self::to_slug( $label );
					foreach ( $js_section_map as $map_label => $map_js ) {
						if ( self::to_slug( $map_label ) === $label_slug ) {
							$js = $map_js;
							unset( $js_section_map[ $map_label ] );
							break;
						}
					}
				}

				// Strip parser metadata attributes.
				$child->removeAttribute( 'data-label' );
				$child->removeAttribute( 'data-node' );

				$sections[] = [
					'id'      => $id,
					'label'   => $label,
					'tag'     => $tag,
					'html'    => $svg_preserver->restore( $doc->saveHTML( $child ) ),
					'css'     => $css,
					'js'      => $js,
					'node_id' => $node_id,
				];
				$matched_elements[ spl_object_hash( $child ) ] = true;
			}
		}

		// CSS-marker-count oracle: when declaredCount > matchedCount, search the
		// DOM for unmatched markers' root selectors and promote matches.
		$recovered_sections = 0;
		if ( $declared_count > 0 && ! empty( $css_section_map ) && $body_list->length > 0 ) {
			$promoted = self::run_css_marker_oracle( $doc, $css_section_map, $matched_elements );
			foreach ( $promoted as $entry ) {
				$el       = $entry['element'];
				$o_label  = $entry['label'];
				$o_css    = $entry['css'];
				$id       = $el->getAttribute( 'id' ) ?: '';
				$tag      = strtolower( $el->tagName );
				$el_label = $el->getAttribute( 'data-label' ) ?: ( '' !== $o_label ? $css_original_labels[ $o_label ] ?? $o_label : $id );
				$node_id  = $el->getAttribute( 'data-node' ) ?: '';

				// Match JS using the same label/id cascade.
				$js          = '';
				$label_lower = strtolower( trim( $el_label ) );
				$id_lower    = strtolower( trim( $id ) );
				if ( '' !== $label_lower && isset( $js_section_map[ $label_lower ] ) ) {
					$js = $js_section_map[ $label_lower ];
					unset( $js_section_map[ $label_lower ] );
				} elseif ( '' !== $id_lower && isset( $js_section_map[ $id_lower ] ) ) {
					$js = $js_section_map[ $id_lower ];
					unset( $js_section_map[ $id_lower ] );
				}

				$el->removeAttribute( 'data-label' );
				$el->removeAttribute( 'data-node' );

				$sections[] = [
					'id'      => $id,
					'label'   => $el_label,
					'tag'     => $tag,
					'html'    => $svg_preserver->restore( $doc->saveHTML( $el ) ),
					'css'     => $o_css,
					'js'      => $js,
					'node_id' => $node_id,
				];
				$matched_elements[ spl_object_hash( $el ) ] = true;
				$recovered_sections++;
			}
		}

		// Attempt selector-based recovery for orphan CSS.
		if ( ! empty( $css_section_map ) ) {
			self::recover_orphan_css( $css_section_map, $sections );
		}

		// Audit for over-claimed rules (shared classes mis-routed under one
		// @section marker) and lift them to page-level CSS. Symmetric
		// counterpart to orphan recovery — see CssOverClaimAuditor for the
		// cascade-safety proof.
		$over_claim_lifted = 0;
		if ( ! empty( $sections ) ) {
			$audit                  = CssOverClaimAuditor::audit( $sections, $design_system['page'] );
			$sections               = $audit['sections'];
			$design_system['page']  = $audit['page_css'];
			$over_claim_lifted      = $audit['lifted_count'];
		}

		// Any still-unmatched CSS moves to page-level as a safety net.
		$orphan_css_to_page = false;
		if ( ! empty( $css_section_map ) ) {
			$keys           = array_keys( $css_section_map );
			$display_labels = array_map( fn( $k ) => $css_original_labels[ $k ] ?? $k, $keys );
			$warnings[]     = sprintf(
				'CSS section %s "%s" did not match any element. Add data-label="%s" or id="%s" to a <header>, <section>, or <footer>, or rename the marker to match an existing one. Moved to page CSS as a fallback.',
				count( $display_labels ) === 1 ? 'marker' : 'markers',
				implode( '", "', $display_labels ),
				$display_labels[0],
				self::to_slug( $display_labels[0] )
			);
			$orphan_css = implode( "\n\n", array_values( $css_section_map ) );
			if ( '' !== $design_system['page'] ) {
				$design_system['page'] .= "\n\n" . $orphan_css;
			} else {
				$design_system['page'] = $orphan_css;
			}
			$orphan_css_to_page = true;
		}
		if ( ! empty( $js_section_map ) ) {
			$keys           = array_keys( $js_section_map );
			$display_labels = array_map( fn( $k ) => $js_original_labels[ $k ] ?? $k, $keys );
			$warnings[]     = sprintf(
				'JS section %s "%s" did not match any element and was discarded. Add data-label="%s" or id="%s" to a <header>, <section>, or <footer>, or rename the marker to match an existing one.',
				count( $display_labels ) === 1 ? 'marker' : 'markers',
				implode( '", "', $display_labels ),
				$display_labels[0],
				self::to_slug( $display_labels[0] )
			);
		}

		// Compose a diagnostic when no sections were found, to help the agent self-correct.
		// Only true sections (not preserved markers) trigger this branch.
		$has_ds_sections = false;
		foreach ( $sections as $s ) {
			if ( ( $s['type'] ?? '' ) !== 'preserved' ) {
				$has_ds_sections = true;
				break;
			}
		}
		if ( ! $has_ds_sections ) {
			if ( 0 === $body_list->length ) {
				$diagnostic = 'No <body> element was found in the HTML document. Wrap your content in <html><body>...</body></html>.';
			} elseif ( ! $body_has_content ) {
				$diagnostic = 'The <body> element is empty. Add at least one <header>, <section>, or <footer> containing your content.';
			} elseif ( $body_has_non_semantic_elements ) {
				$diagnostic = 'No top-level <header>, <section>, or <footer> elements were found in the <body>. Wrap your content in <section> elements (one per page section). Plain <div>, <main>, <article>, etc. at the body level are not recognized as sections.';
			} else {
				$diagnostic = 'No top-level <header>, <section>, or <footer> elements were found in the <body>.';
			}
		}

		// Compute parseHealth signal.
		$ds_section_count = 0;
		foreach ( $sections as $s ) {
			if ( ( $s['type'] ?? '' ) !== 'preserved' ) {
				$ds_section_count++;
			}
		}
		$parse_health = self::compute_parse_health(
			$declared_count,
			$ds_section_count,
			$recovered_sections,
			$promoted_sections,
			$orphan_css_to_page,
			$over_claim_lifted,
			$body_has_content,
			$warnings,
			$diagnostic
		);

		return [
			'designSystem' => $design_system,
			'sections'     => $sections,
			'warnings'     => $warnings,
			'diagnostic'   => $diagnostic,
			'parseHealth'  => $parse_health,
		];
	}

	/**
	 * Compute the parseHealth signal.
	 *
	 * @param int      $declared_count
	 * @param int      $matched_count
	 * @param int      $recovered_sections
	 * @param int      $promoted_sections
	 * @param bool     $orphan_css_to_page
	 * @param bool     $body_has_children
	 * @param string[] $warnings
	 * @param ?string  $existing_diagnostic
	 * @return array
	 */
	private static function compute_parse_health(
		int $declared_count,
		int $matched_count,
		int $recovered_sections,
		int $promoted_sections,
		bool $orphan_css_to_page,
		int $over_claim_lifted,
		bool $body_has_children,
		array $warnings,
		?string $existing_diagnostic
	): array {
		$markerless = 0 === $declared_count;

		$discovery_tier = 'strict';
		if ( $recovered_sections > 0 ) {
			$discovery_tier = 'marker-oracle';
		} elseif ( $promoted_sections > 0 ) {
			$discovery_tier = 'descent';
		}

		if ( $body_has_children && 0 === $matched_count && $declared_count > 0 ) {
			$state = 'broken';
		} elseif ( $body_has_children && 0 === $matched_count && $markerless ) {
			$state = 'broken';
		} elseif ( ! $markerless && 0 === $matched_count ) {
			$state = 'broken';
		} elseif (
			'strict' === $discovery_tier &&
			! $orphan_css_to_page &&
			( $markerless ? $matched_count > 0 : $matched_count === $declared_count )
		) {
			$state = 'healthy';
		} elseif ( 0 === $matched_count ) {
			$state = 'broken';
		} else {
			$state = 'degraded';
		}

		// Health-specific diagnostic; preserves the existing branch-specific
		// message (which is more actionable for empty/no-body cases).
		$diag = $existing_diagnostic;
		if ( null === $diag ) {
			if ( 'broken' === $state ) {
				$diag = 'No sections detected. Check that <body> contains <header>, <section>, or <footer> elements with data-label attributes.';
			} elseif ( 'descent' === $discovery_tier ) {
				$diag = 'Found sections inside a wrapper element. AI should put sections as direct children of <body>.';
			} elseif ( 'marker-oracle' === $discovery_tier ) {
				$diag = sprintf(
					'Some CSS section markers had no matching HTML; promoted %d element(s) via class/id matching.',
					$recovered_sections
				);
			} elseif ( $orphan_css_to_page ) {
				$diag = 'Some CSS section markers had no matching HTML and were moved to page-level CSS.';
			}
		}

		return [
			'state'             => $state,
			'declaredSections'  => $declared_count,
			'matchedSections'   => $matched_count,
			'recoveredSections' => $recovered_sections,
			'promotedSections'  => $promoted_sections,
			'orphanCssToPage'   => $orphan_css_to_page,
			'overClaimLifted'   => $over_claim_lifted,
			'discoveryTier'     => $discovery_tier,
			'markerless'        => $markerless,
			'warnings'          => $warnings,
			'diagnostic'        => $diag,
		];
	}

	/**
	 * CSS-marker-count oracle. For each unmatched CSS section marker, derive
	 * root selectors and look up matching DOM elements. Returns elements to
	 * promote (one per marker, deduped against already-matched and previously-claimed).
	 *
	 * @param \DOMDocument $doc
	 * @param array        $css_section_map  Remaining unmatched markers (passed by reference; promoted markers are removed).
	 * @param array        $matched_elements Map of spl_object_hash => true for already-claimed elements.
	 * @return array<int, array{element: \DOMElement, label: string, css: string}>
	 */
	private static function run_css_marker_oracle(
		\DOMDocument $doc,
		array &$css_section_map,
		array $matched_elements
	): array {
		$xpath    = new \DOMXPath( $doc );
		$promoted = [];
		$claimed  = $matched_elements;

		// Iterate a copy because we mutate $css_section_map.
		foreach ( array_keys( $css_section_map ) as $label ) {
			$css   = $css_section_map[ $label ];
			$roots = CssSelectorUtils::extract_root_selectors( $css );
			if ( empty( $roots ) ) {
				continue;
			}

			$candidate = null;
			foreach ( $roots as $root ) {
				// Try id selector.
				$by_id = $doc->getElementById( $root );
				if (
					$by_id instanceof \DOMElement &&
					! isset( $claimed[ spl_object_hash( $by_id ) ] )
				) {
					$candidate = $by_id;
					break;
				}
				// Try class selector via XPath.
				$matches = $xpath->query(
					sprintf(
						'//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]',
						addslashes( $root )
					)
				);
				if ( $matches && $matches->length > 0 ) {
					foreach ( $matches as $m ) {
						if ( $m instanceof \DOMElement && ! isset( $claimed[ spl_object_hash( $m ) ] ) ) {
							$candidate = $m;
							break 2;
						}
					}
				}
			}

			if ( $candidate instanceof \DOMElement ) {
				$promoted[] = [
					'element' => $candidate,
					'label'   => $label,
					'css'     => $css,
				];
				$claimed[ spl_object_hash( $candidate ) ] = true;
				unset( $css_section_map[ $label ] );
			}
		}

		return $promoted;
	}

	/**
	 * Reassemble parsed design system sections into a single comment-marked CSS string.
	 *
	 * @param array $ds Design system array with tokens, reset, base, page keys.
	 * @return string Comment-marked CSS string.
	 */
	public static function buildDesignSystemCss( array $ds ): string {
		$parts = [];

		if ( ! empty( $ds['tokens'] ) ) {
			$parts[] = "/* Tokens */\n" . $ds['tokens'];
		}
		if ( ! empty( $ds['reset'] ) ) {
			$parts[] = "/* Reset */\n" . $ds['reset'];
		}
		if ( ! empty( $ds['base'] ) ) {
			$parts[] = "/* Base */\n" . $ds['base'];
		}
		if ( ! empty( $ds['page'] ) ) {
			$parts[] = "/* Page */\n" . $ds['page'];
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Convert a string to a kebab-case slug for normalized matching.
	 *
	 * @param string $str e.g., "Feature Grid" or "feature-grid".
	 * @return string Kebab-case slug, e.g., "feature-grid".
	 */
	private static function to_slug( string $str ): string {
		$slug = strtolower( $str );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );
		return trim( $slug, '-' );
	}

	/**
	 * Attempt to match orphan CSS sections to HTML sections that have no CSS,
	 * using selector-based matching. Matches when any root selector overlaps
	 * with a section's identifiers. Section class names are specific enough
	 * that any match is a strong signal; utility classes are already excluded.
	 *
	 * @param array  $css_section_map Remaining unmatched CSS sections (passed by reference).
	 * @param array  $sections        Parsed sections array (passed by reference, css field may be updated).
	 */
	private static function recover_orphan_css( array &$css_section_map, array &$sections ): void {
		foreach ( $sections as &$section ) {
			if ( ! empty( $section['css'] ) || 'preserved' === ( $section['type'] ?? '' ) ) {
				continue;
			}

			$identifiers = [];
			if ( ! empty( $section['id'] ) ) {
				$identifiers[] = strtolower( $section['id'] );
			}
			// Extract class names from section HTML.
			if ( preg_match( '/\bclass=["\']([^"\']*)["\']/', $section['html'], $class_match ) ) {
				foreach ( preg_split( '/\s+/', $class_match[1] ) as $cls ) {
					if ( '' !== $cls ) {
						$identifiers[] = strtolower( $cls );
					}
				}
			}
			if ( empty( $identifiers ) ) {
				continue;
			}

			$identifier_set = array_flip( $identifiers );
			$best_match     = null;
			$best_overlap   = 0;

			foreach ( $css_section_map as $label => $css ) {
				$roots = CssSelectorUtils::extract_root_selectors( $css );
				if ( empty( $roots ) ) {
					continue;
				}
				$overlap = 0;
				foreach ( $roots as $root ) {
					if ( isset( $identifier_set[ $root ] ) ) {
						$overlap++;
					}
				}

				// Any overlap is a strong signal — section class names are specific,
				// and utility classes are already filtered from roots.
				if ( $overlap > 0 && $overlap > $best_overlap ) {
					$best_match   = $label;
					$best_overlap = $overlap;
				}
			}

			if ( null !== $best_match ) {
				$section['css'] = $css_section_map[ $best_match ];
				unset( $css_section_map[ $best_match ] );
			}
		}
		unset( $section );
	}

	/**
	 * Load an HTML string into a DOMDocument with HTML5 tolerance.
	 *
	 * @param string $html Raw HTML string.
	 * @return \DOMDocument Parsed document.
	 */
	private static function load_html( string $html ): \DOMDocument {
		$doc = new \DOMDocument();

		// Suppress warnings for HTML5 tags that libxml doesn't understand.
		$prev = libxml_use_internal_errors( true );

		// Ensure the document has a proper wrapper for DOMDocument.
		if ( false === stripos( $html, '<html' ) ) {
			$html = '<html><head></head><body>' . $html . '</body></html>';
		}

		$doc->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $doc;
	}

	/**
	 * Extract concatenated CSS from all <style> elements in <head>.
	 *
	 * @param \DOMDocument $doc Parsed document.
	 * @return string Concatenated CSS content.
	 */
	private static function extract_head_styles( \DOMDocument $doc ): string {
		$parts    = [];
		$head_els = $doc->getElementsByTagName( 'head' );

		if ( 0 === $head_els->length ) {
			return '';
		}

		$head   = $head_els->item( 0 );
		$styles = $head->getElementsByTagName( 'style' );

		foreach ( $styles as $style ) {
			$content = trim( $style->textContent );
			if ( '' !== $content ) {
				$parts[] = $content;
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Extract CSS from <style> elements inside <body>, one entry per element.
	 *
	 * Returns a list so each <style> can be parsed independently — markers in one
	 * element must not bleed into the next.
	 *
	 * Removes the extracted <style> elements from the DOM so they don't end up
	 * in the body-content scan or get rendered twice. Skips <style> elements
	 * that are descendants of <section>, <header>, or <footer> — those are the
	 * section's own children, not body-level siblings.
	 *
	 * @param \DOMDocument $doc Parsed document.
	 * @return string[] CSS content from body <style> siblings, in document order.
	 */
	private static function extract_body_styles( \DOMDocument $doc ): array {
		$body_list = $doc->getElementsByTagName( 'body' );
		if ( 0 === $body_list->length ) {
			return [];
		}

		$body = $body_list->item( 0 );

		// Snapshot candidates before mutating the tree.
		$candidates = [];
		foreach ( $body->getElementsByTagName( 'style' ) as $style ) {
			if ( self::has_section_ancestor( $style, $body ) ) {
				continue;
			}
			$candidates[] = $style;
		}

		$parts = [];
		foreach ( $candidates as $style ) {
			$content = trim( $style->textContent );
			if ( '' !== $content ) {
				$parts[] = $content;
			}
			if ( $style->parentNode ) {
				$style->parentNode->removeChild( $style );
			}
		}

		return $parts;
	}

	/**
	 * Check whether a node has a <section>, <header>, or <footer> ancestor
	 * before reaching $body.
	 *
	 * @param \DOMNode    $node Starting node.
	 * @param \DOMElement $body Body element to stop the walk at.
	 */
	private static function has_section_ancestor( \DOMNode $node, \DOMElement $body ): bool {
		$section_tags = [ 'section', 'header', 'footer' ];
		$cur          = $node->parentNode;
		while ( $cur && $cur !== $body ) {
			if (
				$cur instanceof \DOMElement &&
				in_array( strtolower( $cur->tagName ), $section_tags, true )
			) {
				return true;
			}
			$cur = $cur->parentNode;
		}
		return false;
	}

	/**
	 * Merge body-CSS parse buckets back into the head-CSS parse result, recording
	 * warnings for the buckets that don't map cleanly.
	 *
	 * Routing:
	 *  - tokens / reset  → dropped (head-only buckets), warning emitted
	 *  - base            → appended to page (unmarked CSS bucket), warning emitted
	 *  - page            → appended to page (documented escape hatch, no warning)
	 *  - sections        → merged into head sections; head-first on key collision
	 *
	 * @param array    $parsed_css  Head-CSS parse result (mutated).
	 * @param array    $body_parsed Body-CSS parse result.
	 * @param string[] $warnings    Warning bag (mutated).
	 */
	private static function merge_body_css( array &$parsed_css, array $body_parsed, array &$warnings ): void {
		if ( '' !== $body_parsed['tokens'] || '' !== $body_parsed['reset'] ) {
			$msg = '<style> siblings inside <body> contained /* @tokens */ or /* @reset */ markers. Those are only valid in head CSS and were ignored. Move design-system tokens and resets into a <head><style> block.';
			if ( ! in_array( $msg, $warnings, true ) ) {
				$warnings[] = $msg;
			}
		}

		if ( '' !== $body_parsed['base'] ) {
			$parsed_css['page'] = '' !== $parsed_css['page']
				? $parsed_css['page'] . "\n\n" . $body_parsed['base']
				: $body_parsed['base'];
			$msg                = '<style> siblings inside <body> had unmarked CSS. Moved to page CSS. To attach the rules to a specific section, prefix them with /* @section [Label] */.';
			if ( ! in_array( $msg, $warnings, true ) ) {
				$warnings[] = $msg;
			}
		}

		if ( '' !== $body_parsed['page'] ) {
			$parsed_css['page'] = '' !== $parsed_css['page']
				? $parsed_css['page'] . "\n\n" . $body_parsed['page']
				: $body_parsed['page'];
		}

		foreach ( $body_parsed['sections'] as $label => $css ) {
			if ( isset( $parsed_css['sections'][ $label ] ) && '' !== $parsed_css['sections'][ $label ] ) {
				$parsed_css['sections'][ $label ] .= "\n\n" . $css;
			} else {
				$parsed_css['sections'][ $label ] = $css;
			}
		}
	}

	/**
	 * Extract concatenated JavaScript from inline <script> elements in <head>.
	 * Only includes inline scripts (no src attribute).
	 *
	 * @param \DOMDocument $doc Parsed document.
	 * @return string Concatenated script content.
	 */
	private static function extract_head_scripts( \DOMDocument $doc ): string {
		$parts    = [];
		$head_els = $doc->getElementsByTagName( 'head' );

		if ( 0 === $head_els->length ) {
			return '';
		}

		$head    = $head_els->item( 0 );
		$scripts = $head->getElementsByTagName( 'script' );

		foreach ( $scripts as $script ) {
			// Skip external scripts.
			if ( $script->hasAttribute( 'src' ) ) {
				continue;
			}

			$content = trim( $script->textContent );
			if ( '' !== $content ) {
				$parts[] = $content;
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Extract Google Font families (with variants) from <link> elements.
	 *
	 * Returns a list of `{family, variants}` entries where `variants` is the
	 * opaque string that followed the colon in the Google Fonts URL (or an
	 * empty string when no variant segment was present). Duplicate families
	 * are merged, keeping the first non-empty variants value encountered —
	 * later duplicates with a variants string overwrite an earlier empty
	 * entry so the authoritative spec wins.
	 *
	 * @param \DOMDocument $doc Parsed document.
	 * @return array<int, array{family: string, variants: string}>
	 */
	public static function extract_fonts( \DOMDocument $doc ): array {
		$entries = [];
		$links   = $doc->getElementsByTagName( 'link' );

		foreach ( $links as $link ) {
			$href = $link->getAttribute( 'href' );
			if ( false === strpos( $href, 'fonts.googleapis.com' ) ) {
				continue;
			}

			self::accumulate_font_entries_from_href( $href, $entries );
		}

		return array_values( $entries );
	}

	/**
	 * Accumulate font entries from a single Google Fonts URL into the running map.
	 *
	 * Splits each `family=<value>` on the first `:` — everything before is the
	 * family name, everything after is the opaque variants spec.
	 *
	 * @param string $href    Google Fonts URL.
	 * @param array  $entries Running `[family => entry]` map (passed by reference).
	 */
	private static function accumulate_font_entries_from_href( string $href, array &$entries ): void {
		// Match each family=<value> (value terminated by `&` or end of string).
		if ( ! preg_match_all( '/family=([^&]+)/i', $href, $matches ) ) {
			return;
		}

		foreach ( $matches[1] as $raw_value ) {
			$colon_pos = strpos( $raw_value, ':' );
			if ( false === $colon_pos ) {
				$raw_family   = $raw_value;
				$raw_variants = '';
			} else {
				$raw_family   = substr( $raw_value, 0, $colon_pos );
				$raw_variants = substr( $raw_value, $colon_pos + 1 );
			}

			$family = trim( str_replace( '+', ' ', urldecode( $raw_family ) ) );
			if ( '' === $family ) {
				continue;
			}

			$variants = trim( $raw_variants );

			if ( ! isset( $entries[ $family ] ) || ( '' === $entries[ $family ]['variants'] && '' !== $variants ) ) {
				$entries[ $family ] = [
					'family'   => $family,
					'variants' => $variants,
				];
			}
		}
	}
}
