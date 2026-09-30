# Forwext Frontend Rebuild Plan v1

This document is the binding implementation order for the native PHP public frontend remediation started after 1.0.10.
The current `main` implementation and automated qualification remain the source of truth if this document becomes stale.

## Goals

- Make the public forum UI feel like a mature forum product rather than a collection of independent module cards.
- Preserve Forwext identity while borrowing proven information architecture patterns from XenForo, MyBB and vBulletin.
- Keep native PHP/cPanel runtime first-class; Node tooling may be used only for development/CI where useful.
- Stop solving visual defects by appending another override layer.

## Phase F0 — Frontend foundation

- [x] Extract static surface CSS from `ProfileHtml::page()` into `public/assets/site-base.css`.
- [x] Keep only dynamic appearance/design-token CSS inline.
- [x] Replace multiple historical navbar generations with one maintained navigation shell block.
- [x] Remove the separate oversized masthead and place brand/navigation/user tools in one compact primary bar.
- [x] Lock background scrolling while the mobile navigation drawer is open.
- [ ] Split remaining base CSS into explicit base/components/pages layers without changing runtime behavior.
- [ ] Reduce repeated breakpoint definitions to a documented responsive scale.
- [ ] Normalize button, input, card, list-row, badge, dropdown, pagination and empty-state primitives.
- [ ] Remove obsolete selectors after repository-wide usage checks.

Acceptance:
- no public page depends on static CSS embedded in PHP;
- only one maintained navbar implementation exists;
- desktop and mobile navigation expose the same destinations without overflow;
- no required cPanel runtime dependency is added.

## Phase F1 — Forum core surfaces

- [x] Forum index: compact page toolbar, category headers, readable forum rows, latest activity identity and optional widget sidebar.
- [x] Forum view: sticky/featured/locked states, clear topic metadata, last activity, pagination and permission-aware actions.
- [x] Thread view: stronger author/content hierarchy, stable post anchors, compact action bar and mobile author header.
- [ ] Reply/create flows: consistent composer shell, validation, attachment states and quick-reply path where domain services allow it.
  - Rich editor and CSRF-protected inline quick reply are wired; attachment-state UX remains open.
- [ ] Shared unread/read/status presentation hooks without inventing state not supplied by the backend.

Acceptance:
- forum/index/thread pages remain usable at 390px, 768px, 1024px and 1440px widths;
- information hierarchy is understandable without relying on color alone;
- moderator/member/guest actions remain permission-driven.

## Phase F2 — Discovery and identity

- [x] Search and results.
- [x] What's New / activity discovery.
- [x] Member directory and online users.
- [ ] Profile layout, tabs, relationship actions, trophies/badges and profile media.
  - Compact profile identity/tabs and relationship account surfaces are migrated; trophy/media detail parity remains open.
- [ ] Account center, authentication, MFA, sessions, privacy and notification settings.
  - Grouped account center, notifications, notification settings, bookmarks and shared auth/MFA/recovery presentation are migrated; remaining account/session/privacy surfaces remain open.

## Phase F3 — First-party module parity

- [ ] Marketplace.
  - Public browse, listing detail and seller surfaces are migrated; manage/purchase operations parity remains open.
- [ ] Portfolio.
  - Public index and project detail are migrated; project management parity remains open.
- [ ] Giveaways.
  - Public index/detail are migrated; giveaway management/proof surfaces still require the final module pass.
- [ ] FAQ / Wiki surfaces.
  - Public FAQ index/article are migrated; management/Wiki-adjacent surfaces remain open.
- [ ] Support and bug reports.
  - User intake, history, detail and conversation surfaces are migrated; staff dashboards remain open.
- [x] Notifications.
- [ ] Moderation workspaces.
  - Main workspace, approval queue, report review, abuse, oversight, discipline and content-manager surfaces are migrated; remaining audit/freshness sub-workspaces remain open.
- [ ] Remaining first-party modules.
  - Referral/account upgrades, user reports and account discipline history are migrated; admin-only reward/trophy/payment/promotion/advertising surfaces are tracked with ACP remediation rather than public F3 parity.

All modules must consume shared UI primitives instead of defining a separate visual language.

## Phase F4 — Responsive, accessibility and regression gates

- [ ] Keyboard/focus traversal for navigation, details/popovers, forms and post actions.
- [ ] WCAG-oriented contrast/focus/touch-target review.
- [ ] Reduced-motion coverage.
- [ ] Horizontal overflow audit.
- [ ] Browser-level smoke/regression coverage in CI.
- [ ] Representative screenshots for guest/member/moderator layouts where CI tooling permits.
- [ ] Remove temporary compatibility rules and dead CSS after visual parity is proven.

## Implementation rules

1. Do not append a new replacement block when an existing maintained block can be edited.
2. Do not copy XenForo/MyBB/vBulletin HTML/CSS; use their information architecture only as reference.
3. Backend permission/routing/domain rules remain authoritative.
4. Do not fake unread counts, views, roles, avatars or other data unavailable from backend readers.
5. Every visual refactor must retain mobile behavior and keyboard access.
6. Prefer shared primitives over module-specific one-off card/button/input variants.
7. Update tests when structure changes; string-presence tests alone are not final visual acceptance.
8. Keep full/update release packaging rules unchanged.
