# Page Generation Spec

The markdown files under this directory are the shared source of truth for the page-generation format contract. They are not loaded as a single document. Each consumer composes the fragments it needs.

There are three consumers:

- **The WordPress chat**, at runtime, via JavaScript imports of individual fragments. Three top-level generation specs assemble prompts for the in-product build agent:
  - [generation-spec.js](../../frontend/src/core/ai/specs/generation-spec.js) — the page HTML path. The agent emits a complete HTML document with `@tokens` / `@reset` / `@base` / `@section` markers, and the importer parses it.
  - [generation-structured-spec.js](../../frontend/src/core/ai/specs/generation-structured-spec.js) — the page structured path. The agent fills a typed JSON payload (`design_system`, `page`, `sections`) and the importer composes the document. Experimental, see "Status" below.
  - [block-generation-spec.js](../../frontend/src/core/ai/specs/block-generation-spec.js) — the generate-block tool. Emits one or more HTML blocks with matching CSS.

  Several chat sub-agents and helpers also import individual `data/spec/` fragments directly rather than going through one of the three specs above (for example the brief-guidance generator, the write and update-settings sub-agents, the base prompt builder, and the style-guide handler).

- **The MCP server**, at runtime, via [FormatSpecLoader.php](../../backend/Mcp/Support/FormatSpecLoader.php), which composes the spec for external clients calling MCP abilities. A handful of abilities also load specific fragments directly (e.g. `AnalyzePageDesign`, `GenerateStyleGuide`).

- **The design-kit build script**, at build time, via [scripts/build-design-kit.mjs](../../../../scripts/build-design-kit.mjs). The script composes selected `core/` fragments into a static `data/design-kit/spec/format-contract.md` and copies a couple of `consumer/` and `context/` files into the kit. Agents that build design kits read those generated files, not `data/spec/` directly, but the source of truth is here.

The HTML and structured paths are both first-class. The HTML path is not on a deprecation track. The block tool and the design-kit build both use the HTML path today.

## Subdirectories

### `core/`

Shared rule fragments that apply across emission paths: body elements, inner structure, stylesheet format, design tokens, JavaScript rules, annotations, forms, Google Fonts, quality standards, reset CSS, mixed content, native CSS and JS, settings merge, and the codec authoring guide. All three consumers pull from here. If a rule applies regardless of how the agent emits its output, it belongs in `core/`.

### `context/`

Situational fragments organized by *what is happening*: starting a new design system, using an existing one, editing an existing page, gathering creative input, applying a style guide. When a situation needs a different fragment for the structured path, the variant gets a `-structured` suffix and lives next to its HTML peer (`new-ds.md` + `new-ds-structured.md`, `existing-ds.md` + `existing-ds-structured.md`). All three consumers pull from here; each picks the variants that match its path and use case.

### `consumer/`

Complete consumer-facing documents rather than rule fragments — a format-spec intro, an example document, and the art-direction brief guidance. Mixed audience:

- `brief-guidance.md` is shared across all three consumers (the chat brief-guidance sub-agent imports it, MCP abilities load it, and the design-kit build script copies it into the kit as `art-direction-guidance.md`).
- `format-spec-intro.md` and `format-spec-example.md` are loaded only by the MCP loader. The in-chat specs embed equivalent intro framing directly in their prompt strings.

### `codec-fixtures/`

JSON test fixtures that drive cross-implementation parity tests between the JS annotation codec and the PHP `AnnotationReconstructor`. Loaded only by tests. Not part of any consumer's runtime prompt. See [core/codec-authoring-guide.md](core/codec-authoring-guide.md) for what these fixtures encode and how to add new ones.

### `structured/`

Fragments unique to the structured path that have no HTML peer: framing for typed tool-call emission, and a tightened JavaScript discipline that replaces `core/javascript.md` for the structured path. Only `generation-structured-spec.js` imports these files.

## Layout rule

The split between `context/` and `structured/` follows one rule:

- A fragment goes in `context/` when it has both an HTML and a structured variant for the same situation. The two variants live side by side, and the structured one gets a `-structured` suffix.
- A fragment goes in `structured/` when it is unique to the structured path and has no HTML peer.

When adding a new fragment, decide which of those two cases applies before picking a directory. Keeping paired files together in `context/` is what makes drift between the two emission modes visible during edits.

## Status

`structured/` and the two `-structured.md` files in `context/` are an exploratory footprint. We are evaluating a structured-output approach behind the scenes through an experimental generate-page-structured tool in the WordPress chat. The direction has not been decided.

If structured output sticks, the HTML and structured paths will likely coexist long-term, and this directory may be reshaped to make the split between the two paths clearer at the top level. Until that decision is made, do not preemptively refactor the layout, and do not assume either path is on the way out.

## Not to be confused with

[`data/design-kit/spec/`](../design-kit/spec/) holds the *generated* design-kit format contract and art-direction guidance, produced from this directory by `scripts/build-design-kit.mjs`. Edit the source files here, not the generated copies there.
