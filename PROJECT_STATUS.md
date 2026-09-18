# Forwext Project Status

This file is the canonical human-readable continuation pointer for Forwext. The current GitHub `main` branch, implementation, tests and migration history remain the final source of truth when any status text is stale.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.7.02-dev
LAST_COMPLETED_MAIN_STEP = 09
LAST_COMPLETED_SUBSTEP = 10.02
CURRENT_STEP = 10.03
LAST_COMMIT = 174a1a76d3ad0aec1cea5e3cc83196c7f7385f59
BLOCKERS = none
NEXT_STEP = 10.03 - Talep konuşması ve yetkili araçları
```

## Current position

- Target: **Forwext 1.0.0 Production**, not an MVP/demo/prototype.
- Binding roadmap: **20 main steps / 138 real sub-steps**, plan v2.0.
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`, default branch `main`.
- Project license: **Apache-2.0**.
- Completed main steps: `01`, `02`, `03`, `04`, `05`, `06`, `07`, `08`, `09`; main step `10` is active.
- Completed sub-steps: `01.01–01.06`, `02.01–02.07`, `03.01–03.07`, `04.01–04.08`, `05.01–05.07`, `06.01–06.08`, `07.01–07.07`, `08.01–08.06`, `09.01–09.07`, `10.01–10.02`.
- Current sub-step: `10.03 — Talep konuşması ve yetkili araçları`.
- Remaining roadmap work after 10.02: **73 real sub-steps**.
- Minimum deployment remains PHP 8.4+, MySQL/MariaDB and Apache/LiteSpeed/Nginx with a first-class native PHP frontend; Composer/npm/Node/SSH/Redis/Docker/Supervisor are not mandatory on normal cPanel runtime.
- Advanced deployments may add Redis, workers, WebSocket/SSE providers, S3-compatible storage, external search and Docker/VDS infrastructure without breaking the minimum profile.

`LAST_COMMIT` records the implementation/fix commit that completed the last roadmap sub-step. Status-only, changelog-only and unrelated contract-correction commits are intentionally not used as the roadmap completion pointer.

## Completed in 10.02

- Added category-specific dynamic support fields with backend-controlled text, textarea, select and checkbox validation.
- Rejects unknown/manipulated field keys and invalid scalar types instead of trusting browser-rendered form controls.
- Added one-to-one ticket intake descriptions plus typed historical dynamic-field value snapshots.
- Added typed optional context links for thread, account and marketplace-listing references.
- Thread context re-checks real node-scoped `forum.view`; account context is self-only unless the actor has `support.ticket.view_all`.
- Marketplace context is intentionally opaque until its later domain exists and does not expose fabricated marketplace data.
- Added support-specific private attachment metadata while reusing the hardened verified-upload reader, signature/MIME inspection, image metadata stripping, filename normalization and shared private storage driver.
- Added storage compensation when DB persistence fails after an attachment object is written.
- Added fixed-window database anti-spam limits: 5 tickets/user/hour, 20/user/day and max 2 normalized duplicate payloads per 15 minutes; only SHA-256 fingerprints are stored.
- Added authenticated CSRF-protected native `GET|POST /support/new` UX with progressive disclosure and multipart attachment handling.
- Added additive idempotent migration `20260918012000_support_ticket_intake` with dynamic fields, intake, values, context links, attachments and rate-limit buckets.
- Updated ticket creation to participate safely in an existing transaction so ticket + intake + values + context + attachment metadata can commit atomically.
- Added regression coverage for field manipulation, unauthorized account context, duplicate rate limits, attachment persistence and escaped HTML.
- Feature commit: `174a1a76d3ad0aec1cea5e3cc83196c7f7385f59`.
- GitHub Actions build run `35360039776`: success.
- MySQL migration smoke run `35360039857`: success.
- Conversation, internal notes, canned responses, escalation, merge/split and status history remain scoped to 10.03.

## Completed in 10.01

