# ACP information architecture

Roadmap step 17.01 introduces the central Administration Control Panel information architecture without replacing the backend permission checks of existing modules.

## Entry point and access

The native PHP ACP dashboard is `/admin`.

The dashboard itself requires `acp.access` on the backend. This permission already belongs to the common permission engine and is not replaced by a new administrator bypass.

Every navigation item also declares one or more module permissions. A surface is shown only when the actor has at least one declared permission required to enter that surface. The target surface keeps its own backend checks, so ACP navigation is a discovery layer rather than an authorization substitute.

## Global search

Global ACP search is bounded to 80 UTF-8 bytes and searches only static first-party navigation metadata:

- label;
- description;
- section;
- safe first-party path;
- keywords.

Search results are filtered by backend permissions before they are returned. The search never scans secrets, custom JavaScript, theme sources, ticket bodies, bug details, user data or arbitrary database fields.

## Action-needed queues

The dashboard currently summarizes real operational queues that already exist in the product:

- support tickets in `open` or `in_progress`;
- bug reports in `new` or `in_review`;
- moderation report groups in `open` or `in_review`;
- moderation tasks in `open` or `in_progress`.

Each database count is executed only after its corresponding backend permission passes. This prevents queue-size leakage to ACP users who do not have access to that subsystem.

## Favorites and recent areas

Favorites and recent navigation are per-user UX preferences stored in `forwext_admin_navigation_preferences`.

They are bounded, deduplicated and filtered against the actor's current visible navigation set. If permission is later revoked, a stale favorite/recent key stops rendering.

Opening an ACP destination and toggling a favorite are POST operations protected by the dedicated `admin-navigation` CSRF middleware. The recent list is therefore not mutated by cross-site GET requests.

Favorites and recents are not security policy or content state and are intentionally not written to the central audit stream. Administrative mutations performed after navigation remain audited by their owning services.

## Redirect safety

The dashboard never accepts an arbitrary target URL.

Navigation destinations come from the typed first-party registry and are validated to one of the supported management path families:

- `/admin...`;
- `/moderation...`;
- `/support/staff...`;
- `/bugs/staff...`.

The POST handler resolves a registry key, verifies the actor can access it, records recent navigation, then redirects to the validated same-origin path through the configured base path.

## Breadcrumbs and sectioning

The dashboard ships a reusable native PHP breadcrumb renderer and groups entries into a small number of sections:

- Moderasyon ve Destek;
- Ticaret ve Gelir;
- Analiz ve Raporlama;
- Görünüm;
- Topluluk Araçları.

The design keeps the default view understandable while leaving room for later ACP steps to extend users/roles/forums, integrations, system health and module management.

## Deployment profile

17.01 adds one additive/idempotent MySQL/MariaDB table for per-user navigation UX state.

It does not add Composer, npm, Node.js, Redis, workers, WebSocket, Docker, SSH or Supervisor requirements. The ACP remains a first-class native PHP surface on the minimum cPanel profile.
