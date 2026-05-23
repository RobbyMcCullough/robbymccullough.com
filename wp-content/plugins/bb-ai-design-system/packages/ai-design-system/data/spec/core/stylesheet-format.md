## Stylesheet Format

Place a single `<style>` block in `<head>`. Use comment markers to separate sections.

**Marker rules:**
- Use EXACTLY this format — `/* @marker */`. Do NOT use decorative separators, box-drawing characters, multi-line comment blocks, or any variation (e.g., no `/* === @tokens === */`, no `/* --- @section Hero --- */`). The system parses these markers programmatically.
- The label in `/* @section {Label} */` should use the element's `data-label` value (e.g., `/* @section Feature Grid */` for `data-label="Feature Grid"`)
- Each section's CSS is scoped to its class selector (e.g., `.hero { }`, `.hero .card { }`). Never use ID selectors for styling. **Exception:** rules whose selectors target a class that appears in the HTML of 2+ sections belong under `/* @base */` so every page that uses this DS inherits them — not under any one section's marker, where only that section gets the styling. When an existing DS makes `/* @base */` read-only, place these rules under `/* @page */` instead.
- `@keyframes` blocks go under the `/* @section */` marker for the section that uses them
- `@media` queries for a section go under that section's marker
- A global responsive `@media` block that affects multiple sections can go under `/* @base */`
- The `@` prefix is required. `/* section Hero */` (without `@`) will not be parsed correctly in JavaScript blocks.

### Sync Checklist

Before writing your stylesheet, verify that each section's CSS marker matches the `data-label`:
- HTML: `<section id="feature-grid" class="feature-grid" data-label="Feature Grid">`
- CSS:  `/* @section Feature Grid */`
- JS:   `/* @section Feature Grid */`

Common mistakes:
- WRONG: `/* @section Features */` (abbreviated, doesn't match data-label)
- RIGHT: `/* @section Feature Grid */` (matches data-label exactly)

### Base CSS Class Names

All reusable class names defined in `/* @base */` MUST use the `bb-` prefix (e.g., `.bb-container`, `.bb-btn`, `.bb-badge`). This prevents conflicts with site themes and CSS frameworks like Bootstrap. The `bb-` prefix is a namespace convention for conflict avoidance, not a reference to Beaver Builder. Section-specific classes (under `/* @section */` markers) do not need the prefix.

Create whatever base utility classes the design needs — containers, buttons, badges, cards, grids, etc. Only add classes to `@base` that are genuinely reused across multiple sections. Most styles belong in section CSS.

### CSS Background Image Promotion

When a section's CSS uses `background-image: url('https://...')` with an external URL, the parser auto-promotes the URL to an editable image field (see `annotations.md`). The stored CSS is rewritten to read from a custom property, and the matching HTML element is tagged with an inline custom property that carries the current URL:

```css
/* Authored */
.hero { background-image: url('https://example.com/hero.jpg'); }

/* Stored (after parse) */
.hero { background-image: var(--settings-hero-background); }
```

```html
<section class="hero" style="--settings-hero-background: url('https://example.com/hero.jpg')">
  ...
</section>
```

Rules:
- Only `background-image` is supported. The `background:` shorthand is left untouched.
- Class-chain selectors only (`.hero`, `.hero .card`). Pseudo-classes, structural selectors, attribute selectors, and combinators other than descendant are skipped.
- Top-level rules and rules inside `@media` blocks are supported. Variants inside `@media` blocks produce additional fields with ordinal suffixes (e.g., `hero_background` and `hero_background_2`). Other at-rules (`@supports`, `@container`) and native CSS nesting are skipped in v1.
- Multi-value `background-image` is supported: gradients and non-external URLs stay as literal CSS; each qualifying `url()` becomes its own field.
- Do not manually remove the `var(--settings-*)` wrapper or the matching inline `--settings-*` custom property. The parse-reconstruct pipeline relies on the pattern to round-trip field edits.
- To opt out: add `aria-hidden="true"` to the element, or `/* @no-field */` immediately before the declaration.
