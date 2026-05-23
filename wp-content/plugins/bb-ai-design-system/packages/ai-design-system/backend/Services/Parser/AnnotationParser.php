<?php

namespace FL\DesignSystem\Services\Parser;

use FL\DesignSystem\Services\Parser\AutoAnnotateGaps\Keys;

/**
 * Annotation Parser
 *
 * Converts annotated HTML (with data-field, data-repeater, etc.) into
 * a Mustache template and settings array. The LLM generates standard HTML
 * with data-field attributes; this parser handles the mechanical conversion.
 */
class AnnotationParser {

	/**
	 * Annotation data attributes to remove after processing.
	 */
	private const DATA_ATTRS = [
		'data-field',
		'data-field-type',
		'data-field-href',
		'data-field-max',
		'data-repeater',
		'data-repeater-item',
		'data-variation',
	];

	/**
	 * Acronyms that should be fully uppercased in labels.
	 */
	private const ACRONYMS = [ 'cta', 'url', 'svg', 'html', 'css', 'api', 'id', 'faq' ];

	/**
	 * Placeholder prefix used in attributes to avoid DOMDocument URL-encoding.
	 *
	 * DOMDocument encodes curly braces in href/src attributes. We use
	 * alphanumeric placeholders during DOM manipulation, then replace
	 * them with Mustache tokens during serialization.
	 */
	private const PH_OPEN     = 'MSTCH2OPEN';
	private const PH_CLOSE    = 'MSTCH2CLOSE';
	private const PH_OPEN3    = 'MSTCH3OPEN';
	private const PH_CLOSE3   = 'MSTCH3CLOSE';
	private const PH_SECT_O   = 'MSTCHsOPEN';
	private const PH_SECT_C   = 'MSTCHsCLOSE';

	/**
	 * Per-parse map of rating SVG placeholders to their pre-captured HTML strings.
	 *
	 * Rating active/inactive children are captured as strings via getChildOuterHTML
	 * (which routes <svg> through serializeSvg) so that DOMDocument's HTML4 parser
	 * never lowercases attributes or expands self-closing SVG elements during the
	 * final saveHTML pass. Substituted into the serialized template string.
	 *
	 * @var array<string, string>
	 */
	private static array $ratingSvgPlaceholders = [];

	/**
	 * Monotonic counter for rating placeholder tokens. Reset on each parse().
	 *
	 * @var int
	 */
	private static int $ratingSvgCounter = 0;

	/**
	 * Active SvgPreserver for the current parse() call. Lets rating helpers
	 * resolve comment placeholders back to their original SVG markup so
	 * decorative SVGs inside rating containers can still be classified and
	 * captured. Reset on each parse().
	 *
	 * @var SvgPreserver|null
	 */
	private static ?SvgPreserver $svgPreserver = null;

