## Design Tokens

Tokens are CSS custom properties named `--ds-{category}-{name}` and declared in exactly one `:root { ... }` block. Categories are listed below.

**Required categories:**
- `color` — all colors (primary, secondary, surface, text, accent)
- `font` — font families (heading, body, mono)
- `space` — spacing values (xs, sm, md, lg, xl, section)
- `text` — font sizes (xs, sm, base, lg, xl, 2xl)
- `weight` — font weights. At minimum: `--ds-weight-regular` (400), `--ds-weight-medium` (500), `--ds-weight-semibold` (600), `--ds-weight-bold` (700). Add `--ds-weight-light` (300) or higher grades when the design uses them. Every weight token must match a weight requested in the Google Fonts `<link>` tag.

**Optional categories** (use as needed):
- `radius` — border radii
- `shadow` — box shadows
- `transition` — transition durations/easings
- `line` — line heights
- `letter` — letter spacing
- `width` — max-widths, container widths

Use tokens consistently across all sections. Every color, font, spacing value, and font size should reference a token.

### Canonicalize tokens regardless of source spec naming

When importing a brand spec — whether it uses `--brand` / `--ink` / `--surface`, `primary` / `secondary` / `surface`, semantic role names, or anything else — output tokens as `--ds-{category}-{name}` using this mapping:

- hex / rgb / rgba / hsl / hsla values → `--ds-color-{name}`
- rgb component tuples (`252, 176, 64`) → `--ds-color-{name}-rgb` (paired with the canonical color token)
- font family stacks → `--ds-font-{name}`
- spacing / gap dimensions → `--ds-space-{name}`
- border radii → `--ds-radius-{name}`
- shadow values → `--ds-shadow-{name}`
- layout widths and container sizes → `--ds-width-{name}`
- durations / easing → `--ds-transition-{name}`
- line heights → `--ds-line-{name}`
- letter spacings → `--ds-letter-{name}`
- everything else → `--ds-{best-category}-{name}`

Do not emit the source spec's literal token names alongside `--ds-*` aliases. Pick the canonical name and use it. The spec's original token names can be mentioned in the `guidance` prose so the relationship is documented for the user.

The token names declared in `:root` are the only `--ds-*` custom properties available. Do not invent token names. The custom properties created by Background Image Promotion are system-generated; do not author them in CSS.

### Tokens go in exactly one `:root { ... }` block

Do not emit `[data-X]` selectors, media queries (including `prefers-color-scheme`), `:where(...)` blocks, or any other rules in the `/* @tokens */` section. The storage layer extracts custom properties from the first `:root` block and drops everything else with a warning. If the source spec uses runtime-variant patterns (selector overrides, dark-mode blocks, alternate theme files), see the variants guidance under "Creating a New Design System".
