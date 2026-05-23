## Annotations

All user-editable content MUST have annotation attributes so the system can generate settings forms.

### Attribute Reference

| Attribute | Purpose | Auto-inferred type |
|---|---|---|
| `data-field="key"` | Editable content | text (from tag), image (on `<img>`), svg (on `<svg>`), link (on `<a>` with `href`) |
| `data-field-href="key"` | Editable URL (on `<a>` or `<svg>`) | url |
| `data-field-type="TYPE"` | Type override | `editor` (rich text), `textarea`, `svg`, `rating` |
| `data-field-max="N"` | Maximum value for rating fields | — |
| `data-repeater="key"` | Repeater container | — |
| `data-repeater-item` | Repeater item root | — |

### Key Rules

- **Field keys** use snake_case: `hero_title`, `feature_1_heading`
- **Consistent key prefixes** for related fields: `author_name`, `author_image` — the system groups them in settings
- **All visible text** must have `data-field` (headings, paragraphs, buttons, labels, list items). Forms are an exception — see the Forms section for which form copy to annotate.
- **All images** must have `data-field` on the `<img>` tag
- **All SVG icons** that represent editable content (feature icons, service icons, logos) must have `data-field` on the `<svg>` element. Purely decorative SVGs (background patterns, dividers, flourishes) do not need `data-field`.
- **Never combine SVG icons with text in one field** — when an element contains both an SVG icon and text (e.g., an email icon next to an email address), use separate `data-field` attributes on each:
  ```html
  <a href="mailto:info@example.com">
    <svg data-field="contact_icon" viewBox="0 0 24 24">...</svg>
    <span data-field="contact_email">info@example.com</span>
  </a>
  ```
- **All links** use a single `data-field` on the `<a>` tag. The link text and `href` are captured together as one compound field; any `target` and `rel` attributes on the anchor are preserved and become editable options in the form. Example:
  ```html
  <a data-field="cta" href="https://example.com" target="_blank" rel="noopener">Get Started</a>
  ```
  Use `data-field-href` alone (without `data-field`) only when the anchor wraps a non-text element like an SVG icon and only the URL is editable:
  ```html
  <a data-field-href="twitter_url" href="https://twitter.com/...">
    <svg data-field="twitter_icon" viewBox="0 0 24 24">...</svg>
  </a>
  ```
  The paired form (`data-field` + `data-field-href` on the same `<a>`) still works for backward compatibility but the single-annotation form is preferred for new layouts.
- **Link with icon (compound + sibling icon)** — when a `data-field` anchor contains a contiguous leading or trailing SVG (or `<img>`) icon plus text, the parser extracts the icon into a sibling field named `{linkKey}_icon` (then `{linkKey}_icon_2`, etc.). The link compound keeps text + URL + target + rel bundled; the icon edits independently. Use this when you want one logical "link" control plus one "icon" control:
  ```html
  <a data-field="contact" href="mailto:hello@example.com">
    <svg viewBox="0 0 24 24">...</svg>
    hello@example.com
  </a>
  ```
  This produces `contact` (link compound) + `contact_icon` (SVG field). Mixed positions (text-icon-text) are NOT extracted — split into separate fields explicitly when you need that. The explicit-split form (`data-field-href` on the anchor + `data-field` on each child) remains supported and is preferred when only the URL is meant to be editable.
- **Decorative text** that is part of the visual design but not user-editable (emoji icons, decorative symbols, static labels) does NOT need `data-field`. Exception: visual indicators that represent a varying numeric value (star ratings, heat levels) must use `data-field-type="rating"` -- see Rating Indicators below.
- **Never nest fields** — a `data-field` element must not contain another `data-field` element. The parser captures the entire innerHTML as one value and does not process inner fields. Use sibling elements instead.
- **Only create content fields** (text, URLs, images). Never create styling fields — all visual styling belongs in CSS.
- **CSS background images auto-promote to image fields.** When a CSS rule uses `background-image: url('https://...')` with an external URL, the parser automatically creates an editable image field for it. No annotation attribute is needed on the HTML element. Class-chain selectors (`.hero`, `.hero .card`) are supported; structural, attribute, and pseudo-class selectors are not. Data URIs and other non-HTTP URLs are treated as styling and left alone. To opt an external-URL background out of promotion, add `aria-hidden="true"` to the element or a `/* @no-field */` comment immediately before the declaration.

### Repeaters

Use repeaters for ANY group of 2+ siblings of the same kind -- whether each item is rich (menus, cards, testimonials, pricing tiers) or compact (nav, links, buttons, icons, stats). If you'd otherwise repeat a field type across siblings, you need a repeater. Even 2 buttons side by side are a repeater. NEVER use numbered keys like `item1_title`, `item2_title`, and NEVER use distinct semantic keys for the same field across siblings -- one key, one repeater.

Repeater items must share the same HTML structure -- the system uses the first item as a template for all items. Any content that varies across items must be annotated as a field (see below). When items need different visual treatments (different background colors, different sizes, different styles), use item variations with descriptive CSS classes instead of inline styles or style fields -- see Item Variations below.

