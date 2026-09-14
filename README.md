# Forwext

**Forwext** is an open-source, extensible forum platform developed by Warext Studios.

The project targets a production-grade `1.0.0`, not an MVP. Its baseline is PHP 8.4+, MySQL/MariaDB, a cPanel-first installation experience, and an architecture that can scale to VDS, Docker and advanced infrastructure without rewriting application code.

## Product principles

- cPanel users must be able to install production releases without Node.js, npm, Composer, SSH, Redis or Supervisor.
- The same application can use advanced drivers such as Redis, persistent queues, object storage, external search and realtime services on stronger infrastructure.
- The native PHP frontend is a first-class official frontend.
- REST API, webhooks, TypeScript SDK, React UI and an optional official Next.js frontend are part of the final platform architecture.
- Core files are not the supported customization mechanism. First-party systems and third-party add-ons use documented extension points.
- Every first-party system reuses the shared users, roles/permissions, notifications, search, moderation, audit, attachments/media, analytics, queue/scheduler, settings and UI/theme infrastructure where applicable.
- Appearance, layout, widgets, navigation, modules and runtime behaviors are designed to be manageable from the ACP with a simple-by-default Basic/Advanced experience.
- Every releasable version produces both a full-install ZIP and an update ZIP.
- Normal updates preserve the existing database and user data through versioned migrations.

## First-party integrated systems

Forwext ships native first-party systems for moderation workspace and independent moderation audit, support and FAQ, bug reporting, portfolio, referrals, AI/spellcheck/content management, thread freshness, giveaways, Easter eggs, trophies/achievements, user promotions, marketplace and internal/external purchase modes, user upgrades/subscriptions, ads/notices and forum analytics.

These are not ports of proprietary forum add-ons. They are developed for Forwext and share the common platform services instead of creating parallel authentication, permission, moderation, notification or user systems.

## Development roadmap

The binding production roadmap is **v2.0**, containing **20 main phases and 138 real sub-steps**. Progress is tracked in [`PROJECT_STATUS.md`](PROJECT_STATUS.md). The binding roadmap is `forwext_master_gelistirme_plani_v2.txt` (plan v2.0, 20 main steps / 138 sub-steps).

The first persistent server installation is expected after the migration/install/upgrade engine is functional at step **03.03**. Before that point development work does not require repeatedly installing each commit on a hosting account.

## Repository

This repository is the canonical source repository for Forwext.

Current development status: see [`PROJECT_STATUS.md`](PROJECT_STATUS.md).
