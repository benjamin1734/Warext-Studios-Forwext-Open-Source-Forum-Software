# Forwext Project Status

This file is the canonical human-readable development pointer for continuing Forwext across conversations and work sessions. The GitHub `main` branch remains the final source of truth.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.6-dev
LAST_COMPLETED_MAIN_STEP = 05
LAST_COMPLETED_SUBSTEP = 06.02
CURRENT_STEP = 06.03
LAST_COMMIT = 794c2ffdf4a4c65bf32814efbc2c48dfe031ffc9
BLOCKERS = none
NEXT_STEP = 06.03 - Post domain and message lifecycle
```

## Current position

- Target: **Production-ready 1.0.0**
- Binding roadmap: **20 main steps / 138 real sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Project license: **Apache-2.0**
- Completed main steps: `01`, `02`, `03`, `04`, `05`; main step `06` is active with `06.01` and `06.02` completed.
- Completed sub-steps: `01.01` through `01.06`, `02.01` through `02.07`, `03.01` through `03.07`, `04.01` through `04.08`, `05.01` through `05.07`, and `06.01` through `06.02`.
- Current sub-step: `06.03 — Post domain and message lifecycle`
- Persistent server installation: `available — browser installer applies all current core migrations, writes protected configuration/secrets and locks completed installation state`
- Installation packaging: `GitHub CI builds vendor-inclusive cPanel full/update ZIPs after PHP 8.4 and PHP 8.5 PHPUnit checks`
- Permission system: `shared global/node engine, starter profiles, analyzer, actor-bound gate, first-party namespace integration and mandatory security matrix completed`
- Forum node hierarchy: `category/forum/subforum/page/link nodes, ordering, breadcrumbs, visibility, settings, transaction-safe persistence and node-scoped forum.view authorization completed`
- Thread domain: `thread identity/lifecycle, locked/sticky/featured/moderated states, extensible protected thread-type registry, optimistic persistence, creation/state application services and granular node permissions completed`
- Thread permission profiles: `new_user/member/verified explicitly deny thread state-management permissions; moderator/administrator explicitly allow them so template downgrade cannot leave stale privilege grants`
- Installer migration integrity: `all current role/permission/forum/thread migrations are explicitly registered and regression-tested so clean installs cannot silently omit them`
- First persistent install milestone: `03.03 completed`
- Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0)

`LAST_COMMIT` records the implementation commit that completed the last sub-step. Status/changelog-only commits are intentionally not self-referenced because a Git commit cannot contain its own final SHA without changing that SHA. Every continuation session must still resolve and verify the current `main` HEAD before changing files.

## Completed in 06.02

- Added opaque thread ids, bounded titles and immutable registered thread-type keys.
- Added extensible `ThreadTypeRegistry` with protected core `discussion` type and duplicate-key override rejection.
- Added thread lifecycle for visible/pending/rejected moderation state plus lock, sticky and featured state with idempotent domain transitions/events.
- Added optimistic aggregate versioning and transaction-backed `DatabaseThreadRepository`; stale writes fail instead of silently overwriting newer state.
- Added bounded per-forum listing order with sticky/featured priority and parameterized persistence.
- Added `ThreadCreationService` enforcing target forum type, resolvable hierarchy, `forum.view`, node-scoped `forum.thread.create`, forum settings and registered thread type. Thread author comes only from the authenticated actor-bound gate.
- Added `ThreadStateService` with separate node-scoped `forum.thread.lock`, `forum.thread.sticky`, `forum.thread.feature` and `forum.thread.moderate` capabilities.
- Added migration `20260915235945_thread_domain` for thread types/threads plus four granular permissions and 20 starter-template rules. Historical 05.x migrations were left unchanged.
- Added explicit deny rules for new-user/member/verified starter profiles and allow rules for moderator/administrator so applying a lower privilege template cannot retain stale thread-management grants.
- Added domain, registry, database, service, permission, concurrency, migration and installer-registry coverage plus architecture documentation.
- Kept first-post content out of the thread table so 06.03 can model the first post as a real `Post` as required by the normative glossary.
- GitHub CI passed PHPUnit on PHP 8.4 and PHP 8.5 together with strict-types, Composer metadata, production dependency, full/update package-build and release checks.

## Completed in 06.01

- Added first-class `category`, `forum`, `page` and `link` node types with 128-bit identifiers and globally unique canonical slugs.
- Added real subforum support while keeping page/link nodes as leaves.
- Added deterministic ordering, root-to-current breadcrumbs and fail-fast hierarchy validation for duplicates, orphans, illegal parents, cycles and excessive depth.
- Added `listed`, `unlisted` and `disabled` visibility with ancestor propagation while keeping visibility separate from authorization.
- Added node-scoped `forum.view` authorization through the shared actor-bound permission gate.
- Added bounded forum settings, safe page/link payload rules and transaction/row-lock database persistence.
- Added migration `20260915235930_forum_nodes`, installer registry integration, tests and architecture documentation.
- GitHub CI passed all required PHP 8.4/8.5, dependency, package and release stages.

## Completed in 05.07

- Added actor-bound permission gate and generic denial boundary.
- Added mandatory IDOR/BOLA, moderator/admin bypass, multi-role deny, inheritance, numeric-limit and UI/backend parity tests inside normal CI.
- GitHub CI passed all required stages.

## Completed in 05.06

- Registered 83 typed first-party permission definitions across 28 namespaces and added shared `PermissionAuthorizer` runtime integration.
- Replaced temporary profile music/custom-profile-URL permission baselines with shared-engine resolvers.
- Added a narrow existing-user compatibility group without introducing staff/ACP/marketplace grants.
- Added migration `20260915235900_permission_namespaces`, starter-template bridge rules, tests and documentation.
- GitHub CI passed all required stages.

## Completed in 05.05

- Added safe role appearance/banner model and persistence without allowing presentation metadata to alter authorization.
- Added reduced-motion responsive rendering and fixed clean-install access migration registration.

## Completed in 05.04

- Added human-readable permission analyzer/visualization that delegates to the shared engine rather than duplicating precedence logic.

## Completed in 05.03

- Added five protected permission starter profiles with transactional one-click application and custom-rule preservation.

## Completed in 05.02

- Added typed global/node permission engine with allow/deny/inherit, direct-user overrides, deterministic precedence, numeric limits, fail-closed behavior and decision traces.

## Completed in 05.01

- Added explicit primary/secondary group and independent role model with normalized persistence.

## Progress rules

A sub-step is complete only after its required implementation/documentation, acceptance criteria and applicable tests are satisfied. Skeletons and deferred critical placeholders do not qualify.

Before marking a sub-step complete, review its permission, security, audit, migration/data, UX, mobile and supported-deployment impact as applicable.

## Permanent release rule

Every releasable development version provides both:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

The full ZIP is suitable for a clean installation. The update ZIP upgrades the previous supported installation and must not reset the database. Update manifests are expected to carry source version, target version, add/replace/delete sets, migrations, rebuild actions and checksums. Normal updates preserve site-specific config/uploads/storage data and advance existing data through migrations.

## Continuation protocol

A new work session must:

1. Read the binding master roadmap.
2. Read this status file.
3. Inspect current GitHub `main` and recent commits.
4. Continue from `NEXT_STEP` without rewriting completed work unnecessarily.
5. Apply the real requirements of the current sub-step.
6. Run applicable tests/acceptance checks.
7. Add migrations when the change requires them.
8. Commit to GitHub and update this status pointer.