- Added typed support-ticket priority and lifecycle states with explicit transition rules.
- Added support categories with editable default priority plus optional first-response and resolution SLA policy.
- Added immutable ticket entities containing requester, optional assignee, subject, priority, status, SLA snapshot, timestamps and optimistic-lock version.
- Added `SupportTicketService` for category access/management, ticket creation, own-vs-all retrieval, active staff queue, assignment, priority, lifecycle and first-response SLA marker.
- Enforced backend IDOR/BOLA boundaries: another user's ticket requires `support.ticket.view_all`; UI visibility is not used as authorization.
- Enforced assignee eligibility through the shared permission engine.
- Added `DatabaseSupportTicketRepository` with prepared SQL and version-checked optimistic writes.
- Added additive migration `20260918011000_support_ticket_domain` with category/ticket tables, requester/assignee/category foreign keys, queue/SLA indexes and safe built-in permission-template defaults.
- Seeded an editable general support category with 24-hour first-response and 72-hour resolution defaults; migration insertion does not overwrite later administrator edits.
- Added regression tests for SLA snapshot calculation, own-vs-other access, lifecycle resolve/reopen behavior, assignee permissions and SLA breach calculations.
- Feature commit: `452ec301f160cb1e548763763978af521dbe01a8`.
- GitHub Actions build run `35355388929`: success.
- MySQL migration smoke run `35355388937`: success.
- Dynamic category fields, attachment/form UX and anti-spam/rate limiting remain scoped to 10.02; conversation/staff tools remain 10.03; support dashboard/reporting/audit remains 10.06.

## Completed in 09.07

- Added a separate independent moderation oversight stream alongside the operational 09.06 Core Audit Stream.
- Added canonical redacted moderation payloads with payload SHA-256, previous hash, monotonic sequence and chained SHA-256 integrity values.
- Serialized concurrent moderation append operations through a row-locked chain state so two valid events cannot branch from the same previous hash.
- Kept the independent oversight append in the same moderation mutation transaction as the operational audit write; a failed oversight append therefore rolls back the moderation mutation.
- Added paged full-chain verification for sequence continuity, stored payload hash, previous-link integrity, chain hash and final chain-state consistency.
- Added review cases and anomaly flags as separate annotations without adding update/delete APIs for chained entries.
- Added backend self-review protection so an actor cannot open/resolve review cases or add/resolve anomaly flags for their own moderation event even when they hold `audit.review`.
- Added native `/moderation/oversight` UI, explicit full-chain verification and same-origin guarded review/flag mutations.
- Added additive idempotent migration `20260918010000_independent_moderation_oversight` with chain state, chained entries, review cases, anomaly flags and `audit.review` built-in template defaults.
- Existing pre-09.07 audit records are deliberately not backfilled into the tamper-evident chain because a retroactive hash cannot prove historical immutability.
- Feature commit: `6fc94d9e3a00c4c0752f705767a2d7b9c8dfd798`.
- CI fixes: `eca38cee3d15af414bf95bf6b100be2c2c3339bc` (test namespace import), `206d144ea7e4034c1c52608cdaff90506b34afce` (valid migration timestamp), `d99a81f92083981f504c81648776c5b7c445fe08` (typed self-review denial).
- Final GitHub Actions build run `35353769849` passed and final MySQL migration smoke run `35353769826` passed.
- Main roadmap step 09 is now complete; work advances to 10.01.

## Completed in 09.06

