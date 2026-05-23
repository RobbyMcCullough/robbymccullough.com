## Reset CSS

Every new design system includes a standard reset that prevents WordPress theme styles from interfering with your design. Use this exact reset in your `/* @reset */` section:

```css
/* @reset */
*, *::before, *::after { box-sizing: border-box; }
body, h1, h2, h3, h4, h5, h6, p, blockquote, figure { margin: 0; }
h1, h2, h3, h4, h5, h6 { color: inherit; font: inherit; }
a { text-decoration: none; color: inherit; }
img { max-width: 100%; height: auto; display: block; }
blockquote { padding: 0; border: none; }
```

You may add to this reset if the design requires it. If you need additional element resets beyond this standard set, add them in `/* @base */` instead.
