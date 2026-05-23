JavaScript is optional. Only include it when interactive behavior is needed.

Vanilla JS only. No external libraries.

Never use a bare `DOMContentLoaded` listener; always go through an `onReady` helper.

Never put CSS at-rules (`@media`, `@keyframes`, `@supports`, `@font-face`) inside the script — they will throw a parse error in JS.

Use `window.matchMedia` for runtime media-query branching.
