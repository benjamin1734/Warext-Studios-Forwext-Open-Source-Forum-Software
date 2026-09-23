# Responsive mobile / desktop controls

Roadmap step 16.04 establishes one typed responsive contract for the native PHP frontend and the optional modern frontend.

## Breakpoints

The shipped manifest defines non-overlapping mobile, tablet and desktop ranges. Breakpoint bounds are validated and arbitrary media-query text is never accepted from configuration.

## Overrides

Responsive rules target a fixed internal target enum and support:

- visible/hidden/inherit visibility;
- bounded font scaling;
- bounded spacing scaling;
- auto/stack/row/grid layout modes.

The compiler maps targets to fixed first-party selectors. Configuration cannot inject CSS selectors or raw CSS declarations.

The shared spacing-scale custom property is consumed by native shell spacing, while layout and font overrides compile directly inside their breakpoint media queries.

## Mobile navigation

The native header includes a real button with `aria-expanded` and `aria-controls`. The menu JavaScript is progressive enhancement:

- without JavaScript, navigation remains visible and usable;
- after enhancement, mobile navigation starts collapsed;
- the button toggles expanded state;
- Escape closes the menu and returns focus to the button;
- choosing a navigation link closes the menu.

## Accessibility and motion

The shell includes a keyboard skip link, visible `:focus-visible` outlines and a stable main-content landmark id.

A global `prefers-reduced-motion: reduce` rule minimizes transitions/animations/scroll effects. This complements the specific reduced-motion handling already present in role appearance and animated backgrounds.

## RTL preparation

The document now declares an explicit default `dir="ltr"`. Navigation behavior uses direction-aware CSS and the skip-link uses logical inset properties. Later language/theme steps can set `dir="rtl"` without changing component contracts.

## Security / permission / audit

Responsive configuration is immutable shipped configuration in this step. It does not accept request input and cannot change authorization; hidden UI is never treated as a permission boundary.

No new permission, audit event or migration is required. Future Appearance Studio write surfaces must authorize and audit responsive mutations through backend controls.

## Deployment

The native PHP compiler and small first-party mobile-navigation script are included in normal cPanel packages. No Redis, worker, WebSocket, Docker, Node.js, npm, SSH or Supervisor runtime is required.
