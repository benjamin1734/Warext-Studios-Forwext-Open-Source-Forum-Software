# Forwext

**Forwext** is an open-source, extensible forum platform developed by Warext Studios.

The project targets a production-grade `1.0.0`, not an MVP. Its baseline is PHP 8.4+, MySQL/MariaDB, a cPanel-first installation experience, and an architecture that can scale to VDS, Docker and advanced infrastructure without rewriting application code.

## Product principles

- cPanel users must be able to install production releases without Node.js, npm, Composer, SSH, Redis or Supervisor.
- The same application can use advanced drivers such as Redis, persistent queues, object storage, external search and realtime services on stronger infrastructure.
- The native PHP frontend is a first-class official frontend.
- REST API, TypeScript SDK, React UI and an optional official Next.js frontend are part of the final platform architecture.
- Core files are not the supported customization mechanism. First-party modules and third-party add-ons use documented extension points.
- Appearance, layout, widgets, navigation, modules and many runtime behaviors are designed to be manageable from the ACP.
- Every normal release produces both a full-install ZIP and an update ZIP.
- Normal updates preserve the existing database and user data through versioned migrations.

## First-party integrated modules

The following are native Forwext modules, not ports of XenForo add-ons:

1. Bug Reports
2. Independent Moderation Audit
3. Portfolio
4. FAQ
5. Referral / Invitation System
6. AI Content Moderation
7. Spell Checking
8. User Content Manager
9. Thread Freshness

They share Forwext users, permissions, content lifecycle, moderation, audit, notifications, search, queue, storage and ACP services.

## Development roadmap

The production roadmap contains **18 main phases and 96 real sub-steps**. Progress is tracked in `PROJECT_STATUS.md` and the master roadmap under `docs/roadmap/`.

The first persistent server installation is expected after database migrations are functional at step **03.02**. Before that point development artifacts are framework/bootstrap work and do not require reinstalling a site.

## Repository

This repository is the canonical source repository for Forwext.

Current development status: see [`PROJECT_STATUS.md`](PROJECT_STATUS.md).
