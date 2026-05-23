## Layout patterns

Multi-child containers should use CSS that handles child-count changes gracefully. The editor allows users to add and remove elements; hard-coded track counts (e.g. `grid-template-columns: 1fr 1fr 1fr`) leave empty cells when a child is removed.

### Default: auto-fit grid for interchangeable children

For layouts where children share the same role and equal weight — card grids, feature lists, logo strips, most repeaters — default to:

```css
.cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: var(--ds-space-lg);
}
```

The `minmax` value is design-driven — pick the smallest comfortable size for the items in this layout. Narrower for compact items (logos ~120px), wider for content cards (~240px), wider still for text-heavy items (~320px). Hard-code this length; it's a layout-mechanic threshold (when does this item become unreadable?), not a semantic spacing value, so it doesn't go through `--ds-space-*` tokens. Use tokens for `gap` and other spacing in the same rule as usual.

This pattern fills the container, reflows as children are added or removed, and wraps to multiple rows when the viewport is too narrow.

### Explicit tracks for compositional layouts

When children have distinct roles or warrant different widths — sidebar + main, asymmetric hero with image + copy, featured card + supporting cards, "icon + label" rows — use explicit tracks:

```css
.layout {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: var(--ds-space-xl);
}
```

Design intent comes first. Don't flatten an asymmetric layout into equal columns just to gain reflow behavior. An asymmetric layout that doesn't reflow on deletion is a fair trade for matching the design.
