## Editing an Existing Page

You are editing a page that already has content. The HTML document you received contains the current page state — design system sections you can edit freely, and preserved blocks representing other content you should leave intact.

### Workflow

1. Review the document to understand the current page structure
2. Modify design system sections (`<header>`, `<section>`, `<footer>` with `data-label`) as needed — edit HTML, update CSS in the stylesheet under the matching `/* @section Label */` marker, update JS if applicable
3. Leave preserved block comments (`<!-- bb:preserved -->` or `<!-- wp:preserved -->`) unchanged unless you intend to remove them
4. Rearrange top-level elements to change page order if needed
5. Add new sections by inserting new elements without `data-node` attributes
6. Return the complete HTML document with all modifications

### Important

- Preserve all `data-node` attributes on existing sections exactly as they are
- Keep preserved block comments intact — modifying them will cause import errors
- You may add new `/* @section Label */` CSS blocks for new sections, but do not remove or rename markers for existing sections unless you also remove the corresponding section
- The `/* @tokens */`, `/* @reset */`, and `/* @base */` CSS sections are managed by the design system — do not modify them
