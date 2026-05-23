# Concept Brief — Beaver Builder AI Forms

## Identity

- **Name:** Beaver Builder AI Forms
- **Slug:** beaver-builder-ai-forms
- **UUID:** 01d621b4-b1eb-4cff-99dc-5955ab416fa9
- **Description:** A showcase of form experiences built with Beaver Builder AI, styled to match the Beaver Builder AI product site.

## Concept

A self-aware showcase, not a fictional brand. The landing page says "Forms, built with Beaver Builder AI," then links to five example form pages. Visual language is ported directly from the Beaver Builder AI product site — cream paper, teal + amber, Georgia display, SF Mono for meta copy, masking-tape strips, ink-blot decorations, slight rotations, torn-edge clip-paths.

## What the showcase argues

The headline story is **"real, submittable forms, not mockups."** Every form page in this kit should be a working form that a developer can wire to an email, a webhook, or a custom action. BB AI generates the form; the kit shows what BB AI can produce.

Do **not** lean on "every string is editable." Form pages intentionally use fewer `data-field` annotations than a marketing page — too many editable fields on a form make it unmanageable in WordPress. Follow the form annotation guidance in the format contract: annotate section headings, submit button labels, and marketing copy; don't annotate every field label or placeholder.

## Source of truth

This kit's visual language is NOT invented. It is transcribed from:
- `bb-design-system/packages/product-site/frontend/css/product-site.css`
- `bb-design-system/packages/product-site-shared/frontend/css/login.css`

Tokens, palette, typography, decorative vocabulary, and voice all come from those two files. Builders working on this kit should match the product site, not innovate.

## Palette

- Ground: cream `#FAF7F2`, cream-mid `#F0EBE3`
- Ink / text: `#3D4852`, text-light `#5A6670`
- Accent primary: teal `#0E5A71`, teal-mid `#0F7499`, teal-light `#14A3D4`
- Accent secondary: amber `#FEAF52`, amber-light `#FFD699`, amber-dark `#E6993A`
- Warning / error: sienna `#7E2F17`
- Footer surface: deep teal `#0a4557`

## Typography

- **Display:** Georgia (serif) — headings, hero titles, card titles, form button labels
- **Body:** system sans (`-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif`)
- **Mono:** SF Mono stack — eyebrows, filter chips, small caps labels, stat labels

No Google Fonts. Keep it web-safe and fast.

## Decorative vocabulary

- Paper texture overlay (fixed, 3.5% opacity SVG noise)
- Ink blot decorations (amber + teal, 4–8% opacity, irregular border-radius, gentle rotation)
- Masking-tape strips (amber, amber-light; irregular edges via clip-path polygon)
- Torn-edge clip-paths on cards, buttons, inputs, and badges
- Slight rotations (0.2deg–1.5deg) on cards, buttons, eyebrows, titles
- Sienna pins (6–14px) on step cards
- Washi-tape accents floating on forms
- Dashed / repeating lines for connectors
- No drop shadows as the primary treatment — they're subtle (3–5px offsets, 5–15% opacity)

## Voice and tone

Crafted, matter-of-fact, quietly confident. Short sentences. Occasional italics for emphasis. "Describe it. Watch it come to life." over "Revolutionize your workflow!"

## Scope

- **Landing page (`pages/index.html`)** — meta showcase: hero, "what's in this kit," five form-page teaser cards, small CTA to try Beaver Builder AI.
- **Form pages (in order of creation):**
  1. `pages/contact.html` — simple name/email/message
  2. `pages/application.html` — multi-step application (progress bar, steps)
  3. `pages/event-rsvp.html` — guest count, dietary, date
  4. `pages/survey.html` — mixed input types, likert scales
  5. `pages/support.html` — category + priority triage, details, contact
- **Style guide:** optional, after forms are stable.
- **Header + footer:** match product-site partials — masking-tape strip header, dark teal footer.
- **Art direction doc:** optional, add later.

## Self-contained pages

Kit pages render in an iframe in the design-system admin — inter-page navigation does not work inside that preview. Every page in this kit must stand on its own:

- No "back to showcase" footer links on form pages
- No links between form pages
- The landing's showcase cards use `href="#"` defaults (real URLs are configured via the `data-field-href` picker in WordPress after import)
- Each page should complete its own story — intro, form, success state — without sending the user anywhere else

## Anti-patterns

- No corporate "SaaS landing" tropes — no feature-card-icon-grids, no gradient hero backgrounds, no pill-shaped CTAs without clip-paths.
- No fictional brand voice — this is honestly a BB AI showcase.
- No invented colors or typefaces. If a token isn't in the palette above, use a literal value.
- No centered body copy beyond short taglines.
- Don't replace Georgia with a Google serif — Georgia is the point.
- No cross-page links in the page body or footer — pages render in an iframe preview.
