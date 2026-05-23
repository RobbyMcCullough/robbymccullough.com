## Body Elements

The `<body>` must contain ONLY top-level semantic elements: `<header>`, `<section>`, and `<footer>`.

- `<section>` — all page content (hero, features, pricing, testimonials, CTAs, etc.)
- `<header>` — site-level navigation only (nav bar, logo, site menu)
- `<footer>` — site footer

Each top-level element MUST have:
- `id` — kebab-case slug (e.g., `id="feature-grid"`)
- `class` — same value as the id (e.g., `class="feature-grid"`)
- `data-label` — human-readable label (e.g., `data-label="Feature Grid"`)

The `id` is for anchor links. The `class` is for CSS — always use the class selector, never the ID selector.

Example:
```html
<section id="hero" class="hero" data-label="Hero">...</section>
<section id="feature-grid" class="feature-grid" data-label="Feature Grid">...</section>
<section id="pricing" class="pricing" data-label="Pricing">...</section>
```

Sections must be direct children of `<body>`. Do not wrap them.

WRONG:
```html
<body><div class="page-wrap"><section>...</section></div></body>
```
