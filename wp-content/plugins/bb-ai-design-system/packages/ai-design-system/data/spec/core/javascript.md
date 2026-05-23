## JavaScript

JavaScript is optional — only include it when sections need interactive behavior. Keep it minimal; pages should work without JS.

**Code rules:**
- Vanilla JavaScript only. No external libraries or CDN imports.
- Target elements using the section's class so scripts cannot reach across sections: `document.querySelectorAll('.hero .target')`.
- Never put CSS at-rules (`@media`, `@keyframes`, `@supports`, `@font-face`) inside the script — they are CSS syntax and will throw a parse error in JS. Put them in the `<style>` block.
- If you need JS to branch on a media query at runtime (e.g. to skip a JS-driven animation when `prefers-reduced-motion: reduce` is set), use `window.matchMedia('(prefers-reduced-motion: reduce)')`.
- Never use a bare `DOMContentLoaded` listener — the event may have already fired. Define an `onReady` helper in `@base` and use it everywhere:
```js
function onReady(fn) {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fn);
  } else {
    fn();
  }
}
```

### Markers and scope

Every JS statement must live under a marker. Write `/* @base */`, `/* @page */`, or `/* @section {Label} */` above each block. Unmarked trailing statements at the end of the script are silently absorbed into the preceding section and will break.

Each section is isolated at runtime — the block editor renders blocks in separate iframes. A function declared in one section is not reachable from another. Plan accordingly:

- **Section-specific behavior** (header scroll state, hero load class, card hover, etc.): define and wire it **inside that section's `@section` block**. Each section must call its own `onReady(...)` to kick off its own init.
- **Cross-cutting behavior** that watches elements across multiple sections (e.g. an IntersectionObserver that observes targets anywhere on the page): define and wire it inside **`@base`** by default. `@base` is the right home for any pattern future pages should inherit by simply using a class — for example, an observer over `.bb-reveal` keyed to a class you also define in base CSS. Self-invoke the kickoff in `@base` so the behavior just works on any page that adds the class. Reach for **`@page`** only when the behavior is genuinely specific to one page and won't generalize: a one-off countdown timer, an image lightbox initialized only on a gallery page, an observer keyed to selectors no other page would use. When an existing design system makes `@base` read-only, use `@page`. Page DOM is dynamic — settings edits and tools insert blocks after init, so `@base` observers must pair the IntersectionObserver with a `MutationObserver` to pick up newly-inserted targets. Use `.bb-reveal` for the initial hidden state and `.is-visible` for the revealed state.
- **No centralized wiring.** Do not try to call multiple sections' init functions from a single `onReady` block — it will only run in one iframe and will ReferenceError on the others.

`@base` and `@page` may each appear more than once and are concatenated in order. If it reads more naturally to put a cross-cutting wiring call after the sections, open a second `/* @base */` or `/* @page */` block at the end instead of leaving the call unmarked.

### Example

Cross-cutting wiring goes inside `@base`; each section self-wires inside its own `@section`. Unmarked trailing statements are silently absorbed into the preceding section, and each section is iframe-isolated so functions don't cross sections.

```js
/* @base */
function onReady(fn) { /* ... */ }
onReady(() => {
  const io = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      e.target.classList.add('is-visible');
      io.unobserve(e.target);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
  const observe = el => io.observe(el);
  document.querySelectorAll('.bb-reveal').forEach(observe);
  new MutationObserver(muts => muts.forEach(m => m.addedNodes.forEach(n => {
    if (n.nodeType !== 1) return;
    if (n.matches?.('.bb-reveal')) observe(n);
    n.querySelectorAll?.('.bb-reveal').forEach(observe);
  }))).observe(document.body, { childList: true, subtree: true });
});

/* @section Hero */
onReady(() => {
  const hero = document.querySelector('.hero');
  if (!hero) return;
  // hero-specific setup
});
```
