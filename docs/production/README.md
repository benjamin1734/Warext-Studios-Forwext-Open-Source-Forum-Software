# Forwext production handbook

This is the production operator/developer entry point for the Forwext 1.0 product line. The native PHP frontend and browser installer are the minimum supported cPanel profile. Redis, object storage, Meilisearch, persistent workers, WebSocket infrastructure and the official Next.js frontend are optional advanced tiers.

## Installation

Use the immutable GitHub Release asset `forwext-vX.Y.Z-full.zip`; do not use GitHub's source archive as the production installer.

Minimum profile:

- PHP 8.4+;
- MySQL or MariaDB;
- OpenSSL, PDO and PDO MySQL;
- Apache/LiteSpeed/Nginx;
- writable non-symlink `config/` and `storage/` paths.

Composer, npm, Node.js, SSH, Redis, Docker and Supervisor are not required on the standard cPanel runtime.

Open `/install.php` and complete preflight, site identity, database, first administrator, mail, modules, theme and post-install health. Permanent installed-version state is written only after health verification succeeds. When outbound webhooks are used, configure the bounded cPanel cron command shown by the installer.

Reference: `docs/architecture/cpanel-web-installer.md`.

## Administration

The native ACP is the authoritative administration surface. Backend permissions remain authoritative even when controls are hidden in the UI.

Primary areas cover users, groups/roles, permission analysis, forum nodes, content/moderation, modules, integrations, system operations, appearance and analytics. Moderation sanctions use the discipline/ban workflow instead of direct account-table edits.

Administrators should use unique credentials and MFA/passkeys where available, keep privileges minimal, and create/verify backups before destructive maintenance.

References: `docs/architecture/users-roles-forums-moderation-acp.md`, `docs/architecture/system-operations-acp.md`, `docs/security/permission-security-matrix.md`.

## Themes

Use the revision-backed theme/template/language system, design tokens, component appearance settings, layout regions and widgets. Core runtime files are not the supported customization mechanism.

Reference: `docs/architecture/theme-template-language-revisions.md`.

## First-party modules

Enable or disable modules through the module manager. Dependency/conflict validation is server-side and cannot be bypassed by hiding UI controls or modifying a checkbox request.

Reference: `docs/architecture/first-party-module-manager.md`.

## Third-party add-ons

Add-ons must use the manifest/package lifecycle, declared backend/UI capabilities, events/decorators/DI extension points and package-signing/security model. They do not receive unrestricted core-patching authority.

Developer references:

- `docs/developer/addons/README.md`
- `docs/developer/addons/capabilities.md`
- `docs/developer/addons/dependencies.md`
- `docs/developer/addons/package-signing.md`
- `docs/developer/addons/sample-addon.md`

## REST API

The public contract is versioned under `/api/v1`. Private endpoints require both credential scope and the authenticated account's normal Forwext permissions. Rate limiting, stable JSON errors and API audit logging are part of the contract.

References: `docs/architecture/versioned-rest-api-v1.md` and `docs/architecture/api-security-rate-audit.md`.

## TypeScript SDK

`@forwext/sdk` is the official dependency-free Web Platform client. Use `bearerAuth()` for PAT/OAuth credentials or `apiKeyAuth()` for API keys. Call `assertCompatible()` before relying on a server's API major.

Reference: `docs/architecture/typescript-sdk.md`.

## React UI and Next.js

`@forwext/react-ui` provides accessible primitives, shared design tokens and add-on React extension slots. Permission-aware components are presentation helpers only; server authorization remains authoritative.

The official Next.js App Router frontend is optional and requires a Node runtime. Keep backend/auth/revalidation secrets server-only and use HTTPS in production. The native PHP frontend remains fully supported.

References: `docs/architecture/react-ui-extension-slots.md` and `docs/architecture/nextjs-frontend.md`.

## Updates and backups

Use the immutable release pair:

- `forwext-vX.Y.Z-full.zip` for clean installs;
- `forwext-vX.Y.Z-update.zip` for the documented immediate predecessor.

The update ZIP contains `update-manifest.json` with exact source/target versions, add/replace/delete operations, preserve rules, migrations, rebuild actions and SHA-256 payload checksums.

The updater validates the package before mutation, acquires the update lock, creates and verifies a logical database backup, enters maintenance, snapshots changed files, applies files/migrations/rebuilds, runs health checks and then exits maintenance. Failed updates restore database/version/files; incomplete automatic recovery deliberately leaves maintenance active.

Normal updates never overwrite generated config, the master key, uploads, private files, logs or backups.

References: `docs/architecture/full-update-release-system.md` and `docs/architecture/transactional-updater-recovery.md`.

## Security operations

Release qualification explicitly covers SQL injection, XSS, CSRF, SSRF, IDOR/BOLA, upload/path traversal, OAuth linking, session security, webhook signing/destination policy, secret leakage and dependency advisories.

Operators must:

- keep PHP, database software and Forwext on supported releases;
- protect the application master key, database credentials and backups;
- use TLS for production public origins;
- restrict administrator access and use MFA/passkeys where available;
- review third-party add-ons before enabling capabilities;
- never expose raw API tokens, webhook secrets, SMTP/payment credentials or backup contents;
- monitor failed queues, audit events, health/readiness and update failures.

References: `docs/security/threat-model.md` and `docs/architecture/http-security-runtime-health.md`.

## 1.0.0 final acceptance

Roadmap 20.08 is complete only after all of the following are true:

1. install/admin/theme/module/add-on/API/SDK/Next/update/backup/security documentation is present;
2. LICENSE, NOTICE and THIRD_PARTY_NOTICES.md remain part of the distribution source;
3. PHP 8.4 and PHP 8.5 test gates pass;
4. MySQL 8.4 and MariaDB 10.11 clean-install/upgrade smoke passes;
5. security qualification passes;
6. low-resource performance/observability qualification passes;
7. SDK, React UI and optional Next.js build gates pass;
8. the 1.0.0 full/update ZIPs pass archive/checksum/update-manifest integrity validation;
9. GitHub `v1.0.0` is published as a stable, immutable release and is not marked prerelease.

The final changelog records the release commit, workflow evidence and published artifact hashes.
