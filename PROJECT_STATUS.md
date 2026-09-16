# Forwext Project Status

This file is the canonical human-readable development pointer for continuing Forwext across conversations and work sessions. The GitHub `main` branch remains the final source of truth.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.6-dev
LAST_COMPLETED_MAIN_STEP = 06
LAST_COMPLETED_SUBSTEP = 07.03
CURRENT_STEP = 07.04
LAST_COMMIT = 32a7b7456a088dc3db06ff976e352c60c5c4b16d
BLOCKERS = none
NEXT_STEP = 07.04 - Profile posts and activity feed
```

## Current position

- Target: **Production-ready 1.0.0**
- Binding roadmap: **20 main steps / 138 real sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Project license: **Apache-2.0**
- Completed main steps: `01`, `02`, `03`, `04`, `05`, `06`; main step `07` is active.
- Completed sub-steps: `01.01–01.06`, `02.01–02.07`, `03.01–03.07`, `04.01–04.08`, `05.01–05.07`, `06.01–06.08`, `07.01–07.03`.
- Current sub-step: `07.04 — Profile posts ve activity feed`
- Persistent server installation: browser installer applies all current core migrations, writes protected configuration/secrets and locks completed installation state.
- Installation packaging: GitHub CI builds vendor-inclusive cPanel full/update ZIPs after PHP 8.4 and PHP 8.5 PHPUnit checks.
- Permission system: shared global/node engine, starter profiles, analyzer, actor-bound gate, first-party namespace integration and mandatory security matrix completed.
- Forum/content foundation: node hierarchy, thread/post lifecycle, polls, discussion state, metadata, rich editor and audited moderation operations completed.
- Interaction foundation: private attachments, mention/quote/embed/link-preview editor integration, reactions, private bookmarks, follow/ignore and authored-content filtering completed.
- Installer migration integrity: all current migrations are explicitly registered and regression-tested.
- Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0).

`LAST_COMMIT` records the implementation/fix commit that completed the last sub-step. Status-only commits are intentionally not self-referenced because a Git commit cannot contain its own final SHA without changing that SHA. Every continuation session must resolve and verify current `main` before changing files.

## Completed in 07.03

- Added reaction type catalog with bounded keys/labels/scores and six seeded core reactions.
- Added one-reaction-per-user-per-post persistence with aggregate reaction counts and score calculation from enabled reaction definitions.
- Added node-scoped `forum.reaction.use` and blocked reactions on the actor's own post.
- Added private post bookmarks with bounded private notes, private pagination and per-entry current visibility rechecks.
- Added user follow and ignore relationships with self-relation rejection and active-target enforcement for follows.
- Made ignore override follow: applying ignore atomically removes follow and follow/ignore writes serialize through an actor-row `FOR UPDATE` lock to prevent contradictory concurrent state.
- Added server-side ignored-author filters for thread and post collections without replacing normal permission/moderation visibility checks.
- Added four first-party interaction permissions, five-table migration `20260916002000_social_interactions`, explicit starter-profile rules and installer-registry coverage.
- Added authenticated native routes for reaction summary/mutation, bookmark management/listing, follow/unfollow and ignore/unignore; interaction mutations use a dedicated CSRF scope and actor-bound backend authorization.
- Added domain/repository/service/migration/web-surface regression tests and architecture documentation.
- Feature commit: `32a7b7456a088dc3db06ff976e352c60c5c4b16d`.
- Final CI run `35150065939` passed PHP 8.4/8.5 PHPUnit, strict-types, Composer metadata, production dependency baseline, full/update package builds, artifact upload and GitHub Release.

## Completed in 07.02

- Added authenticated bounded mention autocomplete while preserving stable user-id mention tokens.
- Added source-forum-authorized cross-thread quotes with opaque `plain64` quoted payloads so quoted BBCode cannot break out of the quote container.
- Preserved iframe-free safe embed rendering.
- Added SSRF-resistant HTTPS link previews with public-host DNS validation, private/reserved-address rejection, pinned-IP TLS transport, redirect revalidation and response/time bounds.
- Added local emoji/smiley rendering and native editor UI integration.
- Added regression tests covering mention wildcard handling, quote breakout containment, SSRF cases and editor-header gating.
- Feature commit: `bc6164cbd5cdc9a6bc1f14c7afcfd909096d8c55`; hardening fix: `fdd38b4a72e42fa2e6fee467ed4dbeadcb920cb4`.
- Final CI run `35064782254` passed all release gates.

## Completed in 07.01

- Added actor-bound private temporary attachment staging with byte-signature/MIME inspection, quota controls and image safety checks.
- Added privacy stripping for supported image metadata and optional-GD thumbnails without making GD a minimum cPanel dependency.
- Added temporary-to-attached finalization, persisted hash/size integrity verification and private secure downloads.
- Added race-safe quota rechecks, orphan cleanup maintenance service/task/handler and migration `20260916001000_attachment_pipeline`.
- Added three attachment permissions, starter-profile rules, installer coverage and domain/repository/service/migration/security tests.
- Final implementation HEAD: `8530e79c62cf2bcb4334d1706eb59c782a39960d`.
- Final CI run `35029822766` passed all release gates.

## Completed main step 06

- `06.01`: node/category/forum hierarchy.
- `06.02`: thread domain and lifecycle.
- `06.03`: post domain, history and moderation state.
- `06.04`: prefixes/tags/custom fields.
- `06.05`: poll system.
- `06.06`: drafts/read/watch state.
- `06.07`: rich editor, safe BBCode/rendering, preview, mentions/embeds and live metrics.
- `06.08`: audited thread/post moderation operations.

## Completed main step 05

- `05.01`: role/user-group model.
- `05.02`: shared global/node permission engine.
- `05.03`: permission starter profiles.
- `05.04`: permission analyzer/explanation UX.
- `05.05`: safe role appearance/banner system.
- `05.06`: first-party permission definitions/namespaces and runtime integration.
- `05.07`: mandatory permission-security matrix covering IDOR/BOLA, bypass, deny precedence, inheritance, numeric limits and UI/backend parity.

## Progress rules

A sub-step is complete only after its required implementation/documentation, acceptance criteria and applicable tests are satisfied. Skeletons and deferred critical placeholders do not qualify.

Before marking a sub-step complete, review its permission, security, audit, migration/data, UX, mobile and supported-deployment impact as applicable.

## Permanent release rule

Every releasable development version provides both:

- `forwext-vX.Y.Z-full.zip`
- `forwext-vX.Y.Z-update.zip`

The full ZIP is suitable for a clean installation. The update ZIP upgrades the previous supported installation and must not reset the database. Update manifests carry source version, target version, add/replace/delete sets, migrations, rebuild actions and checksums. Normal updates preserve site-specific config/uploads/storage data and advance existing data through migrations.

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