- Added a central core audit event model with operational scope, actor, extensible action, target, optional forum/reason context, request-id, before/after snapshots and UTC occurrence time.
- Added `forwext_core_audit_events` plus target/actor/request/scope/time indexes and a transaction-only `DatabaseAuditEventStore`.
- Added recursive persistence-time sensitive-data redaction for passwords, secrets/tokens, auth/session/credential material, private/recovery/TOTP/API keys, raw IP fields and e-mail fields while preserving privacy-safe fingerprints.
- Added `AuditRecorder` / `CoreAuditRecorder` so state mutation and its audit event can commit atomically.
- Redirected the existing moderation audit adapter to the central stream without changing thread/post/report/task/discipline/abuse domain-facing audit contracts.
- Added upgrade-safe legacy moderation audit import; historical actor/action/target/reason/request/time metadata is retained while pre-09.06 snapshot JSON is replaced by an explicit migration redaction marker.
- Added `audit.view` template defaults and a backend-permission-gated native `/moderation/audit` page with actor/request-id filters plus a conditional Moderation Workspace link.
- Made the existing `ForumMetadataAdminService` require an `AuditRecorder`; prefix, custom-field, forum configuration and forum-field administrative mutations now produce administration-scoped core audit events with before/after snapshots when available.
- Added additive idempotent migration `20260918005000_core_audit_stream`; the previous moderation audit table is not destructively dropped.
- Added regression coverage for recursive redaction, transaction enforcement, `audit.view` authorization, HTML escaping, central moderation persistence and ACP metadata actor/before-after/request-id recording.
- Feature/final implementation commit: `07a14555069e87ce169392ccfce028eda5f8c4b8`.
- GitHub Actions build run `35351960329` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency-baseline verification, cPanel full-package generation and artifact upload.
- Differential update-package/release publication was intentionally skipped because `VERSION` did not change; immutable update output remains release/version-bump driven.
- MySQL 8.4 migration smoke run `35351960288` passed the complete clean-install migration chain and idempotent second pass.
- Hash chaining, independent review cases, moderator self-record protections and anomaly flags were intentionally left for 09.07 rather than conflated with the operational core audit stream.

## Completed in 09.05

- Added a typed shared anti-abuse engine for registration, thread and post events with user, identity, IP, device and content signals.
- Added privacy-safe fixed-window automated rules with `allow < review < reject` severity; normal allow activity only advances counters while review/reject decisions create moderation events.
- Reused existing HMAC registration/authentication fingerprinting so raw IP, e-mail and user-agent values are not stored in anti-abuse tables.
- Added conservative default rules for registration IP/device abuse, thread user/device/content flood and post user/device/content flood; migration re-runs preserve administrator-edited rule values.
- Integrated registration review decisions into `PendingApproval` and rejection before account persistence while retaining existing CAPTCHA/Turnstile, disposable-email and registration rate-limit layers.
- Integrated thread/post review decisions with the existing pending moderation state and 09.03 approval queue; reject decisions stop content persistence instead of creating a parallel spam lifecycle.
- Added `moderation.abuse.view`, `moderation.abuse.manage_rules` and `moderation.abuse.cleanup` permissions with built-in template defaults.
- Added native `/moderation/abuse` rule/event management and a dedicated Anti-spam Moderation Workspace section with escaped output and same-origin mutation protection.
- Added spam cleanup through the existing `ContentModerationService` soft-delete path. Forum view/bulk/delete permissions remain mandatory and mixed thread/post cleanup plus abuse-event resolution/audit share one outer transaction.
- Added bounded retention maintenance for stale fixed-window counters and old resolved abuse events; unresolved review events are preserved.
- Added additive idempotent migration `20260918004000_abuse_prevention`; existing forum data is not reset.
- Feature/final implementation commit: `403d48ea4c54a94fdf06ea718b9d2ec92772c47b`.
- GitHub Actions build run `35341140370` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency-baseline verification, cPanel full-package generation and artifact upload.
- Differential update-package/release publication was intentionally skipped because `VERSION` did not change; immutable update output remains release/version-bump driven.
- MySQL 8.4 migration smoke run `35341140452` passed the complete clean-install migration chain and idempotent second pass.
- No new mandatory Node/Redis/Docker/Supervisor/daemon dependency was introduced for minimum cPanel runtime.

## Completed in 09.04

