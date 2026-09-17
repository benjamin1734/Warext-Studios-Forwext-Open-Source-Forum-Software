# Forwext Project Status

This file is the canonical human-readable development pointer for continuing Forwext across conversations and work sessions. The GitHub `main` branch remains the final source of truth.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.7.02-dev
LAST_COMPLETED_MAIN_STEP = 07
LAST_COMPLETED_SUBSTEP = 08.01
CURRENT_STEP = 08.02
LAST_COMMIT = 455b8d8d68b865a846c0f0be11c890662367573f
BLOCKERS = none
NEXT_STEP = 08.02 - Advanced search filters
```

## Current position

- Target: **Production-ready 1.0.0**
- Binding roadmap: **20 main steps / 138 real sub-steps**
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`
- Project license: **Apache-2.0**
- Completed main steps: `01`, `02`, `03`, `04`, `05`, `06`, `07`; main step `08` is active.
- Completed sub-steps: `01.01–01.06`, `02.01–02.07`, `03.01–03.07`, `04.01–04.08`, `05.01–05.07`, `06.01–06.08`, `07.01–07.07`, `08.01`.
- Current sub-step: `08.02 — Advanced search filters`
- Persistent server installation: browser installer applies all current core migrations, writes protected configuration/secrets and locks completed installation state.
- Installation packaging: GitHub CI builds vendor-inclusive cPanel installation/update ZIPs after PHP 8.4 and PHP 8.5 PHPUnit checks. Published main and side-update release tags are immutable.
- Permission system: shared global/node engine, starter profiles, analyzer, actor-bound gate, first-party namespace integration and mandatory security matrix completed.
- Forum/content foundation: node hierarchy, thread/post lifecycle, polls, discussion state, metadata, rich editor and audited moderation operations completed.
- Interaction foundation: private attachments, mention/quote/embed/link-preview editor integration, reactions, private bookmarks, follow/ignore, profile-wall activity and privacy-filtered unified activity feed completed.
- Notification foundation: persisted in-app alerts, email/push delivery queue, channel preferences, grouping/dedupe, bounded retry/backoff, safe templates, first-party/add-on notification registry, user-controlled accessible notification sounds, and polling/SSE/WebSocket realtime fallback delivery completed.
- Search foundation: native MySQL FULLTEXT indexing now has durable permission-aware lifecycle processing for forum/thread/post/user sources, source-driven scopes, lifecycle triggers, retryable outbox draining and cPanel-friendly maintenance integration.
- Installer migration integrity: all current migrations are explicitly registered and regression-tested; a real MySQL 8.4 clean-install/idempotency workflow also gates migration changes.
- Binding roadmap: `forwext_master_gelistirme_plani_v2.txt` (v2.0).

`LAST_COMMIT` records the implementation/fix commit that completed the last sub-step. Status-only/test-only/documentation commits are intentionally not self-referenced because a Git commit cannot contain its own final SHA without changing that SHA. Every continuation session must resolve and verify current `main` before changing files.

## Completed in 08.01

- Kept the existing native MySQL FULLTEXT search driver and added a production lifecycle layer instead of creating a parallel search system.
- Added first-party index sources for forum nodes, threads, posts and users with source-state checks that exclude pending/rejected/deleted content and private profile fields.
- Added stable search scope tokens and actor-side scope resolution so node-bound search results require the same `forum.view` authorization as normal forum browsing; scope tokens are not accepted from clients as authority.
- Kept unlisted forum nodes out of search discovery while still supporting visible permission-aware forum content.
- Added a durable search-index outbox with lease-based batch ownership, retry/backoff, idempotent upsert/delete processing and rebuild queue support.
- Added MySQL lifecycle triggers for forum nodes, threads, posts and users. Thread node/title/moderation/visibility changes requeue dependent posts so old content or permission scopes cannot remain searchable.
- Added explicit pre-delete handling for thread-owned post index rows so MySQL cascade deletion cannot leave stale search documents.
- Added shared `search.use` permission registration and permission-catalog coverage.
- Added cPanel-friendly maintenance/task wiring to drain the outbox without requiring Redis, Supervisor, Node.js or a persistent worker.
- Added migration `20260917003000_search_index_lifecycle`, migration-registry coverage and architecture documentation.
- Added lifecycle, visibility/privacy, access-scope, migration/trigger and registry regression coverage.
- Feature commit: `455b8d8d68b865a846c0f0be11c890662367573f`; final permission-catalog test alignment: `628f5607b9a104039e53fe145e2a0b7b1cbff06d`.
- GitHub Actions build run `35244712126` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production dependency-baseline verification and cPanel package generation.
- Real MySQL 8.4 migration smoke run `35244712274` passed the full clean-install migration chain and idempotent second migration pass.

