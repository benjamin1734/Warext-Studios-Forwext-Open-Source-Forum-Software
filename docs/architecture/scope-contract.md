# Forwext v1.0 Scope Contract

This document freezes the minimum production scope for Forwext 1.0.0 under the binding v2.0 roadmap. Features may grow during development, but the items below cannot be silently downgraded to placeholders or post-1.0 promises.

## Required core platform scope

- PHP 8.4+ application kernel, dependency injection, configuration, secrets and environments.
- HTTP request/response, middleware, routing, canonical URL, reverse proxy and Cloudflare handling.
- CSRF, CORS, rate limiting, security headers, trusted-host policy, request correlation and structured error/log handling.
- MySQL/MariaDB data layer, transactions, typed query APIs, repositories/services and versioned migrations.
- Driver abstractions for cache, session, storage/media, locks, queue, scheduler, search and realtime transport.
- User accounts, registration/verification/anti-abuse, login/recovery, 2FA/passkeys, OAuth connected accounts and account lifecycle.
- Profiles with avatar/banner/about/social links/privacy, role-gated profile music and permission-gated custom profile URLs.
- Primary/secondary groups, roles, global/node/numeric permissions, per-user overrides, templates/presets and permission analyzer.
- Role appearance including colors, gradients, icons, banners, patterns, animation, priority and mobile/profile/post presentation.
- Category/forum hierarchy, threads, posts, thread states/types, prefixes, tags, polls and custom fields.
- Drafts, read tracking, subscriptions/watch, edit history and move/copy/merge/split/bulk moderation.
- Rich editor, safe BBCode/rendering, mentions, quotes, embeds, link preview, emoji/media and live word/character counters with limits.
- Reactions, bookmarks, follow/ignore, profile posts/activity feed and notification sound controls.
- Unified in-app/email/push notification engine with cPanel-safe polling and advanced realtime transports.
- Private conversations/direct messages.
- Native search, advanced filters, find-new/unread/trending/featured discovery, global discovery UX and SEO/sitemap/feed support.
- Reports, approval queue, warnings, bans, anti-abuse, moderator/admin audit and security tooling.
- Full ACP with Basic/Advanced progressive disclosure, search, safe defaults, preview and recovery patterns.
- Appearance Studio, responsive theme/layout tools, widgets/UI slots and revisions.
- Third-party add-on platform plus REST API/webhooks/TypeScript SDK/React extension surface and optional official Next.js frontend.
- cPanel-first installer, migration/update engine, release packaging, CI, security qualification, performance qualification and product documentation.

## Required first-party integrated systems

All of the following ship as native Forwext systems and use shared core services appropriate to their domain:

1. Moderation Workspace
2. Independent Moderation Audit
3. Support Tickets
4. FAQ with Support integration
5. Bug Reports + My Bug Reports
6. Portfolio
7. Referral / Invitation
8. AI Content Moderation
9. Spell Checking
10. User Content Manager
11. Thread Freshness
12. Giveaway
13. Easter Egg
14. Trophy / Badge / Achievement
15. User Promotions
16. Marketplace
17. Internal and External Purchase Modes
18. Subscription / User Upgrades
19. Advertising / Notices
20. Forum Analytics

Each system must include its actual domain/data model, permissions, ACP controls, audit behavior, lifecycle/failure handling, tests and integrations at the roadmap step where those concerns become applicable.

## Required support/FAQ scope

Support includes ticket categories, priority/status, assignment, attachments, conversation, internal staff notes, canned replies, escalation, merge/split, history and SLA metadata. Members receive a “My Tickets” view. FAQ content is searchable and is suggested before ticket creation; resolved support work can seed FAQ drafts.

## Required bug-reporting scope

Members receive “My Bug Reports” with status, staff response/history, duplicate linkage, resolution visibility and ability to add information. Bug reports may capture privacy-safe technical context such as URL/route/request ID, related forum/thread/post, theme/modules and browser/device metadata.

## Required moderation scope

Moderation Workspace unifies reports, approval queues, flagged content, warnings, bans, AI flags, moderator tasks and review work. Independent Moderation Audit remains logically separate from ordinary logs, stores actor/target/before-after/reason/request/timestamp/linkage data and is designed so moderators cannot alter or delete their own audit records.

## Required marketplace/revenue scope