- Added persisted warning definitions with configurable points, optional expiry, active state and ordering; issued warnings snapshot point/expiry semantics so definition edits do not rewrite history.
- Added append-only discipline actions for warnings, posting/content restrictions, temporary suspensions and temporary/permanent bans, plus explicit row-locked revocation metadata.
- Added granular permissions: `moderation.discipline.view`, `moderation.warning.issue`, `moderation.warning.manage`, `moderation.restriction.manage`, `moderation.ban.manage` and `moderation.discipline.revoke`.
- Integrated posting/content restrictions into the common permission repository as synthetic user-level denies for forum posting, profile posting/comments and planned first-party content creation surfaces; frontend hiding is not relied on for authorization.
- Added `UserAuthenticationAvailability` and database discipline availability enforcement so active suspension/ban actions reject normal login and ordinary authenticated-session resolution; temporary expiry becomes effective directly from UTC time predicates without a mandatory worker.
- Added `/moderation/discipline` native PHP management UI, real Warning/Ban moderation-workspace sources, same-origin guarded mutations, escaped rendering and controlled conflict handling for stale/double revocation.
- Added `/account/discipline` for an affected user with an otherwise valid existing session to inspect their own action history, active warning points, expiry and stable appeal reference.
- Added stable `discipline:<action-id>` references and `moderation.discipline.appeal_available` domain events for later Support/Ticket integration without inventing a placeholder support route.
- Reused the durable notification system and existing moderation audit/request-id infrastructure for issue, definition change and revoke operations.
- Added additive idempotent migration `20260918003000_discipline_system` with three tables, four starter warning definitions, six permissions and built-in template defaults; no database reset is performed.
- Corrected the previously latent `ReportWorkspaceSource` interface mismatch before continuing: `latest()` now matches the shared workspace source contract. Compatibility fix commit: `f0bf4bd97ba549994b5c7a179f22976a56aa4a3d`.
- Feature commit: `ecf64e1b94bcb976cb644484fbea1b9192f46486`.
- Test-import/final implementation pointer: `dc5e5d04e627f6706064acdc3166acdf08abfb0c`.
- GitHub Actions build run `35338877155` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency-baseline verification, cPanel full-package generation and artifact upload.
- Differential update-package/release publication was intentionally skipped by the immutable release workflow because `VERSION` did not change in this roadmap commit; it runs when a version bump or explicit release dispatch occurs.
- MySQL 8.4 migration smoke run `35338877117` passed the complete clean-install migration chain and idempotent second pass.
- No new mandatory Node/Redis/Docker/Supervisor/worker dependency was introduced for cPanel runtime.

## Completed in 09.03

- Replaced the forum-only pending-content workspace adapter with a typed shared `ApprovalQueueRegistry` / provider contract for first-party moderated content domains.
- Added a real forum approval provider for pending threads and posts; later profile/portfolio/marketplace providers can join the same registry when those roadmap domains implement persisted moderation states.
- Added native PHP `/moderation/approval` queue UX with bounded bulk selection, approve/reject actions and controlled reason codes.
- Enforced `moderation.access` for reads and `moderation.manage` for mutations, then re-checked `forum.view`, the relevant node-scoped thread/post moderation permission and `forum.moderation.bulk` before forum candidates become actionable.
- Added first-class thread/post reject actions that persist the existing `rejected` lifecycle state instead of treating rejection as deletion.
- Added row-locked stale-decision protection so bulk approve/reject only mutates content that is still `pending`; concurrent or already-decided records fail rather than overwrite a newer moderator decision.
- Reused the existing moderation audit stream, request-id correlation and same-origin moderation mutation guard; no parallel authorization or audit system was introduced.
- Removed the superseded `ForumApprovalWorkspaceSource` and integrated the shared queue back into the existing moderation workspace through `ApprovalQueueWorkspaceSource`.
- Added regression tests for provider ownership, fail-closed access/manage permissions, provider dispatch/deduplication, HTML escaping, reject permission mapping, per-item/bulk audit events and stale-decision refusal.
- No database migration was required because the existing thread/post moderation states, permission tables and moderation audit storage are reused.
- Feature commit: `41e7a05693bb6711a3f1aa4780b2d504ab567090`.
- Test-fix/final implementation pointer: `7a1e825e11aa4d5375dde35f5c1b4bca99ed7b70`.
- GitHub Actions build run `35336290787` passed strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency-baseline verification and full/update package generation.
- MySQL 8.4 migration smoke run `35336290906` passed the clean-install migration chain and idempotent second pass.
- No new mandatory Node/Redis/Docker/Supervisor/worker dependency was introduced for cPanel runtime.