## Completed in 07.07

- Connected the 07.05 notification engine to the existing 03.06 realtime infrastructure instead of introducing a parallel queue/WebSocket stack.
- Added the browser fallback chain `WebSocket → SSE → database-backed polling`; minimum cPanel remains fully functional with polling and requires no Node.js, Redis, Supervisor or daemon.
- Reused durable `forwext_realtime_messages.sequence_id` values as resume cursors so grouped notification updates cannot be lost by timestamp/id cursor ambiguity.
- Added recipient-derived realtime channels in the form `notification.user.<user-id>`; clients cannot provide an arbitrary user id or channel name to read another account's events.
- Realtime wake payloads contain only `notification_id`; title, body, action path and private notification payload are never broadcast through the external gateway payload.
- Added actor-scoped canonical snapshot reads that re-check `recipient_user_id` and `in_app_visible = 1`, keeping knowledge of a notification id/channel/cursor insufficient for IDOR/BOLA access.
- Added realtime publishing after durable notification mutation and delivery enqueue; dedupe hits do not emit duplicate wake events, while new/grouped in-app changes do.
- Contained realtime provider failures so notification persistence remains the source of truth and delivery gracefully falls back instead of breaking forum/profile writes.
- Added bootstrap semantics that establish the current sequence without replaying historical notifications; stale/invalid wake records still advance the cursor to prevent replay loops.
- Added authenticated polling and SSE HTTP surfaces, bounded cursor/limit validation, private/no-store responses and base-path-aware URLs for subdirectory/cPanel installations.
- Added same-origin WebSocket path validation and kept CSP network permission at `connect-src 'self'`; advanced gateways must independently authenticate the session and authorize the derived user channel.
- Added native browser delivery with a visual/ARIA live notice plus `forwext:notification` DOM event. The 07.06 sound player remains supplementary and still respects mute/volume/category/autoplay constraints.
- Added architecture documentation plus realtime publisher privacy, recipient-scoped reader/IDOR and native WebSocket→SSE→polling surface regression tests.
- No 07.07 migration was required because the durable sequence store and transport abstraction were already delivered by 03.06.
- Feature commit: `e9b166f9755ccf6c2e2e71a7152b9978f5373e49`.
- Final GitHub Actions run `35229594491` passed Composer validation, strict-types enforcement, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency-baseline verification, full/update package builds, artifact upload and GitHub Release.

## Completed in 07.06

