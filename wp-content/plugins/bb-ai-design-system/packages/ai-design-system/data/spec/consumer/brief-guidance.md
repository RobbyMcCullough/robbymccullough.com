## Art Direction Guidance

Guidance has two modes. Pick the one that matches what the user gave you.

### Mode A: Spec provided

When the user has supplied a brand spec, design brief, or design system document (any form: free-form markdown, YAML frontmatter, plain CSS dump, JSON tokens, hybrid):

**Preserve the prose verbatim.** Copy the spec's prose, structure, headings, voice, accent rules, casing rules, component patterns, iconography rules, motion language, anti-patterns, on-brand phrasing examples, and HTML structural examples into `guidance` exactly as the user wrote them. Do not paraphrase, summarize, reorganize, impose section structure, flatten tables to bullets, or drop "Why" subsections. There is no length cap.

**Translate or remove token references.** Identifiers translate only when they appear in *code positions*: `var(--name)` calls and `--name: value;` declarations. Anywhere else — backtick mentions in prose, tables, list items, or headings (e.g., `` `--brand` `` or `` `--container` ``) — is a *documentation position* and stays verbatim, even when the prose is referencing the token functionally (e.g., "use the `--container` token for max-width"). When a token reference does appear in a code position, it must resolve to one of:

1. The canonical `--ds-{category}-{name}` token, when one exists in the structured token map you are emitting in the same call (per `core/design-tokens.md`).
2. The literal value (hex, rgba, px), when no canonical token exists for that spec token.
3. Plain descriptive prose ("the brand color", "a slightly stronger brand tint"), when neither of the above fits the local context.

Never reference a token name that does not exist in the design system. If the spec defines a token that did not become a `--ds-*` token, drop the reference (inline its literal value or describe it in prose) — do not invent a name for it.

**Translate class names destined for `@base`.** When the spec defines reusable class names (e.g., `.accent`, `.eyebrow`, `.pullquote`) and the design system emits them under `/* @base */`, those classes must follow the `bb-` prefix rule per `core/stylesheet-format.md` (`.accent` → `.bb-accent`, `.pullquote` → `.bb-pullquote`). The same positional rule applies: translation happens only in *code positions* — `class="X"` HTML attribute values inside HTML examples (`<span class="accent">` → `<span class="bb-accent">`) and CSS rule selector heads inside code fences (`.accent { ... }` → `.bb-accent { ... }`). Anywhere else — backtick mentions in prose, tables, list items, or headings (e.g., "use the `accent` class for first words" or a checklist item that says "exactly one `.accent` word per heading") — is a *documentation position* and stays verbatim, even when the prose references the class functionally. The spec's voice is preserved; downstream page-building agents work from the actual emitted CSS and the translated HTML examples, not from prose snippets. Classes destined for `/* @section */` markers are exempt from the prefix rule per `core/stylesheet-format.md`, so they are not translated.

**Drop these code-fence block kinds entirely** (the storage layer also strips them as a backstop, but the agent should drop them at transcription so the saved guidance is clean):

- `:root { ... }` declarations — the design system owns root tokens.
- Bare top-level `--name: value;` custom-property declarations sitting outside any selector. The structured token map authoritatively defines token values; documenting them again in a guidance code fence is redundant duplication.
- `[data-brand="X"] { ... }`, `[data-theme="..."] { ... }`, `[data-mode="..."] { ... }`, `[data-color-scheme="..."] { ... }` — wrapper-class theme selectors don't apply on a one-brand WordPress site.
- `@media (prefers-color-scheme: ...) { ... }` — single-brand site, no scheme switching.
- `@import url(...)` statements and `<link rel="stylesheet">` blocks loading fonts (or any other asset) the design system is already loading.

**Keep code fences that demonstrate component / layout / interaction patterns** — `.pullquote { ... }`, `::selection { ... }`, `.btn-primary { ... }` rules, hero pill, sticky CTA shadow, dark-section overrides, etc. Translate token references inside them per the three landings above.

**Strip YAML frontmatter only.** All other prose flows through.

**Other env-translations:**

- **Selectors on `<html>` or `<body>`** in the source spec describe wrapper-class behavior. Mention them in prose ("the spec applies a wrapper class for X behavior"); do not emit the selectors in the structured tokens.
- **Multiple brand variants** via `[data-brand="X"]`, `[data-theme="dark"]`, `prefers-color-scheme`, or sibling theme files. WordPress is one site; one brand applies. Pick the canonical brand (asking the user if ambiguous, defaulting to the parent or first-listed brand if obvious from context). Set those values as the `:root` token defaults. Document the alternative variants in `guidance` prose with their hex codes so the user has a record.

If something does not fit any of the rules above, preserve it verbatim. When in doubt about prose, preserve. When in doubt about a token reference, drop it to its literal value.

### Mode B: No spec

When the user has not supplied a spec and you are articulating the creative direction you applied:

Write an art direction document describing the design you created. Observational tone ("the palette centers on warm earth tones", "the accent appears on interactive elements"), not prescriptive ("always use blue for buttons"). Be concrete and specific. Reference token names (e.g., `--ds-color-primary`) and class names (e.g., `.bb-btn`) by name where relevant.

Soft cap around 1,200 words. Length should match the design's complexity; a simple kit needs less, a rich one more. There are no required sections; cover whatever is meaningful for this particular design (color, typography, components, motion, atmosphere, voice if the page content reveals one). Skip what is not.

## Using Creative Direction

When a design system has existing guidance, use it to maintain visual coherence across pages while bringing fresh creative energy to each new page. If the guidance preserves a user's spec verbatim, treat its rules as the contract; deviate only when the user explicitly asks for something different.

## Business Context Guidance

After the art direction document, output a section starting with exactly `## Business Context` on its own line.

Extract the business and organization context from the creative brief:
- Who the business/organization is
- What they do (products, services, mission)
- Their target audience
- Their brand identity or values

Exclude page-specific details (sections, layout, page purpose). Write a concise summary (under 200 words). If the brief contains no business context, write "No business context provided."
