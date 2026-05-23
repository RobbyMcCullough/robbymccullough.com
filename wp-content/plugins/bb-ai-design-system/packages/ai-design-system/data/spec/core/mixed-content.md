## Mixed Content

When editing an existing page, the document may contain both **design system sections** and **preserved blocks**. Each type has different editing rules.

### Design System Sections

Standard `<header>`, `<section>`, and `<footer>` elements with `data-label` and `data-node` attributes. These are fully editable — you have complete creative control over their HTML, CSS, and JS. Follow all the same rules as page creation.

### Preserved Blocks

HTML comments marking the position of non-design-system content (Beaver Builder modules or WordPress blocks). They look like:

```html
<!-- bb:preserved type="button" label="CTA Button" node="abc123" -->
<!-- wp:preserved type="core/image" label="Hero Image" index="3" -->
```

**Rules for preserved blocks:**
- **Do not modify** the comment content, attributes, or format
- **You may reorder** preserved blocks relative to other elements — their position in the document determines their position on the page
- **You may remove** a preserved block to delete that content from the page
- **Do not add** new preserved block comments — only the system creates these

### The `data-node` Attribute

Design system sections include a `data-node` attribute (e.g., `data-node="abc123"`). This is an internal identifier used to match sections back to stored data on re-import.

- **Do not modify or remove** `data-node` attributes on existing sections
- **Do not add** `data-node` to new sections you create — the system assigns these automatically
- If you create a new section, simply omit `data-node`

### Reordering

The order of top-level elements in `<body>` determines the page layout order. You can freely rearrange design system sections and preserved blocks to change the page structure. For example, moving a `<section>` above a `<!-- bb:preserved -->` comment repositions that section before the preserved block on the page.

### Adding and Removing Content

- **Add new sections** by inserting new `<header>`, `<section>`, or `<footer>` elements (without `data-node`) following the standard format spec
- **Remove sections** by deleting them from the document — sections with a `data-node` that are absent from the updated document will be deleted from the page
- **Remove preserved blocks** by deleting the comment — the corresponding block will be deleted from the page
