## Compact Example

A single-section page showing the key patterns:

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Example Page</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
  <style>
/* @tokens */
:root {
  --ds-color-primary: #2b6cb0;
  --ds-color-surface: #ffffff;
  --ds-color-surface-alt: #f7fafc;
  --ds-color-text: #1a202c;
  --ds-color-text-muted: #718096;
  --ds-font-heading: 'Playfair Display', serif;
  --ds-font-body: 'Inter', sans-serif;
  --ds-text-base: 1rem;
  --ds-text-lg: 1.125rem;
  --ds-text-3xl: 2rem;
  --ds-space-md: 1rem;
  --ds-space-lg: 2rem;
  --ds-space-xl: 4rem;
  --ds-space-section: 6rem;
  --ds-radius-md: 0.75rem;
  --ds-width-container: 1140px;
}

/* @reset */
*, *::before, *::after { box-sizing: border-box; }
body, h1, h2, h3, h4, h5, h6, p, blockquote, figure { margin: 0; }
h1, h2, h3, h4, h5, h6 { color: inherit; font: inherit; }
a { text-decoration: none; color: inherit; }
img { max-width: 100%; height: auto; display: block; }
blockquote { padding: 0; border: none; }

/* @base */
body {
  font-family: var(--ds-font-body);
  font-size: var(--ds-text-base);
  color: var(--ds-color-text);
  line-height: 1.6;
  background: var(--ds-color-surface);
}
h1, h2, h3, h4, h5, h6 { font-family: var(--ds-font-heading); line-height: 1.2; }
.bb-container {
  max-width: var(--ds-width-container);
  margin: 0 auto;
  padding: 0 var(--ds-space-lg);
}

/* @section Services */
.services { padding: var(--ds-space-section) 0; background: var(--ds-color-surface); }
.services h2 { font-size: var(--ds-text-3xl); margin-bottom: var(--ds-space-md); }
.service-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--ds-space-lg); }
.service-card { background: var(--ds-color-surface-alt); padding: var(--ds-space-lg); border-radius: var(--ds-radius-md); }
.service-card h3 { margin-bottom: var(--ds-space-md); }
.service-card p { color: var(--ds-color-text-muted); }
  </style>
  <script>
  /* @base */
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  /* @section Services */
  onReady(function() {
    // section-specific init (event listeners, tabs, carousels, etc.)
  });
  </script>
</head>
<body>

<section id="services" class="services" data-label="Services">
  <div class="bb-container">
    <h2 data-field="services_title">What We Offer</h2>
    <p data-field="services_description">Flexible options to fit your schedule.</p>
    <div class="service-cards" data-repeater="services">
      <div class="service-card" data-repeater-item>
        <h3 data-field="title">Solo Walks</h3>
        <p data-field="description">One-on-one attention for dogs who prefer their own adventure.</p>
      </div>
      <div class="service-card" data-repeater-item>
        <h3 data-field="title">Group Adventures</h3>
        <p data-field="description">Small groups of compatible dogs explore local parks together.</p>
      </div>
      <div class="service-card" data-repeater-item>
        <h3 data-field="title">Puppy Visits</h3>
        <p data-field="description">Mid-day check-ins for puppies in training.</p>
      </div>
    </div>
  </div>
</section>

</body>
</html>
```

This demonstrates:
- Top-level `<section>` with `id`, `class`, and `data-label`
- `.bb-container` inner wrapper
- Comment-marked CSS: `@tokens`, `@reset`, `@base`, `@section {Label}`
- `--ds-*` design tokens used consistently
- `data-field` annotations on editable text
- `data-repeater` / `data-repeater-item` for repeating content
- JavaScript with `@base` and `@section` markers
- Responsive breakpoint