Marketplace supports BYB-style listings/categories, seller pages, internal and external purchase modes, analytics and moderation.

External mode may track views/clicks/CTR while sending buyers to the seller’s external destination with safe redirect/referrer handling.

Internal mode includes cart/checkout/order/payment state, buyer/seller records, digital delivery (file/key/license/manual), order history and support/dispute linkage. Payment providers use an abstraction with webhook verification and idempotency. User upgrades/subscriptions and ad/notice placements integrate with shared permissions and analytics.

## Required giveaway/reward scope

Giveaways support schedule, prize, winner count, participant limits, banner and eligibility rules such as account age, verification, role, post/reaction/trophy state. Winner selection uses cryptographically secure randomness with auditable selection records. Trophy/achievement, promotions and referral/giveaway rewards share a consistent reward-provider direction.

## Required customization scope

- Appearance Studio based on shared design tokens.
- Component-level appearance for major public/ACP primitives.
- Solid/gradient/image/pattern backgrounds with opacity/scale/rotation/blend and scoped application.
- Responsive mobile/desktop controls and reduced-motion/accessibility behavior.
- Layout regions, UI slots, widget registry/cache, visibility conditions and drag/drop layout management.
- Theme inheritance, presets, native template/language/phrase systems, revisions, preview, staging and rollback.
- Basic/Advanced guided configuration, global setting search, safe defaults and reset-to-default behavior.

## Required analytics scope

Permission-controlled analytics cover DAU/MAU, registrations, retention, online peaks, threads/posts/reactions, forum/category performance, search terms, support response/SLA, bug resolution, moderation workload, marketplace views/orders/revenue, referral conversion and giveaway participation, with privacy-aware aggregation/export.

## Required developer ecosystem scope

- Third-party add-on manifest/package/lifecycle system.
- Events, service decorators, DI bindings and deterministic extension ordering.
- Add-on routes, entities, migrations, permissions, settings, ACP pages, jobs/cron, search, notifications, content types, API and webhooks.
- UI slots, widgets, editor extensions, React components and compiled frontend assets.
- CLI scaffolding, SDK/test harness, compatibility/static-analysis tooling and developer documentation.
- Checksums/signature-ready packages, dependency resolution, conflict reporting and capability warnings.

## Required API/frontend ecosystem scope

- Versioned REST API `/api/v1`.
- API tokens/keys/OAuth context, scopes, rate limits, pagination, consistent errors and audit.
- Webhooks with signing, retry/backoff, delivery logs, secret rotation and SSRF-safe destination policy.
- TypeScript SDK.
- Accessible React UI/component library and React extension slots.
- Official optional Next.js frontend with an explicit parity/compatibility contract.
- Native PHP frontend remains fully functional and first-class.

## Required deployment/release scope

- Browser-based cPanel-first installer.
- Production release package that does not require Composer/npm/SSH/Node on the server.
- Full ZIP and immediately-previous-version update ZIP for every releasable version after packaging is introduced.
- Safe updater with source/target validation, migrations, file add/replace/delete manifest, rebuild and health verification.
- Backup/maintenance/recovery path.
- Apache/LiteSpeed/Nginx and root/subdirectory deployment support.
- VDS/dedicated/Docker reference deployment with optional Redis/workers/S3/search/realtime services.
- CI for PHP 8.4/8.5 and supported MySQL/MariaDB targets.
- Automated unit/integration/API/browser/install/upgrade/module-state tests.
- Security and performance/load qualification.
- Installation, admin, customization, deployment, API/SDK/add-on/Next/update/backup/security documentation.

The first persistent hosting installation is expected after **03.03 — Migration / Install / Upgrade Engine** is complete enough to perform and verify a real full install. Routine development after that point advances the same database through update packages/migrations rather than repeated resets.

## Explicit non-goals for 1.0

The following are not required unless deliberately added by a later binding roadmap revision:

- binary/source compatibility with XenForo add-ons;
- reuse of proprietary forum code, schema, templates, phrases or assets;
- mandatory Node.js frontend deployment;
- mandatory Redis/external search/object storage;
- automatic import of every third-party forum product or historical add-on dataset.

## Completion interpretation

The scope is delivered only when implementation, integration, permission/security behavior, migrations, failure handling, tests and user/admin behavior meet the roadmap acceptance criteria. A route, empty class/page, mocked response or skeleton does not satisfy production scope.