- Added per-user notification sound settings with global mute, bounded `0–100` volume and a selectable default sound preset.
- Added per-category sound overrides with independent enable/disable, optional category-specific preset and reset-to-inherited behavior.
- Added four safe first-party Web Audio presets: `soft`, `chime`, `pulse` and `minimal`; no user upload, external URL or remote media source is accepted by the sound engine.
- Reused the shared 07.05 permission engine through `notification.alert.view` and `notification.preference.manage`; no parallel authorization model or frontend-only permission check was introduced.
- Added server-side playback-plan resolution so mute, zero-volume, category disable, stale preset fallback and effective volume are deterministic before browser playback.
- Added native authenticated sound-settings/category routes plus a dedicated `notification-sound` CSRF scope and base-path-aware asset/API paths for subdirectory/cPanel installations.
- Added `public/assets/notification-sound.js` using Web Audio only after a real pointer/keyboard/touch interaction; pre-unlock notifications are not queued for surprise delayed playback.
- Kept notification sound strictly supplementary: visual/in-app notification delivery remains independent and callers treat unavailable/blocked audio as a normal silent path.
- Added migration `20260917002000_notification_sound` with global and category preference tables, cascading user ownership and verification of the shared notification permission dependency.
- Added clean-install registry coverage, service behavior tests, migration tests, web-surface/autoplay safety tests and architecture documentation.
- Self-service sound preference changes intentionally do not create moderation/security audit events; only bounded preference state and update timestamps are stored, with no secrets or sensitive provider data involved.
- Feature commit: `a138065805e01088277f193338a3388043da4fb9`.
- Blob verification matched the locally validated source for the factory, migration registry, playback asset, sound service and migration exactly.
- Local verification passed PHP 8.4 syntax checks and a real-autoload 07.06 smoke covering defaults, mute/volume, category override/disable/reset, preset rejection, migration verification and autoplay/remote-media constraints.
- Final GitHub Actions run `35221907037` passed Composer validation, strict-types enforcement, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency-baseline verification, full/update package builds, artifact upload and GitHub Release.

## Completed in 07.05

- Added a shared `NotificationRegistry`/`NotificationDefinition` API so first-party modules and future third-party add-ons register stable notification types without writing storage tables directly.
- Added persisted `in_app`, `email` and `push` channel handling with category/channel preferences and safe per-definition defaults.
- Added producer-level dedupe keys and unread group keys with occurrence aggregation.
- Added recipient-scoped transaction + `FOR UPDATE` serialization so concurrent notification dispatches cannot race dedupe/group decisions into duplicate records.
- Added deterministic placeholder template rendering without PHP/expression evaluation and bounded scalar notification payloads.
- Restricted notification action targets to same-origin absolute paths and rejected full/protocol-relative URLs to prevent notification-driven open redirects.
- Added retryable external delivery rows, provider-neutral channel transports, exception containment, bounded machine-safe error codes and exponential backoff capped at eight attempts.
- Kept the minimum cPanel profile daemon-free: database persistence plus cron-invokable delivery remains sufficient, while advanced deployments can call the same worker from dedicated workers.
- Added owner-scoped inbox/read operations and shared permission-engine checks through `notification.alert.view` and `notification.preference.manage`; read-state SQL remains recipient-bound against IDOR/BOLA.
- Added migration `20260917001000_notification_alerts` with four tables, five foreign keys, two notification permissions, ten starter-profile rules and installer-registry verification.
- Added architecture documentation plus migration, registry, dispatcher, delivery-worker, retry, safe-URL and recipient-scope regression coverage.
- Feature commit: `6b955722f9c2c3766ca544decb613c08bdc4e393`; concurrency hardening fix: `c9c70266c02e4a26b0329f288b465bdf3f439818`.
- Verification performed in the working runtime: PHP 8.4 syntax checks passed; 13 focused 07.05 test methods passed against the prior vendor-inclusive release baseline with the new source overlaid; a real-autoload runtime smoke passed dispatch, dedupe, recipient row locking and retry/backoff paths.
- GitHub Actions did not auto-create a workflow run for the connector-authored Git commits, so no CI-success claim is recorded for 07.05. The repository test files remain part of the normal PHP 8.4/8.5 workflow and will execute on the next workflow-triggering push/dispatch.

## Completed in 07.04