## Completed in 09.02

- Added the first-party permission-aware content report pipeline with a typed `ReportableContentRegistry`; client target type/id values cannot establish visibility or existence.
- Added forum thread/post report resolvers that re-read the content, require visible/non-deleted state and re-check node-scoped `forum.view` for the reporting actor.
- Added configurable report reasons with five seeded starter reasons: spam, harassment/insult, privacy, potentially illegal content and other.
- Added active duplicate grouping keyed by target type + target id + reason, protected by a unique database fingerprint; each reporter may submit only once per active group.
- Closing a report group clears the active dedupe key so a later incident can create a new independent case without rewriting report history.
- Added report assignment, open/in-review/resolved/rejected state, grouped reporter submissions and private moderator comments.
- Integrated active report cases into the existing `/moderation` workspace and added dedicated moderator detail/assignment/status/comment routes.
- Added same-origin report submission protection with `X-Forwext-Report`, configured Origin and Sec-Fetch-Site validation; moderator mutations reuse the existing moderation same-origin guard.
- Added `report.create` to the first-party permission catalog and also seed it in the new migration so upgrades do not depend on replaying an already-applied permission migration.
- Reused the durable notification subsystem for report receipt, moderator assignment and reporter-visible status changes with dedupe keys.
- Reused the moderation audit store for report assignment, status and internal-comment mutations.
- Added additive idempotent migration `20260918002000_report_system` for reasons, groups, submissions and moderator comments; no database reset is performed.
- Added native PHP report form/history UI, escaped moderator report review UI, architecture/changelog documentation and regression tests for target ownership, same-origin guards, escaping, notification registration, migration registration and safe workspace action paths.
- Feature commit: `9709c84abf019a9eb39737a8ce3302cbac02bf5c`.
- GitHub Actions build run `35322608889` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency baseline and cPanel full-package generation.
- MySQL 8.4 migration smoke run `35322608883` passed the complete clean-install migration chain and idempotent second pass.
- No new mandatory Node/Redis/Docker/Supervisor/worker dependency was introduced for cPanel runtime.

## Completed in 09.01

- Added one authenticated internal moderation workspace for reports, approval/pending content, warnings, bans and moderator tasks without creating parallel permission or authentication systems.
- Added a provider/read-model contract so later moderation domains can contribute real records to the same workspace. Report, warning and ban sections remain honest empty integration points until their dedicated roadmap steps implement those domains.
- Added a real forum approval source for existing pending threads/posts. Forum candidates are derived server-side and require both `forum.view` and the existing node-scoped `forum.thread.moderate` / `forum.post.moderate` permissions.
- Pending-content queries exclude deleted/merged content and expose only bounded identifiers, titles/positions and timestamps needed by the workspace; post bodies are not selected into the dashboard read model.
- Added persistent moderator tasks with priority, open/in-progress/done state, creator, optional assignee and optional UTC due date.
- Task creation/status mutations require `moderation.manage` and are recorded transactionally through the existing moderation audit store with dedicated workspace audit actions.
- Added same-origin mutation protection based on the configured canonical origin, `Sec-Fetch-Site` and `X-Forwext-Moderation`; actor identity is always resolved from the authenticated session.
- Added `/moderation` native PHP composition, escaped rendering, 401/403/404/400 handling, `no-store` and `noindex,nofollow` response controls.
- Added idempotent data-preserving migration `20260918001000_moderation_workspace_tasks` and migration registry integration.
- Added regression coverage for fail-closed workspace access, complete section composition, HTML escaping, same-origin mutation guard, task model and migration registration.
- No new mandatory Node/Redis/Docker/Supervisor/worker dependency was introduced for cPanel runtime.
- Feature commit: `812d2b8267e83560287af1ffe117977293ac10b7`.
- GitHub Actions build run `35279919780` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production dependency-baseline verification and cPanel package generation.
- MySQL 8.4 migration smoke run `35279919775` passed the complete clean-install migration chain and idempotent second pass.

