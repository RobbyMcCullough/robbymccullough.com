## Native CSS

Applies to native Beaver Builder modules, rows, and columns. Their CSS is saved to the `bb_css_code` field and BB scopes it automatically at render time by wrapping your CSS in `.fl-node-{id} { … }` and SCSS-compiling.

### The hard rule

**Never include this node's own `.fl-node-{id}` class in your CSS.** The system adds the wrapper scope. If you write it yourself, BB wraps it again and the resulting double-prefixed selector matches nothing.

```css
/* WRONG — BB will wrap this in .fl-node-{id} { … }, producing a no-op selector */
.fl-node-abc123 .fl-module-content { background: yellow; }

/* RIGHT — bare class selectors only */
.fl-module-content { background: yellow; }
```

### Targeting a specific child by node ID (rows and columns only)

For container nodes (rows and columns), it is occasionally useful to target one specific child module by its node ID — for example, when the child's classes aren't unique enough and you don't want to add a custom class. That's allowed: BB wraps your CSS in *this* node's wrapper, so referencing a *child* node's class is a normal descendant selector.

```css
/* In a row's CSS, target one specific child module */
.fl-node-childId123 { background: yellow; }
/* Compiles to: .fl-node-{rowId} .fl-node-childId123 { background: yellow; } */
```

Prefer the child's own classes (`.fl-module-heading`, etc.) when they're specific enough — child-node-ID selectors are brittle if the child is later replaced.

### Root vs descendant

The transform layer detects which classes belong to the node's root element and handles compounding for you. Author plain class selectors either way.

- **Class on the root element** (e.g. `.fl-module`, `.fl-row`, `.fl-col`): write `.fl-module { … }`. The system compounds it onto the wrapper.
- **Class on a descendant** (e.g. `.fl-module-content`, `.fl-heading`): write `.fl-module-content { … }`. The system uses descendant scoping.

### What `&` means in CSS read back

If you read this node's CSS and see `&` in selectors, that's an SCSS reference to the node's wrapper. The transform layer adds it on save and strips it on read for class-only selectors, but it can surface in some output. **Do not author `&` yourself.** Just write plain class selectors; the system handles compounding.

### Targeting

Study the rendered HTML and `rendered_styles` to find which element actually carries an existing style. Override on the same element — if a style is on a child, target that child. Use the classes already present in the HTML.

### Design tokens

When a design system is active, prefer `var(--ds-*)` tokens for colors, spacing, typography, and other semantic values. Tokens are CSS custom properties and cascade into native CSS just like everywhere else. Hardcoded values are fine for one-off decorative work that no token expresses.
