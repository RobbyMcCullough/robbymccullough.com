<?php

namespace FL\DesignSystem\Mcp\Abilities\DesignSystems;

use FL\DesignSystem\DesignSystem\DesignSystemPostType;
use FL\DesignSystem\DesignSystem\GuidanceProcessor;
use FL\DesignSystem\Mcp\BaseAbility;
use FL\DesignSystem\Page\PageImporter;

/**
 * MCP ability: create-design-system.
 *
 * Creates a design system from a full HTML document, the same shape
 * generate-page consumes, with <body> allowed to be empty. The <head>
 * carries the tokens / reset / base / fonts / base JS via the standard
 * comment markers and Google Fonts <link> tags.
 */
class CreateDesignSystem extends BaseAbility {

	public function name(): string {
		return 'beaver-builder-ai/create-design-system';
	}

	public function definition(): array {
		return [
			'label'               => 'Create Design System',
			'description'         => 'Pass a complete HTML document via `html`. All CSS must be embedded inside `<style>` blocks in `<head>` and all JavaScript inside `<script>` blocks. There are no separate `css` or `js` parameters. Creates a design system from a full HTML document with <head> containing tokens / reset / base / fonts / base JS, and <body> empty. Same document shape as generate-page; call get-format-spec with has_design_system: false to see the format. Use generate-page (not this tool) when the user wants a page alongside the design system; use this tool when the user wants the design system on its own (e.g., importing a brand spec, setting up a kit before page generation). This is the single canonical path for spec-bearing input: if the user has supplied a brand spec, design brief, or design system document, call this tool first; if generate-page is then called, pass the returned UUID to it via design_system_uuid. Tokens canonicalize to `--ds-{category}-{name}` in the structured map regardless of what the source spec called them. Both `brief` and `guidance` are required; empty placeholders are not accepted. SPEC PRESERVATION RULES (apply when a spec is supplied -- see the `guidance` field description for full detail and examples): preserve the spec\'s PROSE into `guidance` verbatim (voice, headings, tables, anti-patterns, on-brand phrasing, HTML structural examples). TOKEN REFERENCES (`var(--name)` and `--name: value;` declarations) must translate to one of: (a) the canonical `--ds-{category}-{name}` token when emitted in the same call; (b) the literal value (hex, rgba, px) when no ds-token exists; (c) plain descriptive prose. Never reference a token name that does not exist in the design system. POSITIONAL RULE FOR IDENTIFIERS: identifiers translate only in code positions; backtick mentions anywhere in prose (including tables, lists, headings, and checklists) are documentation positions and stay verbatim even when the prose references the identifier functionally. Code positions for tokens: `var(--name)` calls and `--name: value;` declarations. Code positions for classes: `class="X"` HTML attribute values and CSS rule selector heads (`.X { ... }`). CLASS NAMES destined for `/* @base */` translate to their `bb-` prefixed form per `core/stylesheet-format.md` (`.accent` -> `.bb-accent`, `.pullquote` -> `.bb-pullquote`) in those code positions only. `/* @section */` classes are exempt from the prefix rule and are not translated. DROP from code fences entirely: `:root { ... }` declarations, bare top-level `--name: value;` custom-property declarations sitting outside any selector (the structured token map authoritatively defines token values), `[data-brand="X"] { ... }` / `[data-theme="..."] { ... }` / `[data-mode="..."] { ... }` / `[data-color-scheme="..."] { ... }` wrapper selectors, `@media (prefers-color-scheme: ...) { ... }` blocks, `@import url(...)` statements and `<link rel="stylesheet">` blocks loading fonts (or any other asset) the design system is already loading. KEEP code fences that demonstrate component / layout / interaction patterns (.pullquote, ::selection, .btn-primary, hero pill, sticky CTA), translating their token references per above. Strip YAML frontmatter only; all other prose flows through. Other env-translations: selectors on `<html>` or `<body>` describe wrapper-class behavior -- mention them in prose; multiple brand variants -- pick the canonical brand for `:root` and document alternates in `guidance` prose with their hex codes. The storage layer applies the code-fence strips as a backstop and reports any surviving unknown `var(--*)` references as warnings on the response. When no spec is provided, articulate the creative direction you applied so subsequent pages stay coherent.',
			'category'            => 'beaver-builder-ai',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'label'          => [
						'type'        => 'string',
						'description' => 'Descriptive name for the design system (e.g., "Coastal Bakery", "Editorial Dark"). Optional, falls back to the <title> tag, then "Imported Design System".',
					],
					'html'           => [
						'type'        => 'string',
						'description' => 'Complete HTML document following the format spec. All CSS belongs inside `<style>` blocks in `<head>` and all JavaScript inside `<script>` blocks; there is no separate `css` or `js` parameter. <head> contains <style> blocks marked with /* @tokens */, /* @reset */, /* @base */; optional Google Fonts <link> tags; optional inline <script> marked with /* @base */ for shared JS. <body> may be empty.',
					],
					'set_as_default' => [
						'type'        => 'boolean',
						'description' => 'Set as the site default design system. Default: true when no other default exists.',
					],
					'guidance'       => [
						'type'        => 'string',
						'description' => 'Long-form art direction document. Required. The home for everything that does not fit the structured token slots: voice, tone, accent rules, component patterns, iconography, motion language, anti-patterns, and HTML structural examples. This field has two modes; pick the one that matches what the user gave you. MODE A -- when the user has supplied a spec, design brief, or design system document: preserve the spec\'s PROSE verbatim (voice, structure, headings, tables, anti-patterns, on-brand phrasing, HTML examples). No paraphrasing, no restructuring, no "cleaning up." There is no length cap. TOKEN REFERENCES (`var(--name)` calls and `--name: value;` declarations, anywhere they appear -- prose, tables, or code fences) must resolve to one of: (a) the canonical `--ds-{category}-{name}` token when one exists in the structured token map you are emitting; (b) the literal value (hex, rgba, px) when no ds-token exists for that spec token; (c) plain prose ("the brand color") when neither fits. Never reference a token name that does not exist in the design system. POSITIONAL RULE: translation happens only in *code positions*. For tokens, code positions are `var(--name)` calls and `--name: value;` declarations. For classes, code positions are `class="X"` HTML attribute values (`<span class="accent">` -> `<span class="bb-accent">`) and CSS rule selector heads inside code fences (`.accent { ... }` -> `.bb-accent { ... }`). Anywhere else, including backtick mentions in prose, tables, list items, headings, or checklists (e.g., "use the `--container` token for max-width", "exactly one `.accent` word per heading"), is a *documentation position* and stays verbatim, EVEN WHEN the prose references the identifier functionally. Do not rewrite the spec\'s voice. Downstream page-building agents work from the actual emitted CSS and the translated HTML examples, not from prose snippets. CLASS NAMES destined for `/* @base */` get the `bb-` prefix per `core/stylesheet-format.md`; classes destined for `/* @section */` markers are exempt and are not translated. DROP these code-fence blocks entirely (the storage layer also strips them as a backstop): `:root { ... }`, bare top-level `--name: value;` custom-property declarations sitting outside any selector, `[data-brand="..."] { ... }` / `[data-theme="..."] { ... }` / `[data-mode="..."] { ... }` / `[data-color-scheme="..."] { ... }`, `@media (prefers-color-scheme: ...) { ... }`, `@import url(...)` statements and `<link rel="stylesheet">` blocks loading fonts (or any other asset) the design system is already loading. KEEP code fences that demonstrate component / layout / interaction patterns (`.pullquote`, `::selection`, `.btn-primary`, hero pill, sticky CTA, dark-section overrides) -- translate their token references per above. Strip YAML frontmatter only; all other prose flows through. EXAMPLES TO FOLLOW: (a) PRESERVE PROSE VERBATIM -- GOOD: spec says "backdrop-filter: blur(24px)" -> guidance says "backdrop-filter: blur(24px)"; BAD: guidance rounds to "blur(12px)". (b) PRESERVE STANDALONE SUBSECTIONS -- GOOD: spec has Hero pill, Pullquote, Sticky CTA as separate H3s -> guidance preserves all three as separate H3s; BAD: guidance folds them under one "Components" H2. (c) TRANSLATE TOKEN REFERENCE WHEN ds-EQUIVALENT EXISTS -- GOOD: spec has `var(--brand)` and emitted tokens include `--ds-color-brand` -> guidance has `var(--ds-color-brand)`; BAD: guidance keeps `var(--brand)`. (d) INLINE LITERAL WHEN NO ds-EQUIVALENT -- GOOD: spec has `border: 1px solid var(--brand-glow-md)` (no ds-token for it) -> guidance has `border: 1px solid rgba(252,176,64,0.18)`; BAD: guidance keeps the broken `var(--brand-glow-md)` reference. (e) DROP `:root` BLOCK -- GOOD: spec has a "Quick-start CSS" `:root { ... }` block -> guidance drops it; BAD: guidance preserves it (conflicts with the design system\'s own root tokens). (f) KEEP COMPONENT EXAMPLE WITH TOKEN TRANSLATION -- GOOD: spec has a `.pullquote { border-left: 3px solid var(--brand); }` block -> guidance keeps the block translated to `.bb-pullquote { border-left: 3px solid var(--ds-color-brand); }`; BAD: guidance keeps `.pullquote` and `var(--brand)` unchanged (selector does not exist in `@base`; token does not exist either). (g) TRANSLATE CLASS REFERENCE IN HTML EXAMPLE -- GOOD: spec has `<span class="accent">VOICE</span>` and the emitted `@base` CSS defines `.bb-accent` -> guidance has `<span class="bb-accent">VOICE</span>`; BAD: guidance keeps `<span class="accent">VOICE</span>` (page-building agents will copy a class that does not exist in `@base`). (h) STAY VERBATIM ON BACKTICKED PROSE MENTIONS -- GOOD: spec prose says "Use the `--container` token for max-width" or "exactly one `.accent` word per heading" -> guidance keeps the backticks and the original names (`--container`, `.accent`) unchanged, because those are documentation positions not code positions; BAD: guidance rewrites the prose to "Use the `var(--ds-width-container)` token" or "exactly one `.bb-accent` word per heading" (over-translation -- the rule applies only to `var(...)` calls, `--name: value;` declarations, `class="..."` HTML attributes, and `.X { ... }` CSS rule selectors). MODE B -- when the user has not supplied a spec: articulate the creative direction you applied so subsequent pages can stay coherent. Observational tone (e.g., "the palette centers on warm earth tones"), ~1,200 word soft cap, no required sections. Safe inline HTML (spans, emphasis, links) is preserved in both modes.',
					],
					'brief'          => [
						'type'        => 'string',
						'description' => 'Short paragraph (under 200 words) capturing identity context: what the brand is, who it is for, product family. Required. If the spec contains an obvious identity paragraph, copy it verbatim. Otherwise synthesize one from the spec or from the design you applied. Empty placeholders are not accepted.',
					],
				],
				'required'   => [ 'html', 'guidance', 'brief' ],
			],
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'permission' ],
			'meta'                => [
				'annotations' => [
					'readonly'    => false,
					'destructive' => false,
				],
				'mcp'         => [ 'public' => true ],
				'subcategory' => 'design-systems',
				'summary'     => 'Creates a design system from a full HTML document',
			],
		];
	}

	public function execute( array $input ) {
		$html     = isset( $input['html'] ) && is_string( $input['html'] ) ? $input['html'] : '';
		$guidance = isset( $input['guidance'] ) && is_string( $input['guidance'] ) ? $input['guidance'] : '';
		$brief    = isset( $input['brief'] ) && is_string( $input['brief'] ) ? $input['brief'] : '';
		$label    = isset( $input['label'] ) && is_string( $input['label'] ) ? $input['label'] : null;

		if ( '' === trim( $html ) ) {
			return new \WP_Error(
				'missing_params',
				'html is required and must be a non-empty string.',
				[ 'status' => 400 ]
			);
		}

		if ( '' === trim( $guidance ) ) {
			return new \WP_Error(
				'missing_params',
				'guidance is required and must be a non-empty string. Pass the user-supplied brand spec verbatim, or articulate the creative direction you applied.',
				[ 'status' => 400 ]
			);
		}

		if ( '' === trim( $brief ) ) {
			return new \WP_Error(
				'missing_params',
				'brief is required and must be a non-empty string. Capture the identity context (what the brand is, who it is for) in under 200 words.',
				[ 'status' => 400 ]
			);
		}

		$result = PageImporter::create_design_system_from_html( $html, $label );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post     = $result['post'];
		$warnings = $result['warnings'];
		$uuid     = get_post_meta( $post->ID, DesignSystemPostType::META_UUID, true );

		// Set as site default if explicitly requested or if no default exists.
		$set_default     = $input['set_as_default'] ?? null;
		$current_default = DesignSystemPostType::get_default_uuid();

		if ( true === $set_default || ( null === $set_default && null === $current_default ) ) {
			DesignSystemPostType::set_default( $uuid );
		}

		$guidance = GuidanceProcessor::strip_conflicting_blocks( $guidance );

		update_post_meta( $post->ID, DesignSystemPostType::META_GUIDANCE, DesignSystemPostType::sanitize_guidance( $guidance ) );
		update_post_meta( $post->ID, DesignSystemPostType::META_BRIEF, DesignSystemPostType::sanitize_guidance( $brief ) );

		$structured = DesignSystemPostType::get_structured_data( $post );

		$unknown_tokens = GuidanceProcessor::find_unknown_token_refs( $guidance, array_keys( $structured['tokens'] ) );

		if ( ! empty( $unknown_tokens ) ) {
			$warnings[] = sprintf(
				'Guidance references tokens not present in the design system: %s. These will not resolve at render time.',
				implode( ', ', $unknown_tokens )
			);
		}

		return [
			'uuid'        => $uuid,
			'label'       => $post->post_title,
			'token_count' => count( $structured['tokens'] ),
			'is_default'  => DesignSystemPostType::get_default_uuid() === $uuid,
			'warnings'    => $warnings,
			'message'     => "Design system \"{$post->post_title}\" created. If you call generate-page next, you MUST pass design_system_uuid \"{$uuid}\" -- dropping it will create a duplicate design system, drop the spec you just stored, and trigger an unwanted analyze-page-design + update-design-system-guidance follow-up chain. If the user only wanted the design system on its own (kit setup, brand spec import), no further calls are required.",
		];
	}
}