## Completed in 08.06

- Added a shared `GlobalDiscoveryRegistry` that owns user-facing discovery categories and their exact backend `SearchDocument` type contracts.
- Added stable core categories for Forum, Support, SSS, Portfolio, Marketplace and Members, plus contributor support for later first-party/module integrations.
- Upgraded the native PHP search surface into a single global discovery UX with simple type tabs, grouped `all` results and advanced filters behind progressive disclosure.
- Kept `PermissionAwareSearchService` authoritative: discovery tabs and explicit type filters can only narrow search document types and never create or widen permission scopes.
- Added fail-closed validation for unknown, duplicate and cross-category document type filters. Saved searches cannot be combined with non-`all` discovery tabs or explicit type filters.
- Preserved escaped search rendering and existing member links while deliberately avoiding invented Support/SSS/Portfolio/Marketplace routes before those later modules provide real canonical surfaces.
- Reserved first-party search type contracts for later modules without generating fake records, fake URLs or placeholder results; empty/unimplemented categories simply return no authorized results.
- Added regression tests for category/type ownership, tab-bound type narrowing, duplicate/unknown rejection, grouped rendering, route non-fabrication and HTML escaping.
- No migration was required and no new cPanel runtime daemon/dependency was introduced.
- Feature commit: `8e9a8dacef66d51d32be9c4c2e57392e3d85aa11`.
- GitHub Actions build run `35276227997` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency-baseline verification, `forwext-v...-full.zip` package generation and artifact upload.
- MySQL 8.4 migration smoke run `35276227907` passed the complete clean-install migration chain and idempotent second pass.

## Completed in 08.05

- Added a shared ordered `NavigationRegistry` with stable same-origin paths, public/member audience metadata and first-party module-owned contribution support; navigation remains UX and never replaces backend permission checks.
- Added responsive registry-backed native PHP navigation and reusable escaped breadcrumb trails.
- Reused the existing `ForumNodeHierarchy::breadcrumb()` implementation and added per-node `forum.view` rechecks through `ForumBreadcrumbBuilder` instead of duplicating hierarchy logic.
- Upgraded `/members` from a fixed latest-user list to bounded public member search, newest/name sorting, counts and pagination; only active users with public profiles are returned and LIKE wildcard input is escaped while values remain parameterized.
- Added a dedicated presence model rather than treating long-lived authentication sessions as online status. Online activity uses a five-minute window and database heartbeats are throttled to one write per minute per user.
- Added presence visibility values `hidden`, `members` and `public`; the privacy-safe default is member-only. Anonymous visitors only see explicit public presence, while hidden users never appear.
- Added same-origin guarded heartbeat/preference writes and a native online-user page with user-controlled visibility. Online discovery also requires active account + public profile and therefore cannot bypass profile privacy.
- Added actor-specific forum stats. Forum/thread/post counts reuse server-derived `forum.view` scopes and exclude non-forum nodes, deleted/merged threads and non-visible content; client-supplied forum ids are never accepted as authority.
- Added migration `20260917233000_user_presence` with a cascading user foreign key and online lookup index, plus clean-install registry integration.
- Feature commit: `0514625c7a6a7abc35adab46098a4d19f7fdcfc1`.
- GitHub Actions build run `35273596810` passed Composer validation, strict-types, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency-baseline verification, `forwext-v...-full.zip` package generation and artifact upload.
- MySQL 8.4 migration smoke run `35273596912` passed the complete clean-install migration chain and idempotent second pass.

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
- Search/discovery foundation includes index lifecycle (`08.01`), advanced filters (`08.02`), permission-aware discovery (`08.03`), SEO/public-discovery feeds (`08.04`), navigation/member/presence/stats UX (`08.05`) and global content discovery UX (`08.06`).
- Moderation foundation now includes the single internal workspace/read-model composition and persistent moderator tasks (`09.01`) plus the permission-aware report lifecycle (`09.02`); cross-domain approval queue and discipline/ban systems continue in `09.03–09.04`.
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
