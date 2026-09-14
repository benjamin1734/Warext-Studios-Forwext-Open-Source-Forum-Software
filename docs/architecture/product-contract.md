# Forwext Product Contract

Status: Normative for the 1.0 development line.

## 1. Product identity

Forwext is an independent open-source forum platform. It is not a XenForo compatibility layer, not a theme, not a plugin bundle and not a temporary MVP. The target of this roadmap is a production-ready 1.0.0 forum platform with its own core, first-party modules, administration system, extension APIs and deployment model.

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

- PHP 8.4 minimum.
- PHP 8.4 and PHP 8.5 are mandatory CI targets.
- MySQL 8+ and compatible supported MariaDB releases are first-class databases.
- Apache, LiteSpeed and Nginx are supported web servers.
- Root-domain and subdirectory installations are supported.
- HTTP/HTTPS, Cloudflare and reverse-proxy deployments are explicit test targets.

The production application must not depend on Node.js, npm, Composer, Redis, SSH, systemd, Supervisor or persistent workers on the minimum cPanel profile.

## 3. Deployment profiles

### Shared / cPanel profile

The minimum profile uses only capabilities reasonably available on shared hosting. Release packages contain production dependencies and already-built frontend assets. Database-backed or local fallbacks are available for cache, queue, sessions, search and scheduled work where appropriate.

### Advanced server profile

A VDS, dedicated server, container or cloud deployment may enable Redis, persistent workers, object storage, advanced search and realtime transports without changing domain/business code.

Hosting-specific behavior is selected through drivers and capability detection, not duplicated application implementations.

## 4. Architectural boundaries

Forwext is divided into three product layers.

### 4.1 Mandatory core

Core contains the functionality without which the product is no longer a forum platform:

- bootstrap and dependency injection
- HTTP/routing/security primitives
- database and migrations
- user domain
- authentication
- permissions
- node/forum hierarchy
- threads and posts
- core moderation
- notifications
- administration foundation
- module manager

Mandatory core cannot be uninstalled by administrators.

### 4.2 First-party integrated modules

The following systems are native Forwext modules:

- Bug Reports
- Independent Moderation Audit
- Portfolio
- FAQ
- Referral / Invitation System
- AI Content Moderation
- Spell Checking
- User Content Manager
- Thread Freshness

They are developed from scratch for Forwext. Existing XenForo add-on source compatibility is not a goal.

These modules may expose `enabled`, `disabled` and `uninstalled` lifecycle states where technically safe. Disabling a module must not remove data. Destructive data removal requires an explicit separate action and confirmation.

### 4.3 Third-party add-ons

Third-party extensions live outside the mandatory core and use documented public extension points. Supported extension mechanisms include events, dependency-injection bindings/decorators, migrations, permissions, settings, routes, jobs, widgets, UI slots, editor extensions, API endpoints and frontend assets.

Editing core files is not a supported extension API.

## 5. Unified service rule

First-party modules are not isolated mini-products. They must reuse shared Forwext services instead of reimplementing them.

Examples:

- Portfolio uses the core user, media, search, moderation, notification and permission systems.
- AI Content Moderation participates in the common content pipeline.
- User Content Manager acts through normal domain services and produces normal and independent audit events.
- Thread Freshness operates on the real thread lifecycle, not a parallel table of copied thread state.
- Bug Reports may attach route/request/module context produced by the core runtime.

No first-party module may create a second authentication system, permission engine, moderation queue, notification system, media subsystem or user identity layer.

## 6. Frontend contract

The native PHP frontend is mandatory and first-class. It must remain usable on the minimum hosting profile.

The final platform also contains:

- versioned REST API
- webhooks
- TypeScript SDK
- React component/UI extension system
- optional official Next.js frontend

React/Next.js support must not make Node.js a production requirement for users choosing the native PHP frontend.

## 7. Customization contract

Forwext administrators must be able to manage a substantial portion of presentation and site composition without editing application source.

The final product includes:

- design tokens
- color/font/spacing/radius/border/shadow controls
- gradients, backgrounds and patterns
- component-level appearance properties
- responsive/mobile/desktop controls
- layout regions and UI slots
- widgets
- navigation management
- custom pages
- theme inheritance
- revisions, preview and rollback
- language/phrase management

Customizability must not bypass permission/security checks. Visibility rules are evaluated server-side for protected content.

## 8. Data safety contract

Normal application updates preserve user data.

All schema evolution is performed using versioned migrations. Database resets are not part of the routine development or production update path after the first persistent installation milestone at 03.02.

Destructive migrations must be explicit, reviewable and backed by an appropriate safety/backup strategy.

## 9. Release contract

Every development/release version after the packaging system is available produces:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

The full package installs the current version from zero. The update package updates the immediately previous supported release without requiring database reinstallation.

The update mechanism must know source version, target version, files to add/replace/delete, migrations to run and rebuild/health actions to perform.

Site-specific data such as configuration, uploaded/private user data, logs and backups is protected from ordinary file replacement/deletion policies.

## 10. Clean-room rule

Reference installations of other forum products may be studied for behavior, feature coverage, deployment lessons and architectural problems. Their proprietary source code, templates, phrases, database schema, private identifiers or assets are not copied into Forwext.

Forwext defines its own names, schema, contracts, APIs, UI and source implementation.

## 11. Completion rule

A roadmap item is not considered complete merely because a skeleton exists. Completion requires the relevant production behavior, failure handling, security/permission implications, migrations, automated tests, acceptance checks and documentation to be present to the extent required by that step.

Critical placeholders, known data-loss risks, broken supported-hosting behavior or failing required tests block completion.
