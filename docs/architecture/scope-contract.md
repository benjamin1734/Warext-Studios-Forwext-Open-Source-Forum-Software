# Forwext v1.0 Scope Contract

This document freezes the minimum production scope for Forwext 1.0.0. Features may grow during development, but the items below cannot be silently downgraded to placeholders or post-1.0 promises.

## Required core platform scope

- PHP 8.4+ application kernel, dependency injection, configuration, secrets and environments.
- HTTP request/response, middleware, routing, canonical URL, reverse proxy and Cloudflare handling.
- CSRF, CORS, rate limiting, security headers and trusted-host policies.
- MySQL/MariaDB data layer, transactions, typed query APIs and versioned migrations.
- Driver abstractions for cache, session, storage, queue, scheduler, search and realtime transport.
- User accounts, profile/preferences, registration, verification, password recovery and account lifecycle.
- Session/remember-me security, 2FA, WebAuthn/passkey foundation and connected OAuth accounts.
- User groups, global/node/numeric permissions, user overrides, privacy, account deletion/anonymization and data export framework.
- Category/forum hierarchy, threads, posts, thread states/types, prefixes, tags, polls and custom fields.
- Drafts, read tracking, subscriptions/watch, edit history, move/copy/merge/split and bulk operations.
- Rich editor, BBCode/safe rendering, mentions, quotes, embeds, link preview, emoji and media attachments.
- Reactions, bookmarks, follow/ignore, profile posts and activity events.
- Unified notification/alert/email/push abstraction.
- Private conversations/direct messages.
- Native search, advanced filters, find-new/unread, trending/featured discovery and SEO/sitemap/feed support.
- Reports, approval queue, warnings, bans, spam controls, moderator/admin logs and security tooling.
- Full ACP for daily operation and infrastructure health.

## Required first-party integrated modules

All nine ship as native Forwext systems and use shared core services:

1. Bug Reports
2. Independent Moderation Audit
3. Portfolio
4. FAQ
5. Referral / Invitations
6. AI Content Moderation
7. Spell Checking
8. User Content Manager
9. Thread Freshness

Each must include its data model, permissions, ACP controls, audit behavior, lifecycle state, tests and integrations appropriate to the module.

## Required customization scope

- Appearance Studio based on shared design tokens.
- Component-level appearance settings for major public/ACP UI primitives.
- Solid/gradient/image/pattern backgrounds and asset management.
- Responsive controls, including distinct mobile/desktop overrides where appropriate.
- Layout regions, UI slots, widget registry, visibility conditions and drag/drop layout management.
- Custom page/navigation builder.
- Theme inheritance, presets, native template compiler, language/phrase system, revisions, preview and rollback.

## Required developer ecosystem scope

- Third-party add-on manifest/package/lifecycle system.
- Events, service decorators, DI bindings and deterministic extension ordering.
- Add-on migrations, permissions, settings, routes, admin pages, jobs, search, notifications, API and webhooks.
- UI slots, widgets, editor extensions and compiled frontend assets.
- CLI scaffolding, test harness, compatibility/static-analysis tooling and developer documentation.
- Checksums/signature-ready packages, dependency resolution and conflict reporting.

## Required API/frontend ecosystem scope

- Versioned REST API.
- API tokens/scopes/rate limits/audit.
- Webhooks with signing/retry/delivery logs.
- TypeScript SDK.
- React UI/component library and React extension slots.
- Official optional Next.js frontend with a defined parity/compatibility contract.

## Required deployment/release scope

- Browser-based cPanel-first installer.
- Production release package that does not require Composer/npm/SSH/Node on the server.
- Full ZIP and previous-version update ZIP for every releasable version after packaging is introduced.
- Safe updater, source/target version validation, migrations, file add/replace/delete manifest, rebuild and health verification.
- Backup/maintenance/recovery path.
- Apache/LiteSpeed/Nginx configuration support.
- VDS/dedicated/Docker reference deployment with optional Redis/workers/S3/search/realtime services.
- CI for PHP 8.4/8.5 and supported MySQL/MariaDB targets.
- Automated unit/integration/API/browser/install/upgrade/module-state tests.
- Security qualification and performance/load qualification.
- Installation, admin, customization, deployment, API and add-on documentation.

## Explicit non-goals for 1.0

The following are not required by the current 1.0 contract unless later deliberately added to the roadmap:

- Binary/source compatibility with XenForo add-ons.
- Reuse of XenForo proprietary code, schema, templates, phrases or assets.
- Mandatory Node.js frontend deployment.
- Mandatory Redis/external search/object storage.
- Automatic import of every third-party forum product and every historical add-on dataset.
- A guarantee that 1.0 will never receive later feature releases; 1.0 is complete production scope, not the end of product evolution.

## Completion interpretation

The scope is considered delivered only when implementation, integration, permissions/security, migrations, failure handling, tests and user/admin behavior meet the roadmap's acceptance criteria. A route, class, empty page, TODO or mocked response does not satisfy a production scope item.
