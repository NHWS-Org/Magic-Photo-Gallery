# Palette's UX & Accessibility Journal

## 2026-11-04 - Accessible Interactive Card Grids Using Non-Interactive Elements
**Learning:** In galleries or grid layouts, rendering item cards as non-interactive elements (like `<figure>`) without `tabindex`, keyboard handlers, or focus-visible styling makes whole sections of content inaccessible to keyboard-only and screen reader users, while folder links (`<a>`) are naturally navigable.
**Action:** When item cards perform an action (e.g., opening a modal/lightbox), ensure `<figure>` or `<div>` cards include `tabindex="0"`, `role="button"`, descriptive `aria-label`, visible `:focus-visible` ring, and both `Enter`/`Space` keydown event handlers.

## 2026-11-09 - Accessible Focus Trapping and Restoration in Ephemeral Modals Launched from Dropdowns
**Learning:** When a modal dialog is launched from an item inside a dropdown menu (e.g., login modal launched from #btnLogin in a popup menu), closing the menu hides the triggering element (`offsetParent === null`). Standard focus restoration to `lastFocused` fails and dumps focus to `<body>`.
**Action:** When closing modals launched from collapsible menus, check if `lastFocused` is visible (`offsetParent !== null`); if not visible because the menu collapsed, fall back to focusing the persistent menu toggle button (`#menuToggle`).
