## Native JavaScript

Applies to native Beaver Builder modules, rows, and columns. Their JS is saved to the `bb_js_code` field and runs on the published page as plain JavaScript.

### Runtime model

Vanilla JS, runs globally on the page. **No iframe isolation, no `@base` / `@page` / `@section` markers** — those are DS-block concepts and do not apply here. Don't write marker comments; they have no parser on this surface.

### Scoping

JS on native nodes runs in the page's global scope. Scope your queries:

- Prefer querying by the module's existing classes (e.g. `.fl-module-heading`, `.fl-button`).
- When the module's classes aren't unique enough, scope to the node ID at runtime: `document.querySelectorAll('.fl-node-{id} .target')`. The node ID is a real class on the rendered element — you just don't write it into CSS.
- Avoid bare globals (`window.foo = …`); use IIFEs or `const` / `let` to keep state out of the page namespace.

### Readiness

The page may already have fired `DOMContentLoaded` by the time `bb_js_code` runs. Use a readiness guard:

```js
function onReady( fn ) {
  if ( document.readyState === 'loading' ) {
    document.addEventListener( 'DOMContentLoaded', fn );
  } else {
    fn();
  }
}
```

### Constraints

- Vanilla JS only. No external libraries, no CDN imports.
- No build-time syntax (JSX, TypeScript, decorators).
- Never put CSS at-rules (`@media`, `@keyframes`, `@supports`, `@font-face`) inside the script — they are CSS syntax and will throw a parse error in JS. Put them in the `<style>` block.
- If you need JS to branch on a media query at runtime (e.g. to skip a JS-driven animation when `prefers-reduced-motion: reduce` is set), use `window.matchMedia('(prefers-reduced-motion: reduce)')`.