	/**
	 * Parse annotated HTML into a Mustache template and settings array.
	 *
	 * If $options['css'] is supplied, also promotes qualifying
	 * `background-image` URLs into image fields (see decision 001).
	 * The returned array then also includes a 'css' entry with rewritten CSS.
	 *
	 * @param string $html    Annotated HTML string with data-field attributes.
	 * @param array  $options {
	 *     @type string $css              CSS to scan for background-image content.
	 *     @type array  $existingSettings Preexisting settings for idempotent re-parse.
	 * }
	 * @return array{template: string, settings: array, meta: array{autoAnnotatedCount: int, orphanedKeys: array}, css?: string}
	 */
	public static function parse( string $html, array $options = [] ): array {
		self::$ratingSvgPlaceholders = [];
		self::$ratingSvgCounter      = 0;

		// Extract decorative (non-annotated) SVGs before DOMDocument sees them.
		// DOMDocument's HTML4 parser lowercases SVG-specific attributes (viewBox →
		// viewbox) and re-emits self-closing tags as paired ones (<path /> →
		// <path></path>). Annotated SVGs (data-field, data-field-type,
		// data-field-href) are left in place so processSvgField / processField
		// can still see and route them through serializeSvg.
		$svg_preserver       = new SvgPreserver();
		$html                = $svg_preserver->extract( $html, true );
		self::$svgPreserver  = $svg_preserver;

		$doc = new \DOMDocument();

		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>'
		);
		libxml_clear_errors();

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );

		if ( null === $body ) {
			return [
				'template' => '',
				'settings' => [],
				'meta'     => [ 'autoAnnotatedCount' => 0, 'orphanedKeys' => [] ],
			];
		}

		$settings = [];

		self::rescueOrphanedRepeaterItems( $body );
		$global_shared_classes = self::computeGlobalSharedClasses( $body );

		// Auto-annotate elements the agent missed. Runs *after* orphan rescue
		// (rescue inspects only data-repeater-item; auto-annotate needs the
		// promoted parent in place so first-item key plans propagate correctly)
		// and *before* processNode so downstream parsing sees a fully-annotated
		// tree. See plan: auto-annotate-fallback.md.
		//
		// AutoAnnotateGaps returns a structured record: a count plus an
		// `svgEmissionPlan` of placeholder-comment decisions. The plan is
		// executed in two stages below (DOM mutation + settings writes) so
		// the synthetic SVG fields match the JS runtime byte-for-byte. See
		// plan: svg-auto-annotation.md.
		$auto_result          = AutoAnnotateGaps::apply( $body, $svg_preserver );
		$auto_annotated_count = $auto_result['count'];

		$existing_settings = isset( $options['existingSettings'] ) && is_array( $options['existingSettings'] )
			? $options['existingSettings']
			: [];

		$css_result = null;
		$has_css    = array_key_exists( 'css', $options ) && is_string( $options['css'] );
		if ( $has_css ) {
			$css_result = CssContentFields::rewrite( $options['css'], $body, $existing_settings );
			foreach ( $css_result['settings'] as $key => $value ) {
				if ( ! isset( $settings[ $key ] ) ) {
					$settings[ $key ] = $value;
				}
			}
		}

		// DOM-only inline-style tokenizer (see plan css-background-roundtrip-tokenizer).
		// Runs unconditionally so JS-saved templates round-trip cleanly even
		// on PHP callsites that skip the css option. Per-item URLs are keyed
		// by `${repeaterKey}|${itemIndex}|${fieldKey}` because items detach
		// from the DOM during processRepeater — element-keyed maps would
		// lose their handles before the patch loop reads them.
		$tokenize_result = CssContentFields::tokenize_inline_styles( $body, $existing_settings );
		foreach ( $tokenize_result['topLevelSettings'] as $key => $value ) {
			// Last-write-wins on the URL: the inline style is the round-trip
			// authority for what the user/LLM most recently saw.
			$settings[ $key ] = $value;
		}

		// Execute the top-level SVG emission plan from AutoAnnotateGaps:
		//   Stage 1 — replace each placeholder comment with a Mustache-token
		//             text node so the serialized template references the new
		//             field key.
		//   Stage 2 — synthesize the SVG bytes via svgBytesFor() and write
		//             them into top-level settings, byte-aligned with the JS
		//             runtime's `normalizeSelfClosingSvg(el.outerHTML)` path.
		self::executeTopLevelSvgEmissionPlan( $auto_result['svgEmissionPlan'], $body, $svg_preserver, $settings );

		// Repeater-scoped Stage 1 — replace placeholder comments inside each
		// repeater item with Mustache tokens (no `settings.` prefix because
		// these resolve against the per-item context). Stage 2 runs *after*
		// processNode so it can merge into the item arrays processRepeater
		// builds. See svg-auto-annotation plan, Phase 2.
		$repeater_svg_plan = $auto_result['svgRepeaterEmissionPlan'] ?? [];
		self::executeRepeaterSvgEmissionPlanStage1( $repeater_svg_plan, $body );

		// Top-level `used_keys` set — keys claimed at the top-level annotation
		// scope, used by `processLinkField` to disambiguate auto-generated
		// icon keys against the surrounding document. Built fresh from the
		// DOM after `AutoAnnotateGaps::apply` so it also catches keys the
		// auto-pass added. Per-item sets are rebuilt inside `processRepeater`.
		$used_keys = Keys::collectExistingKeys( $body, /* include_repeater_items */ false );

		self::processNode( $body, $settings, false, $global_shared_classes, $used_keys, $svg_preserver );

		self::executeRepeaterSvgEmissionPlanStage2( $repeater_svg_plan, $svg_preserver, $settings );

		// Patch per-item settings for repeater-scoped CSS fields. processRepeater
		// builds settings[repeaterKey] as an array of item objects; each item
		// needs its own default for the CSS-derived key. Prefer the tokenizer's
		// per-item URL when it has one (preserves item-by-item variation across
		// round-trips); fall back to the CSS rewriter's defaultUrl on first
		// import when no inline-style declaration exists yet.
		if ( $css_result ) {
			foreach ( $css_result['fields'] as $field ) {
				if ( empty( $field['repeaterKey'] ) ) {
					continue;
				}
				if ( ! isset( $settings[ $field['repeaterKey'] ] ) || ! is_array( $settings[ $field['repeaterKey'] ] ) ) {
					continue;
				}
				foreach ( $settings[ $field['repeaterKey'] ] as $i => &$item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					if ( isset( $item[ $field['key'] ] ) ) {
						continue;
					}
					$per_item_key = $field['repeaterKey'] . '|' . $i . '|' . $field['key'];
					$url          = isset( $tokenize_result['perItemUrls'][ $per_item_key ] )
						? $tokenize_result['perItemUrls'][ $per_item_key ]['url']
						: $field['defaultUrl'];
					$item[ $field['key'] ] = [
						'url'  => $url,
						'alt'  => '',
						'id'   => null,
						'size' => null,
					];
				}
				unset( $item );
			}
		}

		$template = self::serializeTemplate( $body );
		$template = $svg_preserver->restore( $template );

		// Settings strings can also hold placeholders when an annotated wrapper
		// (e.g. data-field-type="svg") captures inner HTML that referenced an
		// extracted SVG. Walk the settings tree once to restore every value.
		self::restorePlaceholdersInSettings( $settings, $svg_preserver );

		// Top-level orphaned keys: keys that existed in existingSettings but
		// did not survive this parse. Top-level only (per plan Decisions);
		// repeater-item-scoped orphans are not surfaced in v1.
		$orphaned_keys = array_values(
			array_diff( array_keys( $existing_settings ), array_keys( $settings ) )
		);

		$result = [
			'template' => $template,
			'settings' => $settings,
			'meta'     => [
				'autoAnnotatedCount' => $auto_annotated_count,
				'orphanedKeys'       => $orphaned_keys,
			],
		];
		if ( $has_css ) {
			$result['css'] = $css_result ? $css_result['css'] : $options['css'];
		}

		self::$svgPreserver = null;

		return $result;
	}

	/**
	 * Walk a settings array (potentially nested via repeaters) and run the
	 * SvgPreserver restore pass on every string value, so SVG placeholders
	 * captured into per-field markup are swapped back to the original SVG.
	 *
	 * @param array         $settings  Settings array (passed by reference).
	 * @param SvgPreserver  $preserver Preserver holding the extracted SVGs.
	 */
	private static function restorePlaceholdersInSettings( array &$settings, SvgPreserver $preserver ): void {
		foreach ( $settings as $key => &$value ) {
			if ( is_string( $value ) ) {
				if ( false !== strpos( $value, '<!--__BB_SVG_' ) ) {
					$value = $preserver->restore( $value );
				}
			} elseif ( is_array( $value ) ) {
				self::restorePlaceholdersInSettings( $value, $preserver );
			}
		}
		unset( $value );
	}

	/**
	 * Convert a snake_case or kebab-case key to a human-readable label.
	 *
	 * @param string $key The field key.
	 * @return string The formatted label.
	 */
	public static function formatLabel( string $key ): string {
		$words = preg_split( '/[_\-]/', $key );

		$formatted = array_map( function ( $word ) {
			if ( in_array( strtolower( $word ), self::ACRONYMS, true ) ) {
				return strtoupper( $word );
			}
			return ucfirst( $word );
		}, $words );

		return implode( ' ', $formatted );
	}

	/**
	 * Naive singularization: strips trailing 's' for button labels.
	 *
	 * @param string $word The word to singularize.
	 * @return string
	 */
	public static function singularize( string $word ): string {
		if ( str_ends_with( $word, 'ies' ) ) {
			return substr( $word, 0, -3 ) . 'y';
		}
		if ( str_ends_with( $word, 'ses' ) || str_ends_with( $word, 'xes' ) || str_ends_with( $word, 'zes' ) ) {
			return substr( $word, 0, -2 );
		}
		if ( str_ends_with( $word, 's' ) && ! str_ends_with( $word, 'ss' ) ) {
			return substr( $word, 0, -1 );
		}
		return $word;
	}

	// ─── Orphaned Repeater Recovery ─────────────────────────────────────

	/**
	 * Pre-processing step: rescues orphaned data-repeater-item elements
	 * by promoting their parent to a data-repeater container.
	 *
	 * Only handles the sibling case (items sharing the same parent).
	 *
	 * @param \DOMNode $root The root element to scan.
	 */
	private static function rescueOrphanedRepeaterItems( \DOMNode $root ): void {
		$doc   = $root->ownerDocument;
		$xpath = new \DOMXPath( $doc );

		$all_items = $xpath->query( './/*[@data-repeater-item]', $root );

		$orphans = [];
		foreach ( $all_items as $item ) {
			// Check if any ancestor (up to root) has data-repeater.
			$parent                = $item->parentNode;
			$has_repeater_ancestor = false;
			while ( $parent && $parent !== $root ) {
				if ( $parent instanceof \DOMElement && $parent->hasAttribute( 'data-repeater' ) ) {
					$has_repeater_ancestor = true;
					break;
				}
				$parent = $parent->parentNode;
			}
			if ( ! $has_repeater_ancestor ) {
				$orphans[] = $item;
			}
		}

		if ( empty( $orphans ) ) {
			return;
		}

		// Group by parent element.
		$groups = new \SplObjectStorage();
		foreach ( $orphans as $item ) {
			$parent = $item->parentNode;
			if ( ! $parent instanceof \DOMElement ) {
				continue;
			}
			if ( ! $groups->contains( $parent ) ) {
				$groups->attach( $parent, [] );
			}
			$items            = $groups[ $parent ];
			$items[]          = $item;
			$groups[ $parent ] = $items;
		}

		// Promote each parent.
		foreach ( $groups as $parent ) {
			$key = self::inferRepeaterKey( $parent );
			$parent->setAttribute( 'data-repeater', $key );
		}
	}

	/**
	 * Infer a repeater key from the parent element's CSS class name.
	 *
	 * @param \DOMElement $parent The parent element.
	 * @return string The inferred repeater key.
	 */
	private static function inferRepeaterKey( \DOMElement $parent ): string {
		$class_attr = $parent->getAttribute( 'class' );
		if ( empty( $class_attr ) ) {
			return 'items';
		}

		$classes = preg_split( '/\s+/', trim( $class_attr ) );
		foreach ( $classes as $cls ) {
			// Skip framework/utility prefixes.
			if ( preg_match( '/^(fl-|bb-|js-|is-|has-)/', $cls ) ) {
				continue;
			}
			// Strip common layout suffixes.
			$name = preg_replace( '/[-_]?(list|grid|container|wrapper|row|items|group|actions|nav|menu)$/i', '', $cls );
			if ( ! empty( $name ) && strlen( $name ) > 1 ) {
				return str_replace( '-', '_', $name );
			}
			// Class IS the layout word.
			return str_replace( '-', '_', $cls );
		}

		return 'items';
	}

	// ─── DOM Walking ────────────────────────────────────────────────────

	/**
	 * Recursively process DOM nodes, extracting field annotations.
	 *
	 * @param \DOMNode          $node                  The current DOM node.
	 * @param array             $settings              Settings array (passed by reference).
	 * @param bool              $inside_repeater       Whether we are inside a repeater.
	 * @param array|null        $global_shared_classes Cross-instance shared-class map keyed by repeater key.
	 * @param array             $used_keys             Map of claimed keys in the current annotation scope (mutated).
	 * @param SvgPreserver|null $svg_preserver         Preserver for resolving SVG placeholder comments.
	 */
	private static function processNode(
		\DOMNode $node,
		array &$settings,
		bool $inside_repeater,
		?array $global_shared_classes = null,
		array &$used_keys = [],
		?SvgPreserver $svg_preserver = null
	): void {
		$children = [];
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}

		foreach ( $children as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}

			if ( $child->hasAttribute( 'data-repeater' ) ) {
				self::processRepeater( $child, $settings, $inside_repeater, $global_shared_classes, $svg_preserver );
				continue;
			}

			if ( $child->hasAttribute( 'data-field' ) ) {
				self::processField( $child, $settings, $inside_repeater, $used_keys, $svg_preserver );
				continue;
			}

			if ( $child->hasAttribute( 'data-field-href' ) ) {
				self::processHrefOnlyField( $child, $settings, $inside_repeater, $global_shared_classes, $used_keys, $svg_preserver );
				continue;
			}

			self::processNode( $child, $settings, $inside_repeater, $global_shared_classes, $used_keys, $svg_preserver );
		}
	}

	// ─── Field Processing ───────────────────────────────────────────────

	/**
	 * Process a single annotated field element.
	 *
	 * @param \DOMElement       $el              The annotated element.
	 * @param array             $settings        Settings array (passed by reference).
	 * @param bool              $inside_repeater Whether we are inside a repeater.
	 * @param array             $used_keys       Map of claimed keys in scope (mutated).
	 * @param SvgPreserver|null $svg_preserver   Preserver for resolving SVG placeholder comments.
	 */
	private static function processField(
		\DOMElement $el,
		array &$settings,
		bool $inside_repeater,
		array &$used_keys = [],
		?SvgPreserver $svg_preserver = null
	): void {
		$key           = $el->getAttribute( 'data-field' );
		$tag_name      = strtolower( $el->tagName );
		$explicit_type = $el->getAttribute( 'data-field-type' ) ?: null;
		$href_field    = $el->getAttribute( 'data-field-href' ) ?: null;

		if ( 'rating' === $explicit_type ) {
			self::processRatingField( $el, $key, $settings, $inside_repeater );
			return;
		}

		if ( 'img' === $tag_name ) {
			self::processImageField( $el, $key, $settings, $inside_repeater );
		} elseif ( 'svg' === $tag_name || 'svg' === $explicit_type ) {
			self::processSvgField( $el, $key, $explicit_type, $settings, $inside_repeater );
		} elseif ( 'a' === $tag_name && empty( $href_field ) && $el->hasAttribute( 'href' ) ) {
			self::processLinkField( $el, $key, $settings, $inside_repeater, $used_keys, $svg_preserver );
		} else {
			self::processTextField( $el, $key, $explicit_type, $href_field, $settings, $inside_repeater );
		}
	}

	/**
	 * Process a link field (<a> with data-field and an href attribute,
	 * no data-field-href). Captures text + href + target + rel as a
	 * compound value and emits a single form field. Mirrors
	 * `frontend/src/core/services/annotation-parser.js processLinkField`.
	 *
	 * When the anchor's children include contiguous leading or trailing
	 * SVG / `<img>` icons, those icons are extracted to sibling fields
	 * (`{key}_icon`, `{key}_icon_2`, ...) and the captured text excludes
	 * the icon markup. See `extractLinkIconFields`. Mixed positions
	 * (text-icon-text) fall through to the standard innerHTML capture.
	 *
	 * @param \DOMElement       $el              The anchor element.
	 * @param string            $key             The field key.
	 * @param array             $settings        Settings array (passed by reference).
	 * @param bool              $inside_repeater Whether inside a repeater context.
	 * @param array             $used_keys       Map of claimed keys in scope (mutated).
	 * @param SvgPreserver|null $svg_preserver   Preserver for resolving SVG placeholder comments.
	 */
	private static function processLinkField(
		\DOMElement $el,
		string $key,
		array &$settings,
		bool $inside_repeater,
		array &$used_keys = [],
		?SvgPreserver $svg_preserver = null
	): void {
		$prefix = $inside_repeater ? '' : 'settings.';
		$href   = $el->getAttribute( 'href' ) ?: '';
		$target = $el->getAttribute( 'target' ) ?: '';
		$rel    = $el->getAttribute( 'rel' ) ?: '';

		$icons = self::extractLinkIconFields( $el, $key, $settings, $inside_repeater, $svg_preserver, $used_keys );

		if ( empty( $icons ) ) {
			// No extraction (mixed position or no icon children) — existing path.
			$text = trim( self::getInnerHTML( $el ) );
		} else {
			// Helper has removed icon children (SVGs detached; <img>s detached after templatization).
			// Captured text is whatever non-icon content remained.
			$text = trim( self::getInnerHTML( $el ) );
		}

		// Wipe and rebuild anchor content. For the no-icon path this is the
		// same as the original behavior (single text token). For the icon
		// path, tokens are ordered around the text token by position.
		while ( $el->firstChild ) {
			$el->removeChild( $el->firstChild );
		}

		$doc        = $el->ownerDocument;
		$text_token = $doc->createTextNode( self::PH_OPEN3 . $prefix . $key . '.text' . self::PH_CLOSE3 );

		if ( empty( $icons ) ) {
			$el->appendChild( $text_token );
		} else {
			$position = $icons[0]['position'];
			if ( 'leading' === $position ) {
				foreach ( $icons as $icon ) {
					$el->appendChild( self::buildLinkIconNode( $icon, $prefix, $doc ) );
				}
				$el->appendChild( $text_token );
			} else {
				$el->appendChild( $text_token );
				foreach ( $icons as $icon ) {
					$el->appendChild( self::buildLinkIconNode( $icon, $prefix, $doc ) );
				}
			}
		}

		$el->setAttribute( 'href', self::PH_OPEN . $prefix . $key . '.href' . self::PH_CLOSE );
		$el->setAttribute( 'target', self::PH_OPEN . $prefix . $key . '.target' . self::PH_CLOSE );
		$el->setAttribute( 'rel', self::PH_OPEN . $prefix . $key . '.rel' . self::PH_CLOSE );

		$settings[ $key ] = [
			'text'   => $text,
			'href'   => $href,
			'target' => $target,
			'rel'    => $rel,
		];

		self::removeAnnotationAttrs( $el );
	}

	/**
	 * Build a single child node for the link-compound rebuild. SVGs become
	 * a text node carrying their Mustache token; `<img>` icons are returned
	 * as the templatized element itself (detached by the helper, re-attached
	 * here).
	 *
	 * @param array        $icon One entry from `extractLinkIconFields`.
	 * @param string       $prefix Settings prefix (`'settings.'` or `''`).
	 * @param \DOMDocument $doc   Document to allocate text nodes against.
	 * @return \DOMNode
	 */
	private static function buildLinkIconNode( array $icon, string $prefix, \DOMDocument $doc ): \DOMNode {
		if ( 'img' === $icon['kind'] && $icon['node'] instanceof \DOMElement ) {
			return $icon['node'];
		}
		return $doc->createTextNode( self::PH_OPEN3 . $prefix . $icon['key'] . self::PH_CLOSE3 );
	}

	/**
	 * Extract contiguous leading or trailing icon children (SVG or `<img>`)
	 * from a link-compound anchor into sibling settings fields.
	 *
	 * Returns an ordered list of icon descriptors when extraction fires, or
	 * an empty array when:
	 *   - the anchor has no icon children, or
	 *   - icon children are interspersed with content (mixed position).
	 *
	 * Descriptor shape: `[ 'key' => string, 'position' => 'leading'|'trailing',
	 * 'kind' => 'svg'|'img', 'node' => ?\DOMElement ]`. For `'svg'` entries,
	 * `node` is `null` (SVG is gone from the DOM, serialized into settings).
	 * For `'img'` entries, `node` is the detached, templatized `<img>` element
	 * the caller re-attaches during the rebuild.
	 *
	 * Position policy: extraction fires only when every icon child sits
	 * contiguously at the start or contiguously at the end of the anchor's
	 * non-whitespace content. Mixed text-icon-text arrangements return `[]`
	 * and the caller falls back to the standard innerHTML capture. See
	 * `extractSvgFields` for the older in-place SVG-token split used by
	 * `processTextField`; that helper is intentionally kept separate (it
	 * has different positional semantics and doesn't handle `<img>`).
	 *
	 * @param \DOMElement       $a               The link anchor.
	 * @param string            $link_key        The compound link's key (used as `{link_key}_icon` base).
	 * @param array             $settings        Settings array (mutated).
	 * @param bool              $inside_repeater Whether inside a repeater (affects Mustache prefix on `<img>` tokens).
	 * @param SvgPreserver|null $svg_preserver   For resolving SVG placeholder comments.
	 * @param array             $used_keys       Claimed keys in scope (mutated).
	 * @return array<int, array{key: string, position: string, kind: string, node: ?\DOMElement}>
	 */
	private static function extractLinkIconFields(
		\DOMElement $a,
		string $link_key,
		array &$settings,
		bool $inside_repeater,
		?SvgPreserver $svg_preserver,
		array &$used_keys
	): array {
		// Classify each child once, skipping whitespace-only text nodes.
		$classified = [];
		foreach ( $a->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				if ( '' === trim( $child->textContent ) ) {
					continue;
				}
				$classified[] = [ 'type' => 'content', 'node' => $child ];
				continue;
			}
			if ( XML_COMMENT_NODE === $child->nodeType ) {
				if ( $child instanceof \DOMComment && null !== self::lookupSvgPlaceholder( $child ) ) {
					$classified[] = [ 'type' => 'svg-like', 'node' => $child ];
				}
				continue;
			}
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$child_tag = strtolower( $child->tagName );
				if ( 'svg' === $child_tag ) {
					$classified[] = [ 'type' => 'svg-like', 'node' => $child ];
					continue;
				}
				if ( 'img' === $child_tag ) {
					$classified[] = [ 'type' => 'img-like', 'node' => $child ];
					continue;
				}
				$classified[] = [ 'type' => 'content', 'node' => $child ];
			}
		}

		if ( empty( $classified ) ) {
			return [];
		}

		// Detect leading/trailing/mixed position pattern.
		$icon_count = 0;
		foreach ( $classified as $entry ) {
			if ( 'svg-like' === $entry['type'] || 'img-like' === $entry['type'] ) {
				$icon_count++;
			}
		}
		if ( 0 === $icon_count ) {
			return [];
		}

		$first_content = null;
		$last_content  = null;
		foreach ( $classified as $i => $entry ) {
			if ( 'content' === $entry['type'] ) {
				if ( null === $first_content ) {
					$first_content = $i;
				}
				$last_content = $i;
			}
		}

		if ( null === $first_content ) {
			// No content children — purely icons. Treat as leading.
			$position = 'leading';
		} else {
			$leading  = true;
			$trailing = true;
			foreach ( $classified as $i => $entry ) {
				if ( 'svg-like' !== $entry['type'] && 'img-like' !== $entry['type'] ) {
					continue;
				}
				if ( $i > $first_content ) {
					$leading = false;
				}
				if ( $i < $last_content ) {
					$trailing = false;
				}
			}
			if ( $leading ) {
				$position = 'leading';
			} elseif ( $trailing ) {
				$position = 'trailing';
			} else {
				return [];
			}
		}

		// Emit settings + detach in document order. Auto-generated keys use
		// `{link_key}_icon` (1st), `{link_key}_icon_2` (2nd), … each
		// disambiguated against `$used_keys`. A child carrying a pre-existing
		// `data-field` keeps that key (already collected into `$used_keys`).
		$icons        = [];
		$prefix       = $inside_repeater ? '' : 'settings.';
		$auto_counter = 0;
		foreach ( $classified as $entry ) {
			if ( 'svg-like' !== $entry['type'] && 'img-like' !== $entry['type'] ) {
				continue;
			}
			$node = $entry['node'];

			$icon_key = null;
			if ( $node instanceof \DOMElement && $node->hasAttribute( 'data-field' ) ) {
				$icon_key = $node->getAttribute( 'data-field' );
			}
			if ( null === $icon_key || '' === $icon_key ) {
				$auto_counter++;
				$base     = 1 === $auto_counter ? $link_key . '_icon' : $link_key . '_icon_' . $auto_counter;
				$icon_key = Keys::disambiguate( $base, $used_keys );
			}

			if ( 'svg-like' === $entry['type'] ) {
				if ( $node instanceof \DOMElement ) {
					// Stamp `data-field` on the SVG before serializing — keeps the
					// stored bytes byte-aligned with other SVG-field storage
					// (`processSvgField` and `svgBytesFor` both write a SVG with
					// `data-field` set on the root).
					$node->setAttribute( 'data-field', $icon_key );
					$svg_string = self::serializeSvg( $node );
				} elseif ( $node instanceof \DOMComment ) {
					$raw = self::lookupSvgPlaceholder( $node );
					if ( null === $raw ) {
						continue;
					}
					$svg_string = self::svgBytesFor( $raw, $icon_key );
				} else {
					continue;
				}
				$settings[ $icon_key ] = $svg_string;
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
				$icons[] = [
					'key'      => $icon_key,
					'position' => $position,
					'kind'     => 'svg',
					'node'     => null,
				];
				continue;
			}

			// img-like: templatize attributes in place (mirrors `processImageField`),
			// then detach so the caller can place it in the rebuilt order.
			$src = $node->getAttribute( 'src' ) ?: '';
			$alt = $node->getAttribute( 'alt' ) ?: '';
			$node->setAttribute( 'src', self::PH_OPEN . $prefix . $icon_key . self::PH_CLOSE );
			$node->setAttribute( 'alt', self::PH_OPEN . $prefix . $icon_key . '.alt' . self::PH_CLOSE );
			self::removeAnnotationAttrs( $node );
			$settings[ $icon_key ] = [
				'url'  => $src,
				'alt'  => $alt,
				'id'   => null,
				'size' => null,
			];
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
			$icons[] = [
				'key'      => $icon_key,
				'position' => $position,
				'kind'     => 'img',
				'node'     => $node,
			];
		}

		return $icons;
	}

	/**
	 * Process an icon-only link: an element annotated with `data-field-href`
	 * alone (no `data-field` on the same element). Captures the href into
	 * settings, swaps the attribute for a Mustache token, and recurses into
	 * the element's children so any inner annotated content (e.g. an
	 * `<svg data-field>`) is still processed normally.
	 *
	 * @param \DOMElement       $el                    The element bearing data-field-href.
	 * @param array             $settings              Settings array (passed by reference).
	 * @param bool              $inside_repeater       Whether inside a repeater context.
	 * @param array|null        $global_shared_classes Cross-instance shared-class map.
	 * @param array             $used_keys             Map of claimed keys in scope (mutated).
	 * @param SvgPreserver|null $svg_preserver         Preserver for resolving SVG placeholder comments.
	 */
	private static function processHrefOnlyField(
		\DOMElement $el,
		array &$settings,
		bool $inside_repeater,
		?array $global_shared_classes = null,
		array &$used_keys = [],
		?SvgPreserver $svg_preserver = null
	): void {
		$key        = $el->getAttribute( 'data-field-href' );
		$href_value = $el->getAttribute( 'href' ) ?: '';
		$prefix     = $inside_repeater ? '' : 'settings.';

		$el->setAttribute( 'href', self::PH_OPEN . $prefix . $key . self::PH_CLOSE );
		$settings[ $key ] = $href_value;

		$el->removeAttribute( 'data-field-href' );

		// Recurse so inner annotated content (e.g. <svg data-field>) is captured.
		self::processNode( $el, $settings, $inside_repeater, $global_shared_classes, $used_keys, $svg_preserver );
	}

	/**
	 * Process a text-type field.
	 *
	 * @param \DOMElement $el              The element.
	 * @param string      $key             The field key.
	 * @param string|null $explicit_type   Explicit field type override.
	 * @param string|null $href_field      The href field key if present.
	 * @param array       $settings        Settings array (passed by reference).
	 * @param bool        $inside_repeater Whether inside a repeater.
	 */
	private static function processTextField(
		\DOMElement $el,
		string $key,
		?string $explicit_type,
		?string $href_field,
		array &$settings,
		bool $inside_repeater
	): void {
		$prefix         = $inside_repeater ? '' : 'settings.';
		$mixed_svg_text = self::hasMixedSvgAndText( $el );

		// Check for mixed SVG+text content and split into separate fields.
		if ( $mixed_svg_text ) {
			$text_value = self::extractSvgFields( $el, $key, $settings, $inside_repeater );

			// Replace remaining text nodes with the text field token.
			$text_nodes = [];
			foreach ( $el->childNodes as $child ) {
				if ( XML_TEXT_NODE === $child->nodeType ) {
					$trimmed = trim( $child->textContent );
					if ( '' !== $trimmed && 0 !== strpos( $trimmed, self::PH_OPEN3 ) ) {
						$text_nodes[] = $child;
					}
				}
			}

			if ( ! empty( $text_nodes ) ) {
				$token_text = self::PH_OPEN3 . $prefix . $key . self::PH_CLOSE3;
				$token_node = $el->ownerDocument->createTextNode( $token_text );
				$text_nodes[0]->parentNode->replaceChild( $token_node, $text_nodes[0] );
				for ( $i = 1, $count = count( $text_nodes ); $i < $count; $i++ ) {
					$text_nodes[ $i ]->parentNode->removeChild( $text_nodes[ $i ] );
				}
			}

			$settings[ $key ] = $text_value;
		} else {
			$value  = trim( self::getInnerHTML( $el ) );

			// Clear child nodes.
			while ( $el->firstChild ) {
				$el->removeChild( $el->firstChild );
			}

			// Set template content as text node (always triple-curly per ADR 106).
			$token_text = self::PH_OPEN3 . $prefix . $key . self::PH_CLOSE3;
			$el->appendChild( $el->ownerDocument->createTextNode( $token_text ) );

			$settings[ $key ] = $value;
		}

		// Handle href field on same element.
		if ( $href_field ) {
			$href_value = $el->getAttribute( 'href' ) ?: '';
			$el->setAttribute( 'href', self::PH_OPEN . $prefix . $href_field . self::PH_CLOSE );

			$settings[ $href_field ] = $href_value;

			$el->removeAttribute( 'data-field-href' );
		}

		self::removeAnnotationAttrs( $el );

		// Preserve explicit field types that the reconstructor cannot infer
		// from the value alone (e.g. textarea — plain text gives no signal).
		if ( 'textarea' === $explicit_type ) {
			$el->setAttribute( 'data-field-type', $explicit_type );
		}

		// Mixed SVG+text: the wrapper itself carries data-field for the text
		// portion. The split SVG tokens get their own data-field at reconstruct.
		if ( $mixed_svg_text ) {
			$el->setAttribute( 'data-field', $key );
		}
	}

	/**
	 * Check whether an element contains both <svg> child elements and
	 * non-whitespace text node siblings.
	 *
	 * @param \DOMElement $el The element to check.
	 * @return bool True if mixed SVG+text content.
	 */
	private static function hasMixedSvgAndText( \DOMElement $el ): bool {
		$has_svg  = false;
		$has_text = false;

		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'svg' === strtolower( $child->tagName ) ) {
				$has_svg = true;
			}
			if ( $child instanceof \DOMComment && null !== self::lookupSvgPlaceholder( $child ) ) {
				$has_svg = true;
			}
			if ( XML_TEXT_NODE === $child->nodeType && '' !== trim( $child->textContent ) ) {
				$has_text = true;
			}
		}

		return $has_svg && $has_text;
	}

	/**
	 * Split mixed SVG+text content into separate fields. Replaces SVG elements
	 * with Mustache tokens in the DOM and returns the remaining text value.
	 *
	 * @param \DOMElement $el              The element containing SVGs and text.
	 * @param string      $key             The field key.
	 * @param array       $settings        Settings array (passed by reference).
	 * @param bool        $inside_repeater Whether inside a repeater.
	 * @return string The remaining text value after SVG extraction.
	 */
	private static function extractSvgFields(
		\DOMElement $el,
		string $key,
		array &$settings,
		bool $inside_repeater
	): string {
		$prefix = $inside_repeater ? '' : 'settings.';
		$doc    = $el->ownerDocument;

		// Collect SVG children (snapshot to avoid modifying while iterating).
		// DOMComment placeholders left by SvgPreserver count as SVGs too.
		$svgs = [];
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'svg' === strtolower( $child->tagName ) ) {
				$svgs[] = $child;
				continue;
			}
			if ( $child instanceof \DOMComment && null !== self::lookupSvgPlaceholder( $child ) ) {
				$svgs[] = $child;
			}
		}

		foreach ( $svgs as $i => $svg ) {
			$svg_key = 0 === $i ? $key . '_icon' : $key . '_icon_' . ( $i + 1 );

			if ( $svg instanceof \DOMElement ) {
				$svg_content = self::serializeSvg( $svg );
			} else {
				$svg_content = self::lookupSvgPlaceholder( $svg ) ?? '';
			}

			$settings[ $svg_key ] = $svg_content;

			$token_text = self::PH_OPEN3 . $prefix . $svg_key . self::PH_CLOSE3;
			$token_node = $doc->createTextNode( $token_text );
			$svg->parentNode->replaceChild( $token_node, $svg );
		}

		// Collect remaining text from text nodes (skip the tokens we just inserted).
		$text_parts = [];
		foreach ( $el->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$trimmed = trim( $child->textContent );
				if ( '' !== $trimmed && 0 !== strpos( $trimmed, self::PH_OPEN3 ) ) {
					$text_parts[] = $trimmed;
				}
			}
		}

		return implode( ' ', $text_parts );
	}

	/**
	 * Process an image field.
	 *
	 * @param \DOMElement $el              The img element.
	 * @param string      $key             The field key.
	 * @param array       $settings        Settings array (passed by reference).
	 * @param bool        $inside_repeater Whether inside a repeater.
	 */
	private static function processImageField(
		\DOMElement $el,
		string $key,
		array &$settings,
		bool $inside_repeater
	): void {
		$src    = $el->getAttribute( 'src' ) ?: '';
		$alt    = $el->getAttribute( 'alt' ) ?: '';
		$prefix = $inside_repeater ? '' : 'settings.';

		$el->setAttribute( 'src', self::PH_OPEN . $prefix . $key . self::PH_CLOSE );
		$el->setAttribute( 'alt', self::PH_OPEN . $prefix . $key . '.alt' . self::PH_CLOSE );

		$settings[ $key ] = [
			'url'  => $src,
			'alt'  => $alt,
			'id'   => null,
			'size' => null,
		];

		self::removeAnnotationAttrs( $el );
	}

	/**
	 * Process an SVG field.
	 *
	 * @param \DOMElement $el              The element.
	 * @param string      $key             The field key.
	 * @param string|null $explicit_type   Explicit field type.
	 * @param array       $settings        Settings array (passed by reference).
	 * @param bool        $inside_repeater Whether inside a repeater.
	 */
	private static function processSvgField(
		\DOMElement $el,
		string $key,
		?string $explicit_type,
		array &$settings,
		bool $inside_repeater
	): void {
		$tag_name = strtolower( $el->tagName );
		$prefix   = $inside_repeater ? '' : 'settings.';

		if ( 'svg' === $tag_name ) {
			// Capture outerHTML with SVG-aware serialization and replace element with text token.
			$svg_content = self::serializeSvg( $el );
			$token_text  = self::PH_OPEN3 . $prefix . $key . self::PH_CLOSE3;
			$placeholder = $el->ownerDocument->createTextNode( $token_text );
			$el->parentNode->replaceChild( $placeholder, $el );

			$settings[ $key ] = $svg_content;
		} else {
			// Non-SVG element with data-field-type="svg" — treat innerHTML as SVG content.
			$svg_content = '';
			foreach ( $el->childNodes as $child ) {
				if ( $child instanceof \DOMElement && 'svg' === strtolower( $child->tagName ) ) {
					$svg_content .= self::serializeSvg( $child );
				} else {
					$svg_content .= $el->ownerDocument->saveHTML( $child );
				}
			}

			while ( $el->firstChild ) {
				$el->removeChild( $el->firstChild );
			}
			$token_text = self::PH_OPEN3 . $prefix . $key . self::PH_CLOSE3;
			$el->appendChild( $el->ownerDocument->createTextNode( $token_text ) );

			$settings[ $key ] = $svg_content;

			self::removeAnnotationAttrs( $el );

			// Preserve data-field-type so the reconstructor knows the wrapper
			// owns the field — the bare SVG value alone wouldn't signal that.
			$el->setAttribute( 'data-field-type', 'svg' );
		}
	}

	// ─── Repeater Processing ────────────────────────────────────────────

	/**
	 * Process a data-repeater container and its items.
	 *
	 * @param \DOMElement       $el              The repeater container element.
	 * @param array             $settings        Settings array (passed by reference).
	 * @param bool              $inside_repeater Whether already inside a repeater.
	 * @param array|null        $global_shared_classes Cross-instance shared-class map.
	 * @param SvgPreserver|null $svg_preserver   Preserver for resolving SVG placeholder comments.
	 */
	private static function processRepeater(
		\DOMElement $el,
		array &$settings,
		bool $inside_repeater = false,
		?array $global_shared_classes = null,
		?SvgPreserver $svg_preserver = null
	): void {
		$repeater_key = $el->getAttribute( 'data-repeater' );

		// Find direct children with data-repeater-item.
		$items = [];
		foreach ( $el->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && $child->hasAttribute( 'data-repeater-item' ) ) {
				$items[] = $child;
			}
		}

		if ( empty( $items ) ) {
			return;
		}

		// Strip AI-generated per-item label comments from the repeater container.
		// SvgPreserver placeholder comments (decorative SVGs lifted before DOM
		// parsing) must survive so rating helpers can still classify them.
		$doc           = $el->ownerDocument;
		$xpath         = new \DOMXPath( $doc );
		$comment_nodes = $xpath->query( './/comment()', $el );
		foreach ( $comment_nodes as $c ) {
			if ( SvgPreserver::isPlaceholder( '<!--' . $c->data . '-->' ) ) {
				continue;
			}
			$c->parentNode->removeChild( $c );
		}

		// Cross-item rating scan: detect inactive templates across items
		// before processNode modifies the DOM.
		self::crossItemRatingScan( $items );

		// Class-diff variation detection: compute which CSS classes differ
		// across items. Shared classes are computed globally across all
		// instances of this repeater key in the document so that sibling
		// instances with uniform classes still reconcile against varying siblings.
		if ( is_array( $global_shared_classes ) && isset( $global_shared_classes[ $repeater_key ] ) ) {
			$shared_classes    = $global_shared_classes[ $repeater_key ]['sharedClasses'];
			$global_item_count = $global_shared_classes[ $repeater_key ]['itemCount'];
		} else {
			$shared_classes    = self::getSharedClasses( $items );
			$global_item_count = count( $items );
		}
		$shared_set      = array_flip( $shared_classes );
		$has_variations  = false;
		$item_variations = null;

		if ( $global_item_count >= 2 ) {
			$item_variations = [];
			foreach ( $items as $item ) {
				$all_classes = array_filter(
					preg_split( '/\s+/', trim( $item->getAttribute( 'class' ) ?: '' ) ),
					'strlen'
				);
				$unique = array_values( array_filter( $all_classes, function ( $cls ) use ( $shared_set ) {
					return ! isset( $shared_set[ $cls ] );
				} ) );
				if ( ! empty( $unique ) ) {
					$has_variations = true;
				}
				$item_variations[] = implode( ' ', $unique );
			}
			if ( ! $has_variations ) {
				$item_variations = null;
			}
		}

		$repeater_items = [];

		// Process each item to extract values.
		foreach ( $items as $i => $item ) {
			$item_settings = [];

			// Per-item key namespace: each item is its own annotation scope, so
			// two items can both define `cta_icon` without colliding. Collected
			// fresh per item so the helper's auto-generated keys (e.g. via
			// `processLinkField`) disambiguate only against keys present in
			// THIS item's subtree.
			$item_used_keys = Keys::collectExistingKeys( $item, /* include_repeater_items */ true );

			// If the item element itself has data-field, process it directly.
			if ( $item->hasAttribute( 'data-field' ) ) {
				self::processField( $item, $item_settings, true, $item_used_keys, $svg_preserver );
				self::processNode( $item, $item_settings, true, $global_shared_classes, $item_used_keys, $svg_preserver );
			} elseif ( $item->hasAttribute( 'data-field-href' ) ) {
				// Icon-only link: the item element itself carries data-field-href
				// alone. processHrefOnlyField captures the href and recurses
				// into children so any inner annotated content (e.g. an
				// <svg data-field>) is still processed.
				self::processHrefOnlyField( $item, $item_settings, true, $global_shared_classes, $item_used_keys, $svg_preserver );
			} else {
				self::processNode( $item, $item_settings, true, $global_shared_classes, $item_used_keys, $svg_preserver );
			}

			// Add per-item variation value (from class-diff detection).
			if ( $has_variations ) {
				$item_settings['_variation'] = $item_variations[ $i ];
			}

			$repeater_items[] = $item_settings;
		}

		// Inject {{{_variation}}} class token on the first item's root element
		// so the variation value renders as a CSS class during Mustache rendering.
		// Also strip per-item classes from the template: the agent may put
		// variation-specific classes directly on items (e.g., "card card-featured"),
		// and since the first item becomes the template, those classes would be
		// baked into every item. We keep only classes shared by ALL items.
		if ( $has_variations ) {
			$clean_class = implode( ' ', $shared_classes );
			$items[0]->setAttribute(
				'class',
				trim( $clean_class . ' ' . self::PH_OPEN3 . '_variation' . self::PH_CLOSE3 )
			);
		}

		// Use first item as template, remove the rest
		// (and preceding whitespace text nodes / HTML comments between them).
		$template_item = $items[0];
		$count         = count( $items );

		for ( $i = 1; $i < $count; $i++ ) {
			$item = $items[ $i ];
			// Remove preceding non-element nodes (whitespace text, comments).
			while ( $item->previousSibling && $item->previousSibling !== $template_item
				&& XML_ELEMENT_NODE !== $item->previousSibling->nodeType ) {
				$item->previousSibling->parentNode->removeChild( $item->previousSibling );
			}
			$item->parentNode->removeChild( $item );
		}

		// Remove trailing non-element nodes after the template item.
		while ( $template_item->nextSibling
			&& XML_ELEMENT_NODE !== $template_item->nextSibling->nodeType ) {
			$template_item->nextSibling->parentNode->removeChild( $template_item->nextSibling );
		}

		// Build settings keys from first item.
		$all_keys = array_keys( $repeater_items[0] ?? [] );

		// Extract item settings from each item.
		$settings_array = array_map( function ( $item_settings ) use ( $all_keys ) {
			$item_obj = [];
			foreach ( $all_keys as $key ) {
				$item_obj[ $key ] = $item_settings[ $key ] ?? '';
			}
			return $item_obj;
		}, $repeater_items );

		$settings[ $repeater_key ] = $settings_array;

		// Store stable variations array — the list of available variation classes.
		if ( $has_variations ) {
			$settings[ $repeater_key . '__variations' ] = array_values( array_unique( $item_variations ) );
		}

		// Clean annotation attributes.
		self::removeAnnotationAttrs( $template_item, true );
		self::removeAnnotationAttrs( $el, true );

		// Insert Mustache section markers.
		$doc       = $el->ownerDocument;
		$prefix    = $inside_repeater ? '' : 'settings.';
		$open_tag  = $doc->createTextNode( self::PH_SECT_O . $prefix . $repeater_key . self::PH_SECT_C );
		$close_tag = $doc->createTextNode( self::PH_OPEN . '/' . $prefix . $repeater_key . self::PH_CLOSE );

		$el->insertBefore( $open_tag, $template_item );
		$el->appendChild( $close_tag );
	}

	// ─── Rating Field Processing ───────────────────────────────────────

	/**
	 * Normalize a class attribute string by sorting its tokens.
	 *
	 * @param string $class_attr The raw class attribute value.
	 * @return string Sorted, trimmed class string.
	 */
	private static function normalizeClassName( string $class_attr ): string {
		$parts = preg_split( '/\s+/', trim( $class_attr ) );
		$parts = array_filter( $parts, 'strlen' );
		sort( $parts );
		return implode( ' ', $parts );
	}

	/**
	 * Resolve direct children of a rating container into descriptor pairs of
	 * (normalized class, captured outer HTML). DOMElement children are cloned
	 * and serialized with SVG-aware rules; SvgPreserver comment placeholders
	 * (decorative SVGs lifted out before DOMDocument parsed the HTML) are
	 * looked up and surfaced verbatim, with the class read from the original
	 * SVG opening tag.
	 *
	 * @param \DOMElement $el The rating container element.
	 * @return array<int, array{class: string, html: string}>
	 */
	private static function getRatingChildDescriptors( \DOMElement $el ): array {
		$descriptors = [];

		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$descriptors[] = [
					'class' => self::normalizeClassName( $child->getAttribute( 'class' ) ),
					'html'  => self::serializeRatingChildElement( $child ),
				];
				continue;
			}

			if ( $child instanceof \DOMComment ) {
				$svg_str = self::lookupSvgPlaceholder( $child );
				if ( null !== $svg_str ) {
					$descriptors[] = [
						'class' => self::extractSvgClass( $svg_str ),
						'html'  => $svg_str,
					];
				}
			}
		}

		return $descriptors;
	}

	/**
	 * Group rating-child descriptors by normalized class name, preserving
	 * first-occurrence order.
	 *
	 * @param array<int, array{class: string, html: string}> $descriptors
	 * @return array<int, array{className: string, children: array<int, array{class: string, html: string}>}>
	 */
	private static function groupRatingDescriptors( array $descriptors ): array {
		$group_map   = [];
		$group_order = [];

		foreach ( $descriptors as $desc ) {
			$cls = $desc['class'];
			if ( ! isset( $group_map[ $cls ] ) ) {
				$group_map[ $cls ] = count( $group_order );
				$group_order[]     = [ 'className' => $cls, 'children' => [] ];
			}
			$group_order[ $group_map[ $cls ] ]['children'][] = $desc;
		}

		return $group_order;
	}

	/**
	 * Serialize a DOMElement child of a rating container, stripping annotation
	 * attributes and routing `<svg>` through serializeSvg to preserve camelCase
	 * attributes and self-closing tags.
	 *
	 * @param \DOMElement $child The child element.
	 * @return string Outer HTML.
	 */
	private static function serializeRatingChildElement( \DOMElement $child ): string {
		$clone = $child->cloneNode( true );
		foreach ( self::DATA_ATTRS as $attr ) {
			$clone->removeAttribute( $attr );
		}

		if ( 'svg' === strtolower( $clone->tagName ) ) {
			return self::serializeSvg( $clone );
		}

		return $child->ownerDocument->saveHTML( $clone );
	}

	/**
	 * Resolve an SvgPreserver comment placeholder back to its original SVG
	 * markup. Returns null if the comment isn't a placeholder or no preserver
	 * is active for the current parse.
	 *
	 * @param \DOMComment $comment The comment node.
	 * @return string|null The original SVG string, or null.
	 */
	private static function lookupSvgPlaceholder( \DOMComment $comment ): ?string {
		if ( null === self::$svgPreserver ) {
			return null;
		}
		$marker = '<!--' . $comment->data . '-->';
		$index  = SvgPreserver::placeholderIndex( $marker );
		if ( null === $index ) {
			return null;
		}
		return self::$svgPreserver->get( $index );
	}

	/**
	 * Extract and normalize the `class` attribute from an SVG opening tag.
	 * Operates on the raw SVG string so camelCase attributes aren't disturbed.
	 *
	 * @param string $svg The full `<svg>...</svg>` fragment.
	 * @return string Normalized class string (empty when absent).
	 */
	private static function extractSvgClass( string $svg ): string {
		if ( ! preg_match( '/<svg\b[^>]*>/i', $svg, $open ) ) {
			return '';
		}
		if ( preg_match( '/\bclass\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $open[0], $m ) ) {
			$value = '' !== ( $m[2] ?? '' ) ? $m[2] : ( '' !== ( $m[3] ?? '' ) ? $m[3] : ( $m[4] ?? '' ) );
			return self::normalizeClassName( $value );
		}
		return '';
	}

	/**
	 * Returns the CSS classes shared by ALL repeater items' root elements.
	 * Classes unique to individual items (variation-specific styling) are excluded.
	 * Preserves the order from the first item.
	 *
	 * @param \DOMElement[] $items The repeater item elements.
	 * @return string[] Classes present on every item.
	 */
	private static function getSharedClasses( array $items ): array {
		if ( empty( $items ) ) {
			return [];
		}

		$first_classes = array_filter(
			preg_split( '/\s+/', trim( $items[0]->getAttribute( 'class' ) ?: '' ) ),
			'strlen'
		);

		if ( count( $items ) === 1 ) {
			return $first_classes;
		}

		$class_sets = array_map( function ( $item ) {
			return array_flip(
				array_filter(
					preg_split( '/\s+/', trim( $item->getAttribute( 'class' ) ?: '' ) ),
					'strlen'
				)
			);
		}, $items );

		return array_values( array_filter( $first_classes, function ( $cls ) use ( $class_sets ) {
			foreach ( $class_sets as $set ) {
				if ( ! isset( $set[ $cls ] ) ) {
					return false;
				}
			}
			return true;
		} ) );
	}

	/**
	 * Pre-scan the entire document, grouping repeater items by data-repeater key
	 * across every repeater instance in the tree. Returns a map keyed by
	 * repeater key with cross-instance shared classes and total item count.
	 *
	 * Nested repeaters with sibling instances must be reconciled globally: if
	 * one sibling has uniform classes but another has varying classes, the
	 * uniform instance still needs a _variation value matching its class.
	 * Local-only shared-class computation misses this because each instance
	 * is processed in isolation.
	 *
	 * @param \DOMNode $root The root element to scan.
	 * @return array<string, array{sharedClasses: string[], itemCount: int}>
	 */
	private static function computeGlobalSharedClasses( \DOMNode $root ): array {
		$xpath    = new \DOMXPath( $root->ownerDocument );
		$repeater_els = $xpath->query( './/*[@data-repeater]', $root );
		if ( false === $repeater_els ) {
			return [];
		}

		$groups = [];
		foreach ( $repeater_els as $el ) {
			$key = $el->getAttribute( 'data-repeater' );
			foreach ( $el->childNodes as $child ) {
				if ( XML_ELEMENT_NODE === $child->nodeType && $child->hasAttribute( 'data-repeater-item' ) ) {
					if ( ! isset( $groups[ $key ] ) ) {
						$groups[ $key ] = [];
					}
					$groups[ $key ][] = $child;
				}
			}
		}

		$result = [];
		foreach ( $groups as $key => $items ) {
			$result[ $key ] = [
				'sharedClasses' => self::getSharedClasses( $items ),
				'itemCount'     => count( $items ),
			];
		}
		return $result;
	}

	/**
	 * Scan rating fields across repeater items to detect inactive templates.
	 *
	 * Sets a temporary `data-rating-inactive-tmpl` attribute on rating elements
	 * in every item so that processRatingField can use it.
	 *
	 * @param \DOMElement[] $items The repeater item elements.
	 */
	private static function crossItemRatingScan( array $items ): void {
		if ( count( $items ) < 2 ) {
			return;
		}

		// Collect all rating elements grouped by data-field key across items.
		$ratings_by_key = [];

		foreach ( $items as $i => $item ) {
			$doc   = $item->ownerDocument;
			$xpath = new \DOMXPath( $doc );
			$nodes = $xpath->query( './/*[@data-field-type="rating"]', $item );

			foreach ( $nodes as $rating_el ) {
				$key = $rating_el->getAttribute( 'data-field' );
				if ( empty( $key ) ) {
					continue;
				}
				if ( ! isset( $ratings_by_key[ $key ] ) ) {
					$ratings_by_key[ $key ] = [];
				}
				$ratings_by_key[ $key ][] = [ 'itemIndex' => $i, 'el' => $rating_el ];
			}
		}

		// For each rating key, find the inactive template.
		foreach ( $ratings_by_key as $entries ) {
			$inactive_template = null;

			foreach ( $entries as $entry ) {
				$descriptors = self::getRatingChildDescriptors( $entry['el'] );
				$groups      = self::groupRatingDescriptors( $descriptors );
				if ( 2 === count( $groups ) ) {
					$inactive_template = $groups[1]['children'][0]['html'];
					break;
				}
			}

			if ( null === $inactive_template ) {
				continue;
			}

			// Set the inactive template on ALL items' rating elements for this key.
			foreach ( $entries as $entry ) {
				$entry['el']->setAttribute( 'data-rating-inactive-tmpl', $inactive_template );
			}
		}
	}

	/**
	 * Process a rating field (data-field-type="rating").
	 *
	 * @param \DOMElement $el              The rating container element.
	 * @param string      $key             The field key.
	 * @param array       $settings        Settings array (passed by reference).
	 * @param bool        $inside_repeater Whether inside a repeater context.
	 */
	private static function processRatingField(
		\DOMElement $el,
		string $key,
		array &$settings,
		bool $inside_repeater
	): void {
		$max_attr = $el->getAttribute( 'data-field-max' );
		$max      = ! empty( $max_attr ) ? (int) $max_attr : 0;
		$prefix   = $inside_repeater ? '' : 'settings.';

		$descriptors    = self::getRatingChildDescriptors( $el );
		$groups         = self::groupRatingDescriptors( $descriptors );
		$child_count    = count( $descriptors );

		$active_html   = null;
		$inactive_html = null;
		$value         = 0;

		// Check for cross-item inactive template (stored as HTML string).
		$cross_item_inactive = $el->getAttribute( 'data-rating-inactive-tmpl' );

		// Active/inactive markup is captured as HTML strings (SVG-aware via
		// getRatingChildDescriptors) so attributes never round-trip through
		// DOMDocument's saveHTML, which would lowercase viewBox and expand
		// self-closing SVG elements like <path />.
		if ( 1 === count( $groups ) ) {
			$active_html = $descriptors[0]['html'];
			$value       = $child_count;

			if ( ! empty( $cross_item_inactive ) ) {
				$inactive_html = $cross_item_inactive;
			}
		} elseif ( 2 === count( $groups ) ) {
			$active_html   = $groups[0]['children'][0]['html'];
			$inactive_html = $groups[1]['children'][0]['html'];
			$value         = count( $groups[0]['children'] );
		}

		// Detect indentation from whitespace before the first child element.
		$child_indent = '  ';
		foreach ( $el->childNodes as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				if ( preg_match( '/\n([ \t]+)/', $node->textContent, $m ) ) {
					$child_indent = $m[1];
					break;
				}
			}
		}
		$inner_indent  = $child_indent . '  ';
		$parent_indent = strlen( $child_indent ) >= 2 ? substr( $child_indent, 0, -2 ) : '';

		// Clear all children from the element.
		while ( $el->firstChild ) {
			$el->removeChild( $el->firstChild );
		}

		// Build formatted Mustache template as DOM nodes so HTML elements
		// serialize correctly (createTextNode would entity-encode angle brackets).
		$doc = $el->ownerDocument;

		// {{#key}}
		$el->appendChild( $doc->createTextNode(
			"\n" . $child_indent . self::PH_SECT_O . $prefix . $key . self::PH_SECT_C
		) );

		// {{#active}} + active markup + {{/active}}
		$el->appendChild( $doc->createTextNode( "\n" . $inner_indent . self::PH_SECT_O . 'active' . self::PH_SECT_C ) );
		$el->appendChild( $doc->createTextNode( "\n" . $inner_indent ) );
		if ( null !== $active_html ) {
			$el->appendChild( $doc->createTextNode( self::registerRatingPlaceholder( $active_html ) ) );
		}
		$el->appendChild( $doc->createTextNode( "\n" . $inner_indent . self::PH_OPEN . '/active' . self::PH_CLOSE ) );

		if ( null !== $inactive_html ) {
			// {{^active}} + inactive markup + {{/active}}
			$el->appendChild( $doc->createTextNode( "\n" . $inner_indent . self::PH_OPEN . '^active' . self::PH_CLOSE ) );
			$el->appendChild( $doc->createTextNode( "\n" . $inner_indent ) );
			$el->appendChild( $doc->createTextNode( self::registerRatingPlaceholder( $inactive_html ) ) );
			$el->appendChild( $doc->createTextNode( "\n" . $inner_indent . self::PH_OPEN . '/active' . self::PH_CLOSE ) );
		}

		// {{/key}}
		$el->appendChild( $doc->createTextNode(
			"\n" . $child_indent . self::PH_OPEN . '/' . $prefix . $key . self::PH_CLOSE
			. "\n" . $parent_indent
		) );

		// Store settings.
		$settings[ $key ] = [
			'value' => $value,
			'max'   => $max > 0 ? $max : $child_count,
		];

		// Clean up.
		$el->removeAttribute( 'data-rating-inactive-tmpl' );
		self::removeAnnotationAttrs( $el );
	}

	/**
	 * Register a captured rating active/inactive HTML string and return the
	 * placeholder token to inject as a text node. The token is substituted
	 * back to the original HTML in serializeTemplate, after DOMDocument has
	 * finished its (lossy) HTML4 serialization pass.
	 *
	 * @param string $html The pre-captured HTML string for one rating star.
	 * @return string The placeholder token.
	 */
	private static function registerRatingPlaceholder( string $html ): string {
		$token = '__BB_RATING_PH_' . self::$ratingSvgCounter++ . '__';
		self::$ratingSvgPlaceholders[ $token ] = $html;
		return $token;
	}

	// ─── Template Serialization ─────────────────────────────────────────

	/**
	 * Serialize the modified DOM body back to an HTML string.
	 *
	 * @param \DOMNode $body The body element.
	 * @return string The serialized template HTML.
	 */
	private static function serializeTemplate( \DOMNode $body ): string {
		$html = self::getInnerHTML( $body );

		// Normalize self-closing tags that DOMDocument may have expanded.
		$html = preg_replace(
			'/<(img|br|hr|input|meta|link)([^>]*?)><\/\1>/i',
			'<$1$2 />',
			$html
		);

		// Replace placeholders with actual Mustache tokens.
		$html = str_replace(
			[ self::PH_OPEN3, self::PH_CLOSE3, self::PH_SECT_O, self::PH_SECT_C, self::PH_OPEN, self::PH_CLOSE ],
			[ '{{{', '}}}', '{{#', '}}', '{{', '}}' ],
			$html
		);

		// Substitute rating SVG placeholders with their pre-captured HTML strings.
		if ( ! empty( self::$ratingSvgPlaceholders ) ) {
			$html = str_replace(
				array_keys( self::$ratingSvgPlaceholders ),
				array_values( self::$ratingSvgPlaceholders ),
				$html
			);
		}

		return trim( $html );
	}

	// ─── Helpers ────────────────────────────────────────────────────────

	/**
	 * Get the inner HTML of a DOM node.
	 *
	 * @param \DOMNode $node The DOM node.
	 * @return string The inner HTML.
	 */
	private static function getInnerHTML( \DOMNode $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Remove all annotation data attributes from an element.
	 *
	 * @param \DOMElement $el      The DOM element.
	 * @param bool        $shallow If true, only clean this element.
	 */
	private static function removeAnnotationAttrs( \DOMElement $el, bool $shallow = false ): void {
		foreach ( self::DATA_ATTRS as $attr ) {
			$el->removeAttribute( $attr );
		}
	}

	// ─── SVG Synthetic Emission ────────────────────────────────────────

	/**
	 * Execute the top-level SVG emission plan from AutoAnnotateGaps. For each
	 * entry, replace its placeholder comment with a triple-Mustache token text
	 * node (Stage 1) and write the parity-tuned SVG bytes into top-level
	 * settings (Stage 2). Entries whose scope isn't 'top' are ignored here —
	 * repeater-scoped emissions are executed by `executeRepeaterSvgEmissionPlan`
	 * after `processRepeater` has built the per-item settings arrays.
	 *
	 * @param array             $plan          The svgEmissionPlan array.
	 * @param \DOMNode          $body          The DOM body (for document ownership).
	 * @param SvgPreserver|null $svg_preserver Preserver for resolving raw SVG bytes.
	 * @param array             $settings      Settings array (mutated).
	 */
	private static function executeTopLevelSvgEmissionPlan(
		array $plan,
		\DOMNode $body,
		?SvgPreserver $svg_preserver,
		array &$settings
	): void {
		if ( null === $svg_preserver || empty( $plan ) ) {
			return;
		}
		$doc = $body->ownerDocument;
		foreach ( $plan as $entry ) {
			if ( ( $entry['scope'] ?? '' ) !== 'top' ) {
				continue;
			}
			$comment = $entry['comment'] ?? null;
			$key     = $entry['key'] ?? '';
			if ( ! $comment instanceof \DOMComment || '' === $key ) {
				continue;
			}

			// Stage 1: DOM mutation.
			$token_text = self::PH_OPEN3 . 'settings.' . $key . self::PH_CLOSE3;
			$token_node = $doc->createTextNode( $token_text );
			if ( $comment->parentNode ) {
				$comment->parentNode->replaceChild( $token_node, $comment );
			}

			// Stage 2: settings write (non-overwriting).
			if ( isset( $settings[ $key ] ) ) {
				continue;
			}
			$svg_string = $svg_preserver->get( (int) $entry['placeholderIndex'] );
			if ( null === $svg_string ) {
				continue;
			}
			$settings[ $key ] = self::svgBytesFor( $svg_string, $key );
		}
	}

	/**
	 * Repeater-scoped Stage 1: replace placeholder comments inside each
	 * repeater item with Mustache token text nodes (no `settings.` prefix —
	 * the surrounding `{{#repeaterKey}}` section resolves keys against the
	 * current item). Tokens for the first item appear in the template once
	 * processRepeater serializes it; siblings detach from the DOM during
	 * processRepeater, but their tokens are irrelevant after detachment.
	 *
	 * @param array    $repeater_plan The svgRepeaterEmissionPlan from AutoAnnotateGaps.
	 * @param \DOMNode $body          DOM body (for document ownership when creating text nodes).
	 */
	private static function executeRepeaterSvgEmissionPlanStage1( array $repeater_plan, \DOMNode $body ): void {
		if ( empty( $repeater_plan ) ) {
			return;
		}
		$doc = $body->ownerDocument;
		foreach ( $repeater_plan as $entry ) {
			$first_item_plan = $entry['firstItemSvgPlan'] ?? [];
			$item_refs       = $entry['itemCommentRefs'] ?? [];
			foreach ( $first_item_plan as $step ) {
				$walk_order = (int) $step['walkOrder'];
				$key        = (string) $step['key'];
				foreach ( $item_refs as $item_index => $refs ) {
					if ( ! isset( $refs[ $walk_order ] ) ) {
						continue;
					}
					$comment = $refs[ $walk_order ];
					if ( ! $comment instanceof \DOMComment || null === $comment->parentNode ) {
						continue;
					}
					$token_text = self::PH_OPEN3 . $key . self::PH_CLOSE3;
					$token_node = $doc->createTextNode( $token_text );
					$comment->parentNode->replaceChild( $token_node, $comment );
				}
			}
		}
	}

	/**
	 * Repeater-scoped Stage 2: after processRepeater has built per-item
	 * settings arrays, merge in the synthetic SVG bytes for each item.
	 * Non-overwriting — if an item already has the key (e.g. agent-annotated
	 * SVG inside that item), the existing value wins.
	 *
	 * @param array             $repeater_plan The svgRepeaterEmissionPlan.
	 * @param SvgPreserver|null $svg_preserver Preserver for resolving raw SVG bytes.
	 * @param array             $settings      Settings array (mutated).
	 */
	private static function executeRepeaterSvgEmissionPlanStage2(
		array $repeater_plan,
		?SvgPreserver $svg_preserver,
		array &$settings
	): void {
		if ( null === $svg_preserver || empty( $repeater_plan ) ) {
			return;
		}
		foreach ( $repeater_plan as $entry ) {
			$repeater_key    = (string) ( $entry['repeaterKey'] ?? '' );
			$first_item_plan = $entry['firstItemSvgPlan'] ?? [];
			$item_indices    = $entry['itemPlaceholderIndices'] ?? [];
			if ( '' === $repeater_key
				|| ! isset( $settings[ $repeater_key ] )
				|| ! is_array( $settings[ $repeater_key ] )
			) {
				continue;
			}
			foreach ( $first_item_plan as $step ) {
				$walk_order = (int) $step['walkOrder'];
				$key        = (string) $step['key'];
				foreach ( $item_indices as $item_index => $indices ) {
					if ( ! isset( $indices[ $walk_order ] ) ) {
						continue;
					}
					if ( ! isset( $settings[ $repeater_key ][ $item_index ] )
						|| ! is_array( $settings[ $repeater_key ][ $item_index ] )
					) {
						continue;
					}
					if ( isset( $settings[ $repeater_key ][ $item_index ][ $key ] ) ) {
						continue;
					}
					$svg_string = $svg_preserver->get( (int) $indices[ $walk_order ] );
					if ( null === $svg_string ) {
						continue;
					}
					$settings[ $repeater_key ][ $item_index ][ $key ] = self::svgBytesFor( $svg_string, $key );
				}
			}
		}
	}

	/**
	 * Synthetically emit the bytes that the parser would store for an
	 * agent-annotated SVG with this key. Loads the raw SVG via XML parsing
	 * (case-sensitive attribute preservation, no DOMDocument HTML4 mangling),
	 * stamps `data-field` on the root element, then routes through
	 * `serializeSvg` — the same path agent-annotated SVGs already take. Result
	 * matches JS's `normalizeSelfClosingSvg(el.outerHTML)` output byte-for-byte
	 * for the codec parity gate.
	 *
	 * If the raw SVG can't be parsed as XML (e.g. HTML entities like &nbsp;
	 * that XML doesn't recognize), returns the raw string as a fallback. Codec
	 * fixtures must therefore use XML-stable SVG markup.
	 *
	 * @param string $svg_string      Raw SVG bytes (full `<svg>...</svg>`).
	 * @param string $data_field_key  The data-field key to stamp on the root.
	 * @return string SVG bytes with `data-field` set, normalized by serializeSvg.
	 */
	public static function svgBytesFor( string $svg_string, string $data_field_key ): string {
		$scratch = new \DOMDocument();
		$prev    = libxml_use_internal_errors( true );
		$loaded  = $scratch->loadXML( $svg_string );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( ! $loaded || ! $scratch->documentElement ) {
			return $svg_string;
		}

		$svg_el = $scratch->documentElement;
		$svg_el->setAttribute( 'data-field', $data_field_key );
		return self::serializeSvg( $svg_el );
	}

	// ─── SVG Serialization ─────────────────────────────────────────────

	/**
	 * Serialize an SVG element preserving camelCase attributes.
	 *
	 * @param \DOMElement $el The SVG element.
	 * @return string The serialized SVG HTML.
	 */
	private static function serializeSvg( \DOMElement $el ): string {
		$attrs = '';
		foreach ( $el->attributes as $attr ) {
			$name   = self::restoreSvgAttrCase( $attr->nodeName );
			$attrs .= ' ' . $name . '="' . htmlspecialchars( $attr->nodeValue, ENT_QUOTES, 'UTF-8' ) . '"';
		}

		$inner = self::getSvgInnerHTML( $el );

		return '<svg' . $attrs . '>' . $inner . '</svg>';
	}

	/**
	 * Get inner HTML of an SVG element, preserving SVG-specific attributes.
	 *
	 * @param \DOMNode $node The SVG node.
	 * @return string The inner HTML.
	 */
	private static function getSvgInnerHTML( \DOMNode $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$html .= self::serializeSvgElement( $child );
			} else {
				$html .= $node->ownerDocument->saveHTML( $child );
			}
		}
		return $html;
	}

	/**
	 * Serialize any SVG child element (path, circle, rect, g, etc.).
	 *
	 * @param \DOMElement $el The SVG child element.
	 * @return string The serialized element.
	 */
	private static function serializeSvgElement( \DOMElement $el ): string {
		$tag   = $el->tagName;
		$attrs = '';
		foreach ( $el->attributes as $attr ) {
			$name   = self::restoreSvgAttrCase( $attr->nodeName );
			$attrs .= ' ' . $name . '="' . htmlspecialchars( $attr->nodeValue, ENT_QUOTES, 'UTF-8' ) . '"';
		}

		if ( ! $el->hasChildNodes() ) {
			return '<' . $tag . $attrs . ' />';
		}

		$inner = self::getSvgInnerHTML( $el );
		return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
	}

	/**
	 * Restore camelCase SVG attribute names that DOMDocument lowercased.
	 *
	 * @param string $attr The lowercased attribute name.
	 * @return string The correctly-cased attribute name.
	 */
	private static function restoreSvgAttrCase( string $attr ): string {
		static $map = [
			'viewbox'             => 'viewBox',
			'preserveaspectratio' => 'preserveAspectRatio',
			'basefrequency'      => 'baseFrequency',
			'stddeviation'       => 'stdDeviation',
			'filterunits'        => 'filterUnits',
			'gradientunits'      => 'gradientUnits',
			'gradienttransform'  => 'gradientTransform',
			'patternunits'       => 'patternUnits',
			'patterntransform'   => 'patternTransform',
			'clippathunits'      => 'clipPathUnits',
			'maskunits'          => 'maskUnits',
			'maskcontentunits'   => 'maskContentUnits',
			'spreadmethod'       => 'spreadMethod',
			'textlength'         => 'textLength',
			'lengthadjust'       => 'lengthAdjust',
			'startoffset'        => 'startOffset',
			'glyphref'           => 'glyphRef',
			'attributename'      => 'attributeName',
			'repeatcount'        => 'repeatCount',
			'baseprofile'        => 'baseProfile',
		];

		return $map[ $attr ] ?? $attr;
	}
}
