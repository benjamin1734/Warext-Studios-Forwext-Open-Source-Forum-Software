# Forwext Project Status

This file is the canonical human-readable development pointer for continuing Forwext across conversations and work sessions. The GitHub `main` branch remains the final source of truth.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.6-dev
LAST_COMPLETED_MAIN_STEP = 05
LAST_COMPLETED_SUBSTEP = 06.01
CURRENT_STEP = 06.02
LAST_COMMIT = 63c554ee18799fae30784326e403272d393a2de8
BLOCKERS = none
NEXT_STEP = 06.02 - Thread domain and thread types
```

## Current position

- Target: **Production-ready 1.0.0**
- Binding roadmap: **20 main steps / 138 real sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Project license: **Apache-2.0**
- Completed main steps: `01`, `02`, `03`, `04`, `05`; main step `06` is active with `06.01` completed.
- Completed sub-steps: `01.01` through `01.06`, `02.01` through `02.07`, `03.01` through `03.07`, `04.01` through `04.08`, `05.01` through `05.07`, and `06.01`.
- Current sub-step: `06.02 — Thread domain and thread types`
- Persistent server installation: `available — browser installer applies all current core migrations, writes protected configuration/secrets and locks completed installation state`
- Installation packaging: `GitHub CI builds vendor-inclusive cPanel full/update ZIPs after PHP 8.4 and PHP 8.5 PHPUnit checks`
- OAuth/connected accounts: `Google + Discord provider abstraction, PKCE/state, verified-email linking, duplicate prevention, unlink safety and encrypted secret references completed`
- Profile/media: `persisted avatar + banner + about + social links + tab preferences, owner-safe visibility policy, private media storage and native PHP member/profile/media routes completed`
- Profile web privacy: `viewer identity derives only from validated authentication sessions; hidden profiles/media fail closed as 404 and private media is never exposed through a direct public-storage URL`
- Profile music: `private uploaded audio, exact-host HTTPS external policy, native mobile-safe player, byte-range delivery, CSP media allowlist and moderation audit completed; runtime permission now delegates to the shared 05.x engine`
- Custom profile URLs: `canonical /u/{slug} routes, configurable reserved names, permanent non-reusable historical claims, privacy-aware 308 redirects, abuse controls and shared-engine permission integration completed`
- Role/user-group model: `primary and secondary groups remain separate from functional/presentation roles; normalized persisted memberships/assignments completed`
- Permission engine: `typed flag/numeric definitions, global + node rules, allow/deny/inherit, direct-user overrides, deterministic precedence, restrictive numeric aggregation, fail-closed behavior and decision trace completed`
- Permission templates: `five protected starter profiles, typed template rules, transactional one-click apply and custom-rule preservation completed`
- Permission analyzer: `human-readable decision summaries, precedence visualization, inheritance/fail-closed visibility and numeric-limit explanation completed`
- Role appearance: `safe colors/gradients/icons/patterns/animations/banner metadata with mobile/profile/post visibility completed`
- First-party permission namespaces: `83 typed permissions across 28 core namespaces registered; profile runtime bridges use the common PermissionAuthorizer; permission registration itself never grants access`
- Permission security matrix: `actor-bound PermissionGate plus mandatory IDOR/BOLA, moderator/admin bypass, inheritance, same-tier deny and UI/backend parity tests completed`
- Forum node hierarchy: `category/forum/subforum/page/link nodes, deterministic ordering, breadcrumbs, listed/unlisted/disabled visibility, forum settings, hierarchy validation, database persistence and node-scoped forum.view authorization completed`
- Installer migration integrity: `all current role/permission/forum migrations are explicitly registered and regression-tested so clean installs cannot silently omit them`
- First persistent install milestone: `03.03 completed`
- Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0)

`LAST_COMMIT` records the implementation commit that completed the last sub-step. Status/changelog-only commits are intentionally not self-referenced because a Git commit cannot contain its own final SHA without changing that SHA. Every continuation session must still resolve and verify the current `main` HEAD before changing files.

## Completed in 06.01

- Added first-class `category`, `forum`, `page` and `link` node types with 128-bit node identifiers and globally unique canonical slugs.
- Added real subforum support by allowing categories/forums to contain children while keeping page/link nodes as leaves.
- Added deterministic child ordering and root-to-current breadcrumb generation.
- Added hierarchy validation for duplicate ids/slugs, orphan parents, illegal leaf parents, self-parenting, cycles and excessive depth.
- Added `listed`, `unlisted` and `disabled` visibility semantics with ancestor propagation while explicitly keeping visibility separate from authorization.
- Added `ForumNodeAuthorization` on top of the shared actor-bound permission gate using node-scoped `forum.view`; disabled ancestors fail closed before permission lookup.
- Added bounded forum settings for new-thread/reply policy, approval policy, default thread sort and threads-per-page.
- Added safe page/link payload rules; external link nodes require credential-free HTTPS and page content remains bounded source text rather than implicit raw HTML.
- Added transactional `DatabaseForumNodeRepository` with row-locked hierarchy revalidation, parameterized writes, safe type transitions and child-aware deletion refusal.
- Added migration `20260915235930_forum_nodes` for `forwext_nodes` and `forwext_forum_settings`, global slug uniqueness, parent `RESTRICT` and settings `CASCADE` integrity.
- Added installer registry integration, domain/repository/authorization/migration tests and architecture documentation.
- GitHub CI passed PHPUnit on PHP 8.4 and PHP 8.5 together with strict-types, Composer metadata, production dependency, full/update package-build and release checks.

## Completed in 05.07

- Added an actor-bound `PermissionGate` so UI checks and backend enforcement resolve against the same trusted authenticated actor rather than route/body target ids.
- Added generic `PermissionDeniedException` behavior without leaking repository/provider internals.
- Added mandatory security tests for direct-user IDOR, sibling-node BOLA, moderator-to-admin escalation, multi-role deny precedence, node/global override behavior, inheritance fall-through, numeric-limit aggregation and UI/backend allow/deny parity.
- Kept the security matrix inside the normal PHPUnit test tree, making PHP 8.4/8.5 release packaging fail when a permission-boundary regression appears.
- Added security documentation describing the actor/target boundary and extension rule for future protected systems.
- GitHub CI passed all required test, dependency and package/release stages.

## Completed in 05.06

- Added canonical first-party permission namespaces and registered 83 typed permission definitions across 28 namespaces covering forum, moderation, independent audit, ACP, profile, support, FAQ, bug reports, portfolio, invite/referral, AI, spellcheck, user-content management, freshness, giveaway, Easter Egg, trophies, promotions/rewards, marketplace, payment, subscriptions, ads/notices, analytics, appearance and API surfaces.
- Added `PermissionAuthorizer` plus persisted user access-assignment loading so first-party systems can use one shared runtime authorization boundary.
- Replaced the temporary native profile music/custom-URL authorization baselines with engine-backed resolvers while preserving existing domain interfaces.
- Added a narrow `system:unassigned` compatibility state only for real pre-05.x users without persisted primary-group assignments; no new staff/ACP/marketplace/etc. capabilities are granted by compatibility.
- Added migration `20260915235900_permission_namespaces`, built-in template bridge rules, installer registry integration and catalog/assignment/authorizer/runtime/migration tests.
- Corrected the migration timestamp after CI rejected an invalid hour-24 identifier; the final migration id is valid and chronological.
- GitHub CI passed PHPUnit on PHP 8.4/8.5, strict-types, production dependency, full/update packages and release stages.

## Completed in 05.05

- Added a dedicated role-presentation model that remains separate from authorization and cannot grant, deny or alter permissions.
- Added canonical six-digit role colors, optional two-color gradients with bounded angles, built-in icons/patterns/animations and optional banner text/color without accepting arbitrary CSS/HTML/URLs.
- Added independent mobile, profile and post visibility controls while keeping role name/priority authoritative in the existing role model.
- Added one-to-one persisted role appearances, escaped native PHP rendering and reduced-motion responsive CSS.
- Added migration `20260915230000_role_appearance`, installer registry integration, tests and architecture documentation.
- Fixed installer migration registration for the already-completed 05.01–05.03 access migrations and added a regression test.
- GitHub CI passed PHP 8.4/8.5, strict-types, dependency and package/release checks.

## Completed in 05.04

- Added a permission analyzer that delegates authorization to the existing permission engine rather than duplicating precedence logic.
- Added human-readable allow/deny summaries, effective numeric-limit explanations and privacy-safe fail-closed messages.
- Added ordered visualization for node user, global user, node membership, global membership and secure fallback layers.
- Added explicit states for not-applicable, no-rule, inherited, allowed, denied, fail-closed and not-reached outcomes.
- Added an escaped accessible native PHP explanation renderer plus analyzer/renderer tests and architecture documentation.
- GitHub CI passed all required stages.

## Completed in 05.03

- Added typed permission-template domain objects, five protected starter profiles and transactional one-click template application while preserving unrelated customized rules.
- Added safe starter permission definitions for forum, moderation and ACP access plus numeric daily content limits.
- Added migration `20260915220000_permission_templates`, persistence/verification tests and documentation.
- GitHub CI passed all required stages.

## Completed in 05.02

- Added typed flag/numeric permission definitions with `allow`, `deny` and `inherit` for user/group/role subjects.
- Added deterministic precedence: node user → global user → node membership → global membership → implicit deny.
- Added same-tier deny-over-allow, inheritance fall-through, restrictive numeric aggregation, direct-user overrides, node-scoped rules, fail-closed behavior and parameterized persistence.
- Added migration `20260915210000_permission_engine`, tests and decision tracing.
- GitHub CI passed all required stages.

## Completed in 05.01

- Added explicit user-group and role models with one primary group, multiple secondary groups and independent direct roles.
- Added custom/staff/protected-system role kinds, stable identifiers, normalized membership/assignment tables and restrictive integrity rules.
- Added domain/migration tests and architecture documentation.
- GitHub CI passed all required stages.

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
