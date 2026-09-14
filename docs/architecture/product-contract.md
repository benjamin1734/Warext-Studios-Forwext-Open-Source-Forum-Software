# Forwext Product Contract

Status: Normative for the 1.0 development line.  
Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0)

## 1. Product identity

Forwext is an independent open-source forum platform developed by Warext Studios. It is not a XenForo compatibility layer, not a theme, not a plugin bundle and not a temporary MVP. The target is a production-ready 1.0.0 forum platform with its own core, first-party systems, administration experience, extension APIs and deployment model.

Official naming family:

- Forwext
- Forwext Core
- Forwext ACP
- Forwext SDK
- Forwext UI
- Forwext API
- Forwext Modules
- Forwext Add-ons
- Forwext CLI
- Forwext React
- Forwext Next

## 2. Runtime baseline

The supported baseline for 1.0 is:

- PHP 8.4 minimum; PHP 8.4 and PHP 8.5 are mandatory CI targets.
- MySQL/MariaDB are first-class databases.
- Apache, LiteSpeed and Nginx are supported web servers.
- Root-domain and subdirectory installations are supported.
- HTTP/HTTPS, Cloudflare and reverse-proxy deployments are explicit test targets.

The production application must not depend on Node.js, npm, Composer, Redis, SSH, systemd, Supervisor or persistent workers on the minimum cPanel/shared-hosting profile.

## 3. Deployment profiles

### Shared / cPanel profile

A standard user uploads the release ZIP, extracts it, opens `/install`, provides database/site/admin details and completes installation without shell access. Release packages contain production dependencies and built frontend assets. Database/local fallbacks exist for cache, queue, sessions, search and scheduled work where appropriate.

### Advanced server profile

A VDS, dedicated server, container or cloud deployment may enable Redis, persistent workers, object storage, external search and realtime transports without changing domain/business code.

Hosting-specific behavior is selected through drivers and capability detection, not duplicated application implementations.

## 4. Architectural boundaries

Forwext is divided into mandatory core, first-party integrated systems and third-party add-ons.

### 4.1 Mandatory core

Core contains functionality without which the product is no longer a coherent forum platform:

- bootstrap and dependency injection;
- HTTP/routing/security primitives;
- database and migrations;
- user domain and authentication;
- shared roles/groups/permissions;
- node/forum hierarchy;
- threads and posts;
- moderation foundations;
- notifications;
- search and content registration foundations;
- administration foundation;
- module/add-on extension foundations.

Mandatory core cannot be uninstalled by administrators.

### 4.2 First-party integrated systems

The following are native Forwext systems and are part of the 1.0 product scope where the binding roadmap places them:

- Moderation Workspace;
- Independent Moderation Audit;
- Support Tickets;
- FAQ integrated with Support;
- Bug Reports and the member-facing “My Bug Reports” area;
- Portfolio;
- Referral / Invitation;
- AI Content Moderation;
- Spell Checking;
- User Content Manager;
- Thread Freshness;
- Giveaway;
- Easter Egg;
- Trophy / Badge / Achievement;
- User Promotions;
- Marketplace;
- internal and external purchase modes;
- Subscription / User Upgrades;
- Advertising / Notices;
- Forum Analytics.

They are developed from scratch for Forwext and share users, roles/permissions, notifications, search, moderation, audit, attachments/media, analytics, queue/scheduler, settings and UI/theme infrastructure as applicable.

A first-party system may expose `enabled`, `disabled` and `uninstalled` lifecycle states where technically safe. Disabling must not delete retained data. Destructive data removal requires a separate, explicit and audited action.

### 4.3 Third-party add-ons

Third-party extensions live outside mandatory core and use documented public extension points. Supported extension mechanisms include routes, entities, migrations, permissions, settings, ACP pages, jobs/cron, search, notifications, content types, UI slots, widgets, editor extensions, API, webhooks and React components/assets.

Editing core files is not a supported extension API.

## 5. Unified service rule

First-party systems are not isolated mini-products. They must reuse common Forwext services.

Examples:

- Support and FAQ share users, permissions, notifications, search and analytics.
- Moderation Workspace consumes common report/approval/discipline state rather than maintaining a disconnected identity model.
- AI Content Moderation participates in the common content pipeline.
- User Content Manager acts through normal domain services and produces normal audit events.
- Thread Freshness operates on the real thread lifecycle rather than copying thread state.
- Bug Reports may attach privacy-safe route/request/module context from the core runtime.
- Marketplace orders/disputes connect to shared users, permissions, notifications, audit and support.

No first-party system may create a second authentication system, permission engine, moderation identity layer, notification engine or user identity model.

## 6. User/profile contract

The 1.0 user/profile scope includes privacy/visibility controls, profile media, role/permission-aware profile music and role/permission-aware custom profile URLs with reserved names, uniqueness, redirect history, change limits and abuse prevention.

Profile music must respect browser autoplay restrictions, mobile behavior and source security. Custom profile URLs must not allow route takeover or reserved-name abuse.

## 7. Permission contract

Every protected system uses the shared permission engine.

The target model supports primary group, secondary groups, roles, global permissions, node/forum permissions, numeric permissions, per-user overrides and deterministic allow/deny/inherit behavior.

Hiding a UI control is never sufficient authorization. Backend checks are mandatory for protected actions. The ACP must ultimately provide a permission analyzer capable of explaining why a user does or does not have an effective permission.

## 8. Frontend contract

The native PHP frontend is mandatory and first-class on the minimum hosting profile.

The final platform also contains:

- versioned REST API;
- webhooks;
- TypeScript SDK;
- React UI/extension system;
- optional official Next.js frontend.

React/Next.js support must not make Node.js a production requirement for users choosing the native PHP frontend, and shared backend rules must keep permission/validation behavior consistent across clients.

## 9. Customization and usability contract

Forwext administrators can manage substantial presentation and site composition without editing application source. The final product includes design tokens, component appearance, gradients/backgrounds/patterns, responsive overrides, layout regions/UI slots, widgets, drag/drop layout building, theme inheritance, revisions, preview/rollback and language/phrase management.

Complex administration surfaces are simple by default and deep on demand. Safe defaults, progressive disclosure, Basic/Advanced modes, explanations, search, preview and recovery mechanisms follow `docs/architecture/usability-constitution.md`.

Customizability must never bypass authorization or leak protected content.

## 10. Data safety contract

Normal application updates preserve user data.

All schema evolution uses versioned migrations. Database resets are not part of routine development or production update after the first persistent installation milestone at **03.03**, when the migration/install/upgrade engine is complete enough for the first real hosting installation.

Destructive migrations must be explicit, reviewable and backed by appropriate safety/recovery strategy.

## 11. Release contract

Every releasable version after packaging is operational produces:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

The full package installs the current version from zero. The update package moves the immediately previous supported Forwext version forward without database reinstallation.

The updater validates source/target versions, runs migrations, applies add/replace/delete manifests, rebuilds required caches/templates/indexes and performs health verification. Site-specific config/uploads/storage data is not blindly overwritten or deleted.

## 12. Clean-room rule

Other forum products may be studied for behavior, feature coverage, deployment lessons and architectural trade-offs. Proprietary source code, templates, phrases, database schemas, private identifiers or assets are not copied into Forwext.

Forwext defines its own names, schema, contracts, APIs, UI and source implementation.

## 13. Completion rule

A roadmap item is not complete merely because a route, class, mock, empty page or skeleton exists. Completion requires the production behavior appropriate to that step plus relevant failure handling, security/permission implications, migrations, tests, acceptance checks and documentation.

Critical placeholders, known data-loss risks, broken supported-hosting behavior or failing required tests block completion.
