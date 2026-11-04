# Palette's UX & Accessibility Journal

## 2026-11-04 - Accessible Interactive Card Grids Using Non-Interactive Elements
**Learning:** In galleries or grid layouts, rendering item cards as non-interactive elements (like `<figure>`) without `tabindex`, keyboard handlers, or focus-visible styling makes whole sections of content inaccessible to keyboard-only and screen reader users, while folder links (`<a>`) are naturally navigable.
**Action:** When item cards perform an action (e.g., opening a modal/lightbox), ensure `<figure>` or `<div>` cards include `tabindex="0"`, `role="button"`, descriptive `aria-label`, visible `:focus-visible` ring, and both `Enter`/`Space` keydown event handlers.
