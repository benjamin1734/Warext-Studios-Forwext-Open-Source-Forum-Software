# Forwext Project Status

This file is the canonical human-readable continuation pointer for Forwext. The current GitHub `main` branch, implementation, tests and migration history remain the final source of truth when any status text is stale.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.7.02-dev
LAST_COMPLETED_MAIN_STEP = 07
LAST_COMPLETED_SUBSTEP = 08.04
CURRENT_STEP = 08.05
LAST_COMMIT = a738bed96fcee2dfaddb14434cb9de1f79013ef8
BLOCKERS = none
NEXT_STEP = 08.05 - Navigation/member directory/stats
```

## Current position

- Target: **Forwext 1.0.0 Production**, not an MVP/demo/prototype.
- Binding roadmap: **20 main steps / 138 real sub-steps**, plan v2.0.
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`, default branch `main`.
- Project license: **Apache-2.0**.
- Completed main steps: `01`, `02`, `03`, `04`, `05`, `06`, `07`; main step `08` is active.
- Completed sub-steps: `01.01–01.06`, `02.01–02.07`, `03.01–03.07`, `04.01–04.08`, `05.01–05.07`, `06.01–06.08`, `07.01–07.07`, `08.01–08.04`.
- Current sub-step: `08.05 — Navigation/member directory/stats`.
- Minimum deployment remains PHP 8.4+, MySQL/MariaDB and Apache/LiteSpeed/Nginx with a first-class native PHP frontend; Composer/npm/Node/SSH/Redis/Docker/Supervisor are not mandatory on normal cPanel runtime.
- Advanced deployments may add Redis, workers, WebSocket/SSE providers, S3-compatible storage, external search and Docker/VDS infrastructure without breaking the minimum profile.

`LAST_COMMIT` records the implementation/fix commit that completed the last roadmap sub-step. Status-only, changelog-only and unrelated contract-correction commits are intentionally not used as the roadmap completion pointer.

## Completed in 08.04

- Added canonical/meta/OpenGraph/Twitter/JSON-LD rendering with absolute URLs derived only from configured canonical origin/base path, never request Host/forwarded input.
- Added fail-closed SEO handling: only explicitly public routes can be indexable; unknown/private HTML routes receive `noindex,nofollow`, and non-indexable non-HTML responses receive `X-Robots-Tag`.
- Added independently verified public-profile SEO reads restricted to active users with effective `public` profile visibility. The SEO query does not select private email/about/social/media content.
- Added canonical public-profile convergence: current `/u/{slug}` when a custom URL exists, otherwise `/members/{username}`.
- Added base-path-aware `robots.txt`, bounded XML sitemap, RSS and Atom endpoints through a shared public-discovery source contract.
- Added JSON-LD script-breakout hardening with JSON hex escaping and XML escaping for sitemap/feed payloads.
- Kept private/member-only profiles out of metadata, sitemap and feeds even when an authenticated viewer can legitimately render them.
- Added extension boundaries for future public forum/FAQ/portfolio/marketplace routes without inventing URLs before those public surfaces exist.
- No migration was required; existing user/profile/custom-URL state is reused and minimum cPanel runtime gains no daemon/Node/Redis dependency.
- Feature commit: `a738bed96fcee2dfaddb14434cb9de1f79013ef8`.
- GitHub Actions build run `35272514113` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency-baseline verification, `forwext-v...-full.zip` package generation and artifact upload.
- MySQL 8.4 migration smoke run `35272514162` passed the complete clean-install migration chain and idempotent second pass.

## Completed in 08.03

- Added shared discovery modes for **new, unread, trending, featured and recent activity** threads.
- Reused the existing permission-aware search scope providers instead of creating a second authorization model. Discovery requires backend `search.use`, and only `forum.node:*` scopes already authorized through `forum.view` are admitted to the repository query.
- Inaccessible/private forum ids therefore do not become SQL discovery candidates merely because a client knows an id.
- Added a single aggregate database query for thread activity/read state, avoiding per-thread unread N+1 queries.
- Reused existing thread `featured` state and existing thread/forum read-state semantics; deleted, merged or non-visible threads and deleted/non-visible posts are excluded.
- Trending uses a bounded seven-day visible-post activity window with deterministic activity/thread-id tie breaking; pagination is server-bounded.
- Added idempotent, data-preserving migration `20260917230000_discovery_query_indexes` and explicit `CoreMigrationRegistry` registration for discovery query indexes.
- Added unit coverage for scope filtering, permission denial, unread query construction, bounded trend window and stable ordering.
- Feature commit: `0f9848bd0c11a9ffd026c493bf7757fc58854aae`.
- GitHub Actions build run `35269385284` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production dependency-baseline verification and cPanel full-package generation.
- MySQL 8.4 migration smoke run `35269385378` passed the complete clean-install migration chain and idempotent second pass.
- No moderation/security audit event is emitted for discovery reads because the operation is read-only; backend authorization remains mandatory.

