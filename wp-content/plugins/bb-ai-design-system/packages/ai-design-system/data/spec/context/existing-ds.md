### Building on an Existing Design System

A site design system is provided below. You MUST:
- Use the provided tokens — do NOT redefine them
- Do NOT output Tokens or Reset sections — they already exist
- Do NOT output `/* @base */` CSS or JS sections — base assets are established and read-only
- **Before adding anything to `/* @page */`, check the provided base CSS and base JS.** If the class, keyframe, or function you need is already defined in `/* @base */`, use it as-is by referencing the class in your HTML or calling the function — do NOT redeclare it under `/* @page */`. Redeclaring duplicates the rule, risks specificity conflicts, and means each new page reauthors what should be inherited from the DS. This applies to every category below: if a shared class, keyframe, or helper is already in `/* @base */`, use it; do not redeclare it.
- Use `/* @page */` for any CSS or JS that should be shared across sections on this page **and is not already provided by the existing base CSS or base JS**. Categories that belong in `/* @page */`:
    - **Utility classes** reused across sections (e.g., `.bb-flex-center`)
    - **Shared animations** (`@keyframes`) referenced from multiple sections
    - **Helper functions** in JS used by 2+ sections
    - **Any other class shared across 2+ sections** that isn't already covered by base CSS — for example, a wrapper or accent element rendered in multiple sections with the same styling. Place the rule under `/* @page */`, **not** under any one `/* @section */` marker (which would leave the other sections unstyled). Choose class names that fit the design; there is no required vocabulary.
- Test for `/* @page */` placement: **if two or more sections need the same rule and the rule is not already in `/* @base */`, it goes under `/* @page */`.** Section markers are for rules genuinely scoped to that section (e.g., `.experience-header { background: #000 }`).
- Use the existing base CSS classes (`.bb-container`, `.bb-btn`, etc.) in your HTML
- Reuse existing Base JavaScript utilities rather than redefining them
- Output Section CSS (one `/* @section {Label} */` block per section)
- You may reference any token from the provided design system in your section CSS
- If you need a value not covered by existing tokens, use a literal value (do not invent new tokens)
- When pairing color tokens for background and text, verify the combination provides readable contrast — check the token values in the `:root` block, don't assume names like "surface" or "accent" imply light or dark
