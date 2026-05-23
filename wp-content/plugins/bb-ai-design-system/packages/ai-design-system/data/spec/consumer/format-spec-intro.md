# Page Format Specification

You are building a web page. Your output should be a complete, self-contained HTML document -- no commentary, no markdown fences, no explanation.

This document defines the HTML format for pages that can be imported into WordPress -- either the block editor or Beaver Builder. Pages written in this format are parsed mechanically -- no AI processing is needed for import. Write the file in one pass -- tokens first, then reset, base, section CSS, then HTML.

```
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>[Page Title]</title>
  [Google Fonts <link> tags]
  <style>
    [Complete stylesheet with comment markers]
  </style>
  [Optional: <script> block with section markers]
</head>
<body>
  [Top-level semantic elements only]
</body>
</html>
```

**Default: sections only.** Only include `<header>` and `<footer>` when the user explicitly requests them (e.g., a self-contained landing page with its own navigation). Most pages use the site's existing header and footer, so omit them unless told otherwise.

**Adapting an existing HTML file.** If the user has handed you an HTML file from another tool (a page they built elsewhere, a download, an export) and wants it imported, your job is to adapt it minimally to this format -- not rewrite it. Preserve their content, structure, and styling verbatim. The only changes you should make are mechanical: add the comment markers (`@tokens`, `@reset`, `@base`, `@section X`) to their existing CSS, wrap top-level content in `<header>`/`<section>`/`<footer>` if it isn't already, add `data-label` and (where useful) `data-field` annotations, and pull `:root` custom properties under the `@tokens` marker. Do not reword copy, redesign layouts, or substitute their CSS for "cleaner" CSS. Aim for the lightest possible touch that produces a parseable document; the importer is forgiving and will surface specific warnings if anything fails to match.