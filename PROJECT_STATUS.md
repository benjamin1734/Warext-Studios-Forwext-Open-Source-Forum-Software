# Forwext Project Status

This file is the canonical human-readable development pointer for continuing Forwext across conversations and work sessions. The GitHub `main` branch remains the final source of truth.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.6-dev
LAST_COMPLETED_MAIN_STEP = 06
LAST_COMPLETED_SUBSTEP = 06.08
CURRENT_STEP = 07.01
LAST_COMMIT = 0837ff8ef8f6767c0796665b9e24eb5e773dedc9
BLOCKERS = none
NEXT_STEP = 07.01 - Attachment and image pipeline
```

## Current position

- Target: **Production-ready 1.0.0**
- Binding roadmap: **20 main steps / 138 real sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Project license: **Apache-2.0**
- Completed main steps: `01`, `02`, `03`, `04`, `05`, `06`; main step `07` is active.
- Completed sub-steps: `01.01` through `01.06`, `02.01` through `02.07`, `03.01` through `03.07`, `04.01` through `04.08`, `05.01` through `05.07`, and `06.01` through `06.08`.
- Current sub-step: `07.01 — Attachment and image pipeline`
- Persistent server installation: `available — browser installer applies all current core migrations, writes protected configuration/secrets and locks completed installation state`
- Installation packaging: `GitHub CI builds vendor-inclusive cPanel full/update ZIPs after PHP 8.4 and PHP 8.5 PHPUnit checks`
- Permission system: `shared global/node engine, starter profiles, analyzer, actor-bound gate, first-party namespace integration and mandatory security matrix completed`
- Forum node hierarchy: `category/forum/subforum/page/link nodes, ordering, breadcrumbs, visibility, settings, transaction-safe persistence and node-scoped forum.view authorization completed`
- Thread domain: `identity/lifecycle, locked/sticky/featured/moderated states, protected extensible thread types, optimistic persistence and granular node permissions completed`
- Post domain: `real first-post identity at position 1, create/edit/history/soft-delete/restore/approve/reject lifecycle, optimistic persistence, serialized per-thread positions, authoritative derived counters and bounded pagination completed`
- Forum metadata: `prefix groups/prefix eligibility, tag policy/autocomplete, typed thread/forum custom fields, forum-scoped configuration, actor-bound own/any editing and atomic metadata replacement completed`
- Poll system: `single/multiple choice, vote changes, open/secret voters, duration/participant limits, actor-bound permissions and result visibility policies completed`
- Discussion state: `revision-safe autosave drafts, monotonic thread/forum read state, unread resolution, watched forums/threads and subscription preferences completed`
- Rich editor: `safe BBCode rendering, authenticated preview/mention APIs, stable-id mentions, safe link/embed handling, reusable thread/post editor UI and live character/word/byte limits completed`
- Content moderation: `audited move/copy/merge/split/lock/sticky/approve/delete/restore/bulk operations, soft-delete/merge tombstones and actor-bound permission enforcement completed`
- Installer migration integrity: `all current role/permission/forum/thread/post/metadata/poll/discussion-state/content-moderation migrations are explicitly registered and regression-tested`
- First persistent install milestone: `03.03 completed`
- Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0)

`LAST_COMMIT` records the implementation commit that completed the last sub-step. Status-only commits are intentionally not self-referenced because a Git commit cannot contain its own final SHA without changing that SHA. Every continuation session must resolve and verify current `main` before changing files.

## Completed in 06.08

- Added actor-bound thread/post moderation operations for move, copy, merge, split, lock/unlock, sticky/unsticky, approve, soft-delete, restore and bounded bulk actions.
- Reused existing granular thread/post permissions where they already existed and added seven structural permissions for move/copy/merge/split/thread delete/thread restore/bulk moderation.
- Required structural permissions across every affected source/target forum and required bulk permission together with the underlying action permission, preventing bulk-action privilege escalation.
- Added soft-delete and merge-tombstone thread lifecycle columns; normal thread lookup/list/update paths now exclude inactive tombstones so stale application objects cannot resurrect moderated content.
- Added transaction-safe structural operations with deterministic row locking, monotonic post re-positioning and first-post invariants.
- Added split-time read-watermark remapping using the real `last_read_post_position` schema so compacting source post positions cannot mark newer content as already read.
- Copy creates new thread/post identities and deliberately copies only core content, not destination-policy-sensitive metadata, polls or private watch/read state.
- Merge preserves source thread identities as soft-deleted `merged_into_thread_id` tombstones instead of hard-deleting their historical identity.
- Added append-only forum moderation audit events with authenticated actor, action, target, forum, bounded reason code, request/correlation id, curated before/after state and UTC timestamp.
- Audit append is required inside the same mutation transaction; audit persistence failure therefore prevents the moderation mutation from committing.
- Added migration `20260916000000_content_moderation`, seven permission definitions and a complete 35-rule five-profile starter matrix.
- Added domain, service, repository, normal-thread visibility, migration and installer-registry tests plus architecture documentation.
- GitHub CI passed PHPUnit on PHP 8.4 and PHP 8.5 together with strict-types, Composer metadata, production dependency, full/update package-build, artifact and release checks.
- Completed Main Step 06 and advanced the roadmap to `07.01 — Attachment and image pipeline`.

## Completed in 06.07

- Added a reusable safe BBCode renderer for bold/italic/underline/strike, quotes, code, links, stable-id mentions and safe embed cards without raw HTML passthrough.
- Added HTTPS/site-relative link policy that rejects credentials, protocol-relative, control-character, backslash-ambiguous and non-HTTPS external targets.
- Added canonical mention resolution through persisted user identity so rendered labels/links do not trust client-supplied display names.
- Added authenticated preview and exact-username mention lookup HTTP handlers through the native web router.
- Added server-side editor assessment and a reusable native thread/post editor component with live character/word/byte counters, min/max indicators, toolbar and preview UI.
- Added same-origin JavaScript/CSS assets compatible with the existing CSP; no third-party iframe or inline script requirement was introduced.
- Added 100,000-byte render cap and bounded recursive BBCode nesting to avoid parser resource-exhaustion paths.
- Added metrics, safe-link, BBCode/XSS, preview/mention handler and reusable editor component tests.
- Fixed a CI-detected backslash-regex escaping bug so ambiguous paths are rejected without PHP warnings.
- GitHub CI passed PHPUnit on PHP 8.4 and PHP 8.5 together with strict-types, Composer metadata, production dependency, full/update package-build, artifact and release checks.

## Completed in 06.06

- Added bounded per-user autosave drafts for new threads and replies while keeping editor/rendering semantics deferred to 06.07.
- Added optimistic draft revisions with `FOR UPDATE` serialization so stale browser tabs cannot silently overwrite newer autosaves.
- Added actor-bound draft access: new-thread drafts reuse `forum.thread.create`; reply drafts reuse `forum.post.create`; forum visibility remains mandatory.
- Added monotonic per-thread read positions validated against the latest non-deleted visible post.
- Added forum-level mark-read watermarks and unread resolution based on latest visible post activity rather than mutable thread metadata timestamps.
- Added watched-thread and watched-forum state with `none`, `in_app`, `email` and `in_app_email` notification modes.
- Added per-user subscription defaults for created/replied thread auto-watch intent and default thread/forum notification modes.
- Kept every private state API actor-bound; there is no external target-user parameter for draft/read/watch/preference mutation.
- Added migration `20260915235959_discussion_state` creating six normalized state tables and ten foreign-key constraints.
- Registered the migration after the poll migration and extended clean-install registry regression coverage.
- Added domain, repository, service and migration tests plus architecture documentation.
- GitHub CI passed PHPUnit on PHP 8.4 and PHP 8.5 together with strict-types, Composer metadata, production dependency, full/update package-build, artifact and release checks.

## Completed in 06.05

- Added first-class polls attached one-to-one to threads with opaque identities and normalized options/votes/choices.
- Added single-choice and multiple-choice selection rules, option ownership validation, bounded 2–20 option sets and configurable maximum selections.
- Added changeable/non-changeable voting with idempotent same-selection resubmission.
- Added open/secret voter identity policy independent from `always`, `after_vote` and `after_close` result visibility policy.
- Added scheduled close, manual close and maximum-participant limits under one domain closure rule.
- Added race-safe vote writes that lock the poll row, reload current poll/options and enforce the final participant slot inside one transaction.
- Added `forum.poll.create`, `forum.poll.vote`, `forum.poll.view_results`, `forum.poll.view_voters` and `forum.poll.manage` permissions with a complete five-profile starter matrix.
- Added migration `20260915235958_poll_system` creating poll, option, participant and choice tables with one-poll-per-thread and one-participant-per-user integrity.
- Added domain/repository/service/migration tests, installer-registry coverage and architecture documentation.
- GitHub CI passed all PHP 8.4/8.5, dependency, package, artifact and release stages.

## Completed in 06.04

- Added bounded prefix groups/prefixes, explicit forum eligibility and deterministic ordering.
- Added Unicode-capable tags with forum-level enable/new-tag/max-count policy and parameterized wildcard-safe autocomplete.
- Added typed `thread` and `forum` custom fields for text/integer/boolean/allowlisted-choice values with bounded validation and no executable administrator-supplied validators.
- Added actor-bound own/any thread metadata editing and ACP-managed forum metadata configuration.
- Added atomic prefix/tag/custom-field replacement and migration `20260915235957_forum_metadata` with eleven normalized tables and fourteen foreign keys.
- GitHub CI passed all required PHP 8.4/8.5, dependency, package and release stages.

## Completed in 06.03

- Added real `Post` entities, first-post position identity, edit/history/soft-delete/restore/approve/reject lifecycle and optimistic persistence.
- Added row-locked monotonic post position allocation and authoritative derived active/visible counters.
- Added actor-bound own-vs-any edit/delete, dedicated restore/moderate permissions and atomic thread + first-post publication boundary.
- Added migration `20260915235955_post_domain`, tests and architecture documentation.

## Completed in 06.02

- Added thread identity/lifecycle, extensible protected type registry, locked/sticky/featured/moderated states and optimistic persistence.
- Added node-scoped thread create/state permissions plus starter-profile downgrade safety.
- Added migration `20260915235945_thread_domain`, tests and architecture documentation.

## Completed in 06.01

- Added first-class category/forum/page/link node hierarchy with true subforums, ordering, breadcrumbs, visibility and forum settings.
- Added node-scoped `forum.view` authorization, transaction-safe persistence and migration `20260915235930_forum_nodes`.

## Completed main step 05

- `05.01`: role/user-group model.
- `05.02`: shared global/node permission engine.
- `05.03`: permission starter profiles.
- `05.04`: permission analyzer/explanation UX.
- `05.05`: safe role appearance/banner system.
- `05.06`: 83 first-party permission definitions across 28 namespaces and runtime integration.
- `05.07`: mandatory permission-security matrix covering IDOR/BOLA, bypass, deny precedence, inheritance, numeric limits and UI/backend parity.

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