Both attributes are REQUIRED -- `data-repeater="key"` on the parent container, `data-repeater-item` on each direct child. Never use one without the other. All `data-repeater-item` elements must be direct children of the `data-repeater` container -- never wrap some items in a grouping div (e.g., don't put a "featured" card outside a wrapper while other cards are inside it). If you need different visual treatments for some items, use variation classes instead of structural grouping. The parent must have its own class with layout styles (flex/grid) -- never put `data-repeater` on a bare `<div>` with no class, because the attribute is stripped during processing and the element remains as an unstyled wrapper:

```html
<div class="cards" data-repeater="features">
  <div class="card" data-repeater-item>
    <svg data-field="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">...</svg>
    <h3 data-field="title">Feature Title</h3>
    <p data-field="description">Description text.</p>
  </div>
  <!-- more items... -->
</div>
```

Compact items (buttons, links, stats) work the same way — `data-repeater-item` on each, fields per item.

**Every value that differs across items must be a field.** The system only preserves content annotated with `data-field`. Unannotated content (step numbers, sequential labels, decorative counters) gets baked into the template from the first item and repeated identically. If cards show "01", "02", "03" -- those numbers need `data-field="number"` or they'll all render as "01".

This applies to authoring AND editing. If an existing block hardcodes similar siblings (literal `<li>` lists, repeated table rows with hardcoded values), the fix is to introduce a repeater -- not to match the existing hardcoded pattern.

Repeaters can nest (e.g., pricing tiers with feature lists). Place the inner `data-repeater` inside each outer `data-repeater-item`.

### Item Variations

When repeater items need different visual treatments (color swatches with different backgrounds, button styles with different appearances, progress bars with different widths), use a descriptive CSS class on each item's root element. The system automatically detects class differences across items and presents a "Variation" select in the form. All visual differences are handled in CSS targeting the variation class.

- Add a descriptive class to `data-repeater-item` elements that need distinct styling (e.g., `swatch-blue`, `swatch-green`)
- Items sharing the same base structure but with different classes automatically get a variation select
- Items without a unique class use base styles and appear as "Default" in the select
- Prefer a single descriptive class per variation -- multiple classes work but are less clean in the UI
- Never use inline styles or style attributes for per-item visual variation
- Never use variation classes for content differences -- use fields for that

```html
<!-- Color palette with class-driven backgrounds -->
<div class="palette" data-repeater="swatches">
  <div class="swatch-card swatch-blue" data-repeater-item>
    <div class="swatch-color"></div>
    <span data-field="name">Ocean Blue</span>
    <code data-field="hex">#3B82F6</code>
  </div>
  <div class="swatch-card swatch-green" data-repeater-item>
    <div class="swatch-color"></div>
    <span data-field="name">Forest</span>
    <code data-field="hex">#22C55E</code>
  </div>
</div>
```

```css
/* Each variation targets the swatch background via CSS */
.swatch-blue .swatch-color { background: #3B82F6; }
.swatch-green .swatch-color { background: #22C55E; }
```

The system detects class differences across items, uses the shared classes as the template base, and injects a variation token so each item renders with its unique class. Users can reassign variations to change an item's visual treatment without editing CSS.

### Type Hints

The system infers field types from HTML tags:
- `<h1>`–`<h6>`, `<span>`, `<label>` → short text
- `<a>` with `href` → link (compound: text + URL + target + rel)
- `<a>` without `href` → short text
- `<p>`, `<blockquote>`, `<figcaption>` → long text
- `<img>` → image
- `<svg>` → svg

Use `data-field-type` to override when needed:
- `data-field-type="editor"` on a `<div>` for rich text (paragraphs with formatting)
- `data-field-type="textarea"` for multi-line plain text
- `data-field-type="svg"` on a non-SVG element containing an SVG icon

### Rating Indicators

For visual indicators that represent a numeric value (star ratings, heat levels, skill dots, progress pips), use `data-field-type="rating"` with `data-field-max`:

- Place `data-field` and `data-field-type="rating"` on the container element
- `data-field-max` is required -- it declares the maximum value
- Children are the visual indicator elements (SVGs, spans, etc.)
- Children can have two visual states (filled/empty) or one state (active only)
- The system detects active vs inactive children by comparing class names
- Each repeater item can have a different value

**Two-state example (filled + empty stars):**
```html
<div class="stars" data-field="rating" data-field-type="rating" data-field-max="5">
  <svg class="star-filled" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z" fill="gold"/></svg>
  <svg class="star-filled" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z" fill="gold"/></svg>
  <svg class="star-filled" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z" fill="gold"/></svg>
  <svg class="star-filled" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z" fill="gold"/></svg>
  <svg class="star-empty" viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z" fill="#ddd"/></svg>
</div>
```

**Single-state example (flames with varying count per item):**
```html
<!-- Item with heat level 3 of 5 -->
<div class="heat" data-field="heat_level" data-field-type="rating" data-field-max="5">
  <svg class="flame" viewBox="0 0 24 24"><path d="M12 23c-4 0-7-3-7-7 0-5 7-13 7-13s7 8 7 13c0 4-3 7-7 7z" fill="red"/></svg>
  <svg class="flame" viewBox="0 0 24 24"><path d="M12 23c-4 0-7-3-7-7 0-5 7-13 7-13s7 8 7 13c0 4-3 7-7 7z" fill="red"/></svg>
  <svg class="flame" viewBox="0 0 24 24"><path d="M12 23c-4 0-7-3-7-7 0-5 7-13 7-13s7 8 7 13c0 4-3 7-7 7z" fill="red"/></svg>
</div>
```

**Rules:**
- Always include `data-field-max` -- the system needs it to know the scale
- Use the same SVG/element for all active children and the same for all inactive children
- Class names must be consistent within each state across all items
- Never use Unicode characters (stars, dots, dashes) for indicators -- use SVG or styled HTML elements
- In repeaters, different items can have different values (the system compares across items to detect both visual states)

### Multi-Paragraph Body Content

When a content area has multiple paragraphs, use a single field with `data-field-type="editor"` on a wrapper `<div>` -- not separate `data-field` attributes on each `<p>`.

```html
<div class="about-body" data-field="about_body" data-field-type="editor">
  <p>We started in 2015 with a simple mission.</p>
  <p>Today we serve over 500 families across Portland.</p>
</div>
```

Single headings, taglines, and short descriptions are fine as individual fields. Use `editor` when the content is body text with flowing paragraphs.
