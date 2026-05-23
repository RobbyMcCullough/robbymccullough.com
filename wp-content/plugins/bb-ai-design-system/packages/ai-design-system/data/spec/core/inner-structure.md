### Section Inner Structure

Each section should use this wrapper pattern:

```html
<section id="features" class="features" data-label="Features">
  <div class="bb-container">
    <!-- Section content here -->
  </div>
</section>
```

The outer element handles full-width backgrounds. Use an inner wrapper div to constrain content width. If you define a container class in `@base`, use it here. Full-bleed sections (e.g. galleries) may skip the inner wrapper.

Most pages need no overflow rule. If a decorative element genuinely bleeds past the viewport and page-wide clipping is necessary, apply it to `body` in `/* @base */`. Do not introduce a wrapper element.

The same default applies to sections: only add `overflow: hidden` when a section actually contains decorative bleed (an absolutely-positioned background, an oversized SVG, a rotated shape). Don't apply it as a defensive default.
