### Building on an Existing Design System

A site design system is provided below — its tokens, reset, base CSS, base JS, and fonts are already established. You MUST:

- Set `mode: "use_existing_ds"` in the tool call.
- **Omit `design_system` entirely.** Do not refill tokens, reset, base CSS, base JS, or fonts. They already exist on disk; redefining them is a no-op at best and a conflict at worst.
- Read the provided base CSS and base JS before writing section code. If a class, keyframe, or helper function is already defined there, reference it as-is. **Do not redeclare it** in `page.css`, `page.js`, or `sections[i].css/js` — redeclaring duplicates the rule, risks specificity conflicts, and means each new page reauthors what should be inherited from the DS.
- Use only the listed fonts. Do not introduce new Google Fonts.
- Reference any token from the provided `:root` in your section CSS. If you need a value not covered by an existing token, use a literal — do not invent new tokens.
- When pairing color tokens for background and text, verify the hex values in the provided `:root` give readable contrast. Names like "surface" or "accent" do not guarantee lightness.

**Where to put shared rules.** If two or more sections on this page need the same rule and the rule is not already in the provided base CSS, put it in `page.css`, not under one section's `css`. Same for JS: helpers used by 2+ sections go in `page.js`. Categories that belong in `page.css`:

- **Utility classes** reused across sections (e.g., `.bb-flex-center`).
- **Shared animations** (`@keyframes`) referenced from multiple sections.
- **Any other class shared across 2+ sections** that isn't already covered by base CSS — for example, a wrapper or accent element rendered in multiple sections with the same styling. Pick class names that fit the design; there is no required vocabulary.

Section-scoped rules — rules genuinely specific to one section — go in that section's `css`.