## Completed in 08.02

- Added advanced search filters for forum, user, date range, prefix, tag, content state, thread type and content type.
- Added a saved-query extension point while keeping filter validation bounded and server-side.
- Added the native PHP `/search` surface, responsive/mobile form layout, pagination bounds and escaped result rendering.
- Kept permission-aware search authoritative through `search.use` and server-derived access scopes; client filters do not grant access.
- Feature commit: `d29f6c452f0d88c4b1dba82c79d40554f6466d21`; fixture alignment fix: `ca98bbf9a9e2ce34ab0720f406044c457424ce7b`.
- The final 08.02 HEAD passed the repository PHP 8.4/8.5 test and quality gates before 08.03 began.

## Completed in 08.01

- Added a durable permission-aware native MySQL/MariaDB search-index lifecycle for forum/thread/post/user sources.
- Added source-driven visibility/scopes, retryable outbox processing, rebuild support and lifecycle triggers without requiring Redis or a persistent worker.
- Search scope tokens are server-derived; node-bound results require normal `forum.view` authorization and private profile data is not indexed as public search content.
- Added shared `search.use` permission, cPanel-friendly maintenance wiring, migration `20260917003000_search_index_lifecycle`, registry coverage and regression tests.
- Feature commit: `455b8d8d68b865a846c0f0be11c890662367573f`; permission-catalog test alignment: `628f5607b9a104039e53fe145e2a0b7b1cbff06d`.

## Release/package contract correction

Commit `6939aae56bfc89cd5ec6dc01ca640664383be853` restored the binding package contract without marking a later release roadmap step complete:

- Full package: `forwext-vX.Y.Z-full.zip`.
- Update package: `forwext-vX.Y.Z-update.zip`.
- Development versions keep the complete `VERSION` token, e.g. `forwext-v0.0.7.02-dev-full.zip`.
- Newly generated update ZIPs contain `update-manifest.json` with source/target version, add, replace, delete, migrations, rebuild and SHA-256 payload checksum data.
- Normal updates do not reset the database and preserve site-specific mutable data through the established update/migration rules.
- Already published historical `install.zip` assets remain immutable; the workflow may read them only as a predecessor compatibility source. Newly generated assets use `full.zip`/`update.zip`.
- A regression test protects the package names and mandatory manifest fields.

## Established platform foundations

- Persistent browser installation uses the 03.03 migration/install/upgrade engine, protected configuration/secrets and completed-install locking.
- Permission foundation includes global/node allow-deny-inherit rules, numeric limits, per-user overrides, templates, analyzer, role appearance, first-party namespaces and IDOR/BOLA/bypass parity tests.
- Forum foundation includes node hierarchy, thread/post lifecycle, prefixes/tags/custom fields, polls, drafts/read/watch state, rich editor/live metrics and audited thread/post moderation operations.
- Media/social foundation includes secure attachments, mentions/quotes/safe embeds/SSRF-protected previews, reactions, bookmarks, follow/ignore and profile activity.
- Notification foundation includes persisted in-app/email/push alerts, preferences, dedupe/grouping, retry, safe templates, sound preferences and polling/SSE/WebSocket fallback delivery.
- Search/discovery foundation now includes index lifecycle (`08.01`), advanced filters (`08.02`), permission-aware discovery (`08.03`) and SEO/public-discovery feeds (`08.04`).
- Detailed historical implementation notes remain under `docs/changelog/` and repository history.

## Completion rules

A roadmap sub-step is complete only after its real implementation/documentation, applicable migrations, permission/security/audit/UX review and acceptance tests are satisfied. Critical TODOs/placeholders do not qualify. Access-controlled content must not leak through UI, search, API, analytics or alternate routes.

For every continuation session:

1. Read the binding v2 master roadmap.
2. Resolve the current GitHub `main` HEAD before assuming a prior commit or status pointer.
3. Inspect this status file, recent commits and the real implementation/tests.
4. If status and code disagree, correct the real implementation first and then repair status.
5. Continue directly from `NEXT_STEP`; do not rewrite completed work unnecessarily.
6. Commit real files/tests/migrations to `main`, verify the SHA and keep this status pointer current.
