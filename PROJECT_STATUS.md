# Forwext Project Status

This file is the canonical human-readable development pointer for continuing Forwext across conversations and work sessions. The GitHub `main` branch remains the final source of truth.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.6-dev
LAST_COMPLETED_MAIN_STEP = 05
LAST_COMPLETED_SUBSTEP = 05.04
CURRENT_STEP = 05.05
LAST_COMMIT = c75d33ff00a190d20b95705e70bcb536bcc0a1ef
BLOCKERS = none
NEXT_STEP = 05.05 - Role appearance and banner system
```

## Current position

- Target: **Production-ready 1.0.0**
- Binding roadmap: **20 main steps / 138 real sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Project license: **Apache-2.0**
- Completed main steps: `01`, `02`, `03`, `04`; main step `05` is active with `05.01` through `05.04` completed.
- Completed sub-steps: `01.01` through `01.06`, `02.01` through `02.07`, `03.01` through `03.07`, `04.01` through `04.08`, and `05.01` through `05.04`.
- Current sub-step: `05.05 — Role appearance and banner system`
- Persistent server installation: `available — browser installer applies all current core migrations, writes protected configuration/secrets and locks completed installation state`
- Installation packaging: `GitHub CI builds vendor-inclusive cPanel full/update ZIPs after PHP 8.4 and PHP 8.5 PHPUnit checks`
- OAuth/connected accounts: `Google + Discord provider abstraction, PKCE/state, verified-email linking, duplicate prevention, unlink safety and encrypted secret references completed`
- Profile/media: `persisted avatar + banner + about + social links + tab preferences, owner-safe visibility policy, private media storage and native PHP member/profile/media routes completed`
- Profile web privacy: `viewer identity derives only from validated authentication sessions; hidden profiles/media fail closed as 404 and private media is never exposed through a direct public-storage URL`
- Profile music: `permission bridge, private uploaded audio, exact-host HTTPS external policy, native mobile-safe player, volume/mute/autoplay/loop preferences, byte-range delivery, CSP media allowlist and moderation audit completed`
- Profile music permission bridge: `05.x shared role/group engine can replace ProfileMusicPermissionResolver without rewriting profile music domain or HTTP handlers; baseline external/moderation capabilities remain disabled by default`
- Custom profile URLs: `canonical /u/{slug} routes, configurable reserved names, permanent non-reusable historical claims, privacy-aware 308 redirects, change cooldown/window limits, race-safe uniqueness and CSRF-protected owner settings completed`
- Custom profile URL permission bridge: `05.x shared role/group engine can replace ProfileUrlPermissionResolver without rewriting URL persistence/service/HTTP handlers`
- Role/user-group model: `primary and secondary groups are separate from roles; custom/staff/system roles, protected system roles, stable identifiers, normalized persisted memberships/assignments and fail-closed foreign-key constraints completed`
- Permission engine: `typed flag/numeric definitions, global + node rules, allow/deny/inherit, direct-user overrides, deterministic precedence, restrictive numeric aggregation, parameterized persistence and decision trace completed`
- Permission templates: `five protected starter profiles, typed template rules, transactional one-click apply, custom-rule preservation and safe core permission seed completed`
- Permission analyzer: `human-readable decision summaries, ordered precedence visualization, inheritance/fail-closed visibility, numeric-limit explanation and escaped native PHP HTML renderer completed`
- First persistent install milestone: `03.03 completed`
- Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0)

`LAST_COMMIT` records the implementation commit that completed the last sub-step. The status-only commit that updates this file is intentionally not self-referenced because a Git commit cannot contain its own final SHA without changing that SHA. Every continuation session must still resolve and verify the current `main` HEAD before changing files.

## Completed in 05.04

- Added a permission analyzer that delegates every authorization decision to the existing 05.02 `PermissionEngine` instead of duplicating precedence logic.
- Added human-readable allow/deny summaries, including effective numeric limits and privacy-safe fail-closed explanations.
- Added a five-layer visualization model covering node user, global user, node membership, global membership and secure fallback states.
- Added explicit layer states for not-applicable, no-rule, inherited, allowed, denied, fail-closed and not-reached outcomes.
- Preserved rule-level trace details for user/group/role subject, effect, node scope, numeric limit and engine outcome.
- Added an accessible native PHP HTML renderer with escaped dynamic values and stable state/effect/outcome hooks for later ACP styling.
- Added analyzer/renderer unit tests and architecture documentation without adding a migration or new runtime dependency.
- GitHub CI passed PHPUnit on PHP 8.4 and PHP 8.5 together with strict-types, Composer metadata, production dependency, full/update package-build and release checks.

## Completed in 05.03

- Added typed permission-template keys, rules, repositories and one-click application service boundaries.
- Added five protected starter profiles: new user, member, verified member, moderator and administrator.
- Added transactional template application that upserts only the template-owned global permission keys and preserves unrelated customized rules.
- Added core starter permission definitions for forum view/content creation, numeric daily content limits, moderation access/management and ACP access/management.
- Added system-template persistence and deterministic seed migration `20260915220000_permission_templates` with foreign-key integrity and 40 built-in rules.
- Added domain, transactional writer and migration tests plus architecture documentation.
- Synchronized root `CHANGELOG.md` with completed 05.01, 05.02 and 05.03 work.
- GitHub CI passed PHPUnit on PHP 8.4 and PHP 8.5 together with strict-types, Composer metadata, production dependency, full/update package-build and release checks.

## Completed in 05.02

- Added typed permission keys, flag/numeric definitions and `allow` / `deny` / `inherit` rule effects.
- Added global and generic node/forum-scoped rule persistence without prematurely coupling 05.02 to a forum table that is introduced later.
- Added deterministic precedence: node user → global user → node group/role membership → global group/role membership → implicit deny.
- Kept direct per-user overrides stronger than membership policy while preserving node-specific user overrides above global user overrides.
- Added same-tier deny-over-allow semantics, inheritance fall-through and most-restrictive numeric aggregation for combined group/role limits.
- Added fail-closed behavior for unknown permissions, malformed rules and repository failures without leaking internal exception details.
- Added parameterized database rule lookup for user/group/role/node identifiers and a machine-readable decision trace for the later permission analyzer.
- Added versioned migration `20260915210000_permission_engine`, domain/database/migration tests and architecture documentation.
- GitHub CI passed PHPUnit on PHP 8.4 and PHP 8.5 together with strict-types, Composer metadata, production dependency, full/update package-build and release checks.

## Completed in 05.01

- Added explicit `UserGroup` and `Role` domain models instead of conflating membership and functional/display roles.
- Added `custom`, `staff`, and protected `system` role kinds with deterministic stable access identifiers.
- Added user access assignments with one primary group in the authorization context, deduplicated secondary groups, and independent direct role assignments.
- Added normalized MySQL/MariaDB migration tables for group catalog, role catalog, primary group membership, secondary group membership, and role assignment.
- Enforced at-most-one persisted primary group row per user structurally, while allowing many secondary groups and roles.
- Added domain and migration tests plus architecture documentation.
- GitHub CI passed PHPUnit on PHP 8.4 and PHP 8.5 together with strict-types, Composer metadata, production dependency, package-build, and release checks.

## Progress rules

A sub-step is complete only after its required implementation/documentation, acceptance criteria and applicable tests are satisfied. Skeletons and deferred critical placeholders do not qualify.

Before marking a sub-step complete, review its permission, security, audit, migration/data, UX, mobile and supported-deployment impact as applicable.

## Permanent release rule

Every releasable development version provides both:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

The full ZIP is suitable for a clean installation. The update ZIP upgrades the previous supported installation and must not reset the database. Update manifests are expected to carry source version, target version, add/replace/delete sets, migrations, rebuild actions, and checksums. Normal updates preserve site-specific config/uploads/storage data and advance existing data through migrations.

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
