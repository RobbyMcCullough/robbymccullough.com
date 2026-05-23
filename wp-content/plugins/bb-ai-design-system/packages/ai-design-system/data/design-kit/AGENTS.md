# Design Kit

A kit for building websites with a local AI agent. The output imports into WordPress (Beaver Builder or the Block Editor).

## IMPORTANT

DO NOT use your thinking block to plan the design. DO NOT describe what you are about to build. Your first tool call after reading format file must be a write tool call to create the code.

DO put your creative energy into the CSS and HTML. Agents who go straight to writing code produce higher-quality pages. No preamble, no plan. After reading the format file, output a short response letting the user know you're creating the page. After that, your very next action must be to write code. NO EXCEPTIONS.

## Building Pages

Read `spec/format-contract.md` before writing any HTML. The format is parsed mechanically — deviations break import.

Build pages in `pages/`. The homepage is always `index.html`.

### Design Kit Adaptations

The format contract describes a single self-contained document. In a design kit, the design system CSS and JS is shared across pages:

- **`/* @tokens */`, `/* @reset */`, `/* @base */`** go in `design-system/styles.css` — not inline in each page
- Each page links to it: `<link rel="stylesheet" href="../design-system/styles.css">`
- JS utilities needed across pages go in `design-system/script.js` and link via `<script src="../design-system/script.js"></script>`
- Each page contains only /* @page */ and /* @section */ CSS (in <style>) and JS (in <script>).

**If `design-system/styles.css` doesn't exist**, create it first — that means a new design system. Write tokens, reset, and base CSS there. Write shared JS utilities in `design-system/script.js`. Once created, don't regenerate `@tokens`, `@reset`, or `@base` on subsequent pages and don't update `design-system/styles.css` or `design-system/script.js`. This is enforced by the import system — changes to these files on subsequent pages won't reach WordPress.

- Only link `design-system/script.js` if it exists — not every design system needs shared JS.
- If a section needs a value not covered by existing tokens, use a literal value. Don't invent new tokens.

## Page Metadata

Page metadata is embedded in the HTML itself, not in kit.json:

- **Title:** Use the standard `<title>` tag. This becomes the WordPress post title on import.
- **Post type:** Use `<meta name="post-type" content="page">` to specify the WordPress post type. Defaults to `"page"` if omitted.
- **Slug:** Derived from the filename (e.g., `pages/about-us.html` -> slug `about-us`).

## Navigation

Unless anchoring to an element on the same page, use # for all links — real URLs are set in WordPress after import.

## Header & Footer

Headers and footers are separate files in `globals/header.html` and `globals/footer.html`. Same format as pages with two differences: they link to the shared design system, and the style/script blocks use a single `/* @section Header */` or `/* @section Footer */` marker (no tokens, reset, or base). The body contains a single `<header>` or `<footer>` element.

When creating the first page in a design kit, ask the user if they would like a header and footer as well. 

## Style Guides

If the user asks for a style guide or test sheet page that showcases the design system, read `spec/style-guide.md` for the content structure (identity card + showcase sections).

## kit.json

Identity-only manifest. All other metadata (pages, globals, design system details) is discovered from the filesystem and HTML content during import.

```json
{
  "uuid": "",
  "name": "",
  "description": ""
}
```

- **`uuid`** -- Unique identifier linking the kit to a design system. When creating a fresh kit, generate one (e.g., `crypto.randomUUID()`). When the kit is downloaded from an existing design system in WordPress, the DS's UUID is pre-filled.
- **`name`** -- Human-readable kit name. Also used as the design system name on import.
- **`description`** -- Brief description of the kit.