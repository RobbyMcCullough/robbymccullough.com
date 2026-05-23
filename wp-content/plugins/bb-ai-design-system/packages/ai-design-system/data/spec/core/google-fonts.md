## Google Fonts

Include `<link>` tags in `<head>` for any Google Fonts used. Use the current Google Fonts API format, and make the `family=<Name>:<variants>` segment match the weights and styles the CSS actually uses. The design system preserves this spec end-to-end — the published page loads exactly the variants you request.

Minimal example:

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
```

Multi-axis example (italic, optical sizing, variable weight range):

```html
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,700;1,9..144,400&display=swap" rel="stylesheet">
```

Guidance:

- Only request weights and styles the CSS actually uses. Don't pad with weights the page never renders.
- If you declare `--ds-weight-*` tokens, the variants requested here must cover every weight those tokens reference.
- If any section CSS sets `font-style: italic` on a Google Font, the family must request its italic axis (`ital,wght@0,...;1,...`).
- Prefer variable-weight ranges (`wght@100..900`) only for display faces that genuinely need the flexibility; otherwise list discrete weights.