- Added profile-wall view/post privacy scopes: `everyone`, `followers` and `owner_only`, enforced server-side using the existing first-party follow relations.
- Added bounded UTF-8 profile posts and comments with visible/pending/rejected moderation state plus soft-delete timestamps.
- Added five profile-activity permissions; normal interaction permissions are explicitly granted by starter profiles while `profile.post.moderate` is denied to normal profiles and allowed to moderator/administrator.
- Added profile-post reactions that reuse the 07.03 shared reaction catalog instead of creating a parallel reaction definition system.
- Added owner/author/staff management rules and bidirectional ignore checks for cross-user post/comment/reaction writes.
- Added a unified activity candidate stream over active threads, forum posts, profile posts/comments and profile reactions.
- Added viewer-specific activity filtering for ignored actors, node-scoped `forum.view`, profile privacy and current profile-post visibility.
- Explicitly kept private bookmark/note data outside the activity-feed source query.
- Added native authenticated profile post/comment/reaction/privacy/activity routes with dedicated `profile-activity` CSRF scope.
- Added migration `20260916003000_profile_activity`, four tables, five permissions, 25 starter-profile rules and installer-registry coverage.
- Added service/privacy, feed, SQL-source, migration and web-surface tests plus architecture documentation.
- Feature commit: `1bbe905cfb75d293166c7c50f6ae9f8483af8a6e`.
- Final CI run `35207746110` passed PHP 8.4/8.5 PHPUnit, strict-types, Composer metadata, production dependency baseline, full/update package builds, artifact upload and GitHub Release.

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
- Feature commit: `32a7b7456a088dc3db06ff976e352c60c5c4b16d`.
- Final CI run `35150065939` passed all release gates.

## Completed in 07.02

- Added authenticated bounded mention autocomplete while preserving stable user-id mention tokens.
- Added source-forum-authorized cross-thread quotes with opaque `plain64` quoted payloads so quoted BBCode cannot break out of the quote container.
- Preserved iframe-free safe embed rendering.
- Added SSRF-resistant HTTPS link previews with public-host DNS validation, private/reserved-address rejection, pinned-IP TLS transport, redirect revalidation and response/time bounds.
- Added local emoji/smiley rendering and native editor UI integration.
- Feature commit: `bc6164cbd5cdc9a6bc1f14c7afcfd909096d8c55`; hardening fix: `fdd38b4a72e42fa2e6fee467ed4dbeadcb920cb4`.
- Final CI run `35064782254` passed all release gates.

## Completed in 07.01

- Added actor-bound private temporary attachment staging with byte-signature/MIME inspection, quota controls and image safety checks.
- Added privacy stripping for supported image metadata and optional-GD thumbnails without making GD a minimum cPanel dependency.
- Added temporary-to-attached finalization, persisted hash/size integrity verification and private secure downloads.
- Added race-safe quota rechecks, orphan cleanup maintenance service/task/handler and migration `20260916001000_attachment_pipeline`.
- Final implementation HEAD: `8530e79c62cf2bcb4334d1706eb59c782a39960d`.
- Final CI run `35029822766` passed all release gates.

## Completed main step 07

- `07.01`: secure attachment pipeline.
- `07.02`: mentions, quotes, embeds, link previews and emoji integration.
- `07.03`: reactions, bookmarks, follow and ignore.
- `07.04`: profile posts/comments and unified activity feed.
- `07.05`: notification/alert engine.
- `07.06`: user-controlled notification sound system.
- `07.07`: polling/SSE/WebSocket realtime notification delivery.

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

Every releasable development version provides both an installation ZIP and an update ZIP. Development releases are immutable once published:

- Main-step release: `vX.Y.Z-dev`, with `forwext-X.Y.Z-dev-install.zip` and `forwext-X.Y.Z-dev-update.zip`.
- Side update: `vX.Y.Z.NN-dev` (`NN` starts at `01`), with its own installation/update ZIP pair.

A published tag/release is never overwritten. Further fixes/features increment the side-update suffix (`.01`, `.02`, `.03` …) until the next main-step version. The installation ZIP is suitable for a clean installation. The update ZIP upgrades the previous supported release and must not reset the database. Update manifests carry source version, target version, add/replace/delete sets, migrations, rebuild actions and checksums. Normal updates preserve site-specific config/uploads/storage data and advance existing data through migrations.

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