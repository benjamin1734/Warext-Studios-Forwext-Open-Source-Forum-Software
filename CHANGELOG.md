# Changelog

All notable Forwext development changes are recorded here.

Forwext follows the binding project roadmap during pre-release development. Semantic version release entries become authoritative once releasable packaging begins.

## Unreleased

### 13.03 — Giveaway Domain and Lifecycle

- Added first-party giveaway creation, prize/terms/schedule fields, per-user entry allowance, maximum participant cap and typed lifecycle states.
- Enforced backend owner/staff permissions, immutable ownership, draft editing boundaries and audited create/update/publish/cancel mutations.
- Added native PHP listing/detail/management routes, dedicated CSRF protection, member navigation and permission-aware search/discovery integration.
- Added deterministic scheduled/open/closed lifecycle maintenance with cPanel-safe bounded read-repair and optional one-minute advanced scheduler processing.
- Added migration `20260919143000_giveaway_domain`, architecture documentation and permission/lifecycle/maintenance regression coverage.
- Deferred participation eligibility/anti-abuse to 13.04, winner selection to 13.05 and shared reward fulfillment to 13.08.
- Completion commits: `d7e66fad0d5272e9950e1b56a39d2485dd234de8`, `560d6c90cec37066cc20175d24491dc961d9496c`, `7f91334ba68e3c6fa4e41d731231daf153e5f606`, `31e2d2d28e564d3ccee688631ed2baa46796ab84`.
- GitHub Actions build run `35447590221`: success.
- Database migration smoke run `35447590228`: success on MySQL 8.4 and MariaDB 10.11.

### 13.02 — Invitation and Referral System

- Added public high-entropy referral links, campaign/expiry policy, first-touch attribution, privacy-safe anti-fraud signals and qualified referral lifecycle.
- Kept referral attribution isolated from security-sensitive registration invite gating and made referral failures non-blocking after successful registration.
- Added member referral/reward analytics, staff campaign/review management, same-origin capture cookies and bounded cPanel/manual plus scheduler qualification paths.
- Added central audit and notification integration and an idempotent reward ledger that will connect to the shared reward-provider API in 13.08.
- Added migration `20260919140000_referral_system`, architecture documentation and anti-fraud/lifecycle/registration regression coverage.
- Completion/hardening commits: `37a9dd847bb3bad0cc8ad7530c2db781f7436fa9`, `9be41d11a9c855968c1fac180d4c55eafa13a3c5`, `8f604b7ad2596142c2507fd7b72715729335e81b`.
- GitHub Actions build run `35439089180`: success.
- Database migration smoke run `35439089182`: success on MySQL 8.4 and MariaDB 10.11.

### 13.01 — Portfolio System

- Added portfolio projects, categories/tags, comments/reactions, featured projects and profile-tab integration.
- Added backend portfolio permission defaults, native PHP management/detail/listing surfaces and public navigation.
- Integrated portfolio content with shared spellcheck/AI moderation pipeline, approval queue, central moderation audit and global discovery search.
- Added secure portfolio image uploads using the existing attachment inspection policy, private storage and integrity-checked media delivery.
- Added additive migrations for portfolio schema/profile-tab defaults and storage-backed media metadata.
- Added regression coverage for permissions, lifecycle moderation, navigation/search and media safety.
- Completion commit: `0eb61484c67e611b0676064848431b5cb0025438`.
- GitHub Actions build run `35438353652`: success.
- Database migration smoke run `35438353632`: success on MySQL 8.4 and MariaDB 10.11.

### 12.08 — Failure and Graceful Degradation

- Verified that the core forum content pipeline persists normally with AI disabled and without a long-running worker runtime.
- Hardened missing AI provider and removed-prompt configurations into human-review fallback assessments instead of request-failing exceptions.
- Kept AI outage safety fail-closed-to-review: operational/configuration fallbacks enter moderation queue rather than bypassing moderation or rejecting solely because an optional provider is unavailable.
- Added `ResilientSearchDriver` so optional external-search query failures fall back to native search.
- Kept native search current during external index-write failures while surfacing a retryable error to the existing search lifecycle backoff/retry mechanism.
- Routed native web search through the resilient driver with the native DB search driver as the minimum-profile fallback.
- Added optional-service failure architecture documentation and regression coverage for AI, worker-independent persistence, external-search fallback and retryable index synchronization.
- Feature/completion commit: `c9b18c331ed0fe4a5647c6d3f7a9055756246266`.
- GitHub Actions build run `35436742890`: success on PHP 8.4/8.5 with production package checks.
- Database migration smoke run `35436742879`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.

### 12.07 — Cross-system Audit / Permission Integration

- Unified AI moderation, spellcheck, user content manager and thread-freshness mutations around the shared backend permission and core audit infrastructure.
- Added request-id propagation from native HTTP mutation handlers into central audit events for log/audit correlation.
- Added central audit coverage for AI moderation feedback, personal/site spellcheck dictionary changes, content-manager enqueue, freshness policy changes, renewal, review resolution and moderator-triggered maintenance.
- Kept sensitive payloads out of audit snapshots: spellcheck words are fingerprinted and AI feedback notes are not copied into the audit stream.
- Fixed the permission catalog drift between legacy `freshness.*` aliases and the real node-scoped `forum.thread.freshness.*` runtime permissions.
- Added additive migration `20260919110000_content_governance_integration` to preserve non-conflicting legacy global/node grants, prefer existing canonical rules and remove shadow permission aliases.
- Hardened the manual freshness maintenance path so a reviewer can only mutate threads in forums where `forum.thread.freshness.review` is currently allowed.
- Preserved scheduler maintenance as a system path without fabricating a user actor in the human audit stream.
- Added migration, permission, audit privacy and node-scope regression coverage.
- Feature commit: `0e0a1d4b6fcd64678d54b8c3f2232f5a08a9e1e8`; hardening/completion commit: `d20173c7267cf633b5d3dad859945c184c5b39c3`.
- GitHub Actions build run `35436134573`: success; strict-types, lint and PHPUnit passed on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel FULL build passed.
- Database migration smoke run `35436134592`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.

### 12.06 — Thread Freshness Policies

- Added per-forum stale windows, author-notification, auto-unfeature, auto-lock, moderator-review and auto-archive thresholds with renewal cooldowns.
- Added an independent freshness activity clock and visible stale/archive status model.
- Added author renewal plus moderator/admin renewal/reopen authority without silently removing pre-existing manual moderator locks.
- Added durable stale-topic author notifications and moderator review cases with keep, renew and archive resolutions.
- Added bounded `thread.freshness.maintain` maintenance processing, 15-minute scheduler registration and a cPanel-safe manual maintenance fallback.
- Made archived topics leave normal thread listings and native search, reject new replies and synchronize related search documents on archive/reopen.
- Added new-activity freshness reset and automatic resolution of obsolete pending stale-review cases.
- Added backend `renew_own`, `renew_any`, `review` and `manage_policy` permissions plus CSRF-protected renewal/review/policy web surfaces.
- Added additive migration `20260919100000_thread_freshness_system`, architecture documentation and regression coverage.

### 12.05 — User Content Manager

- Added a permission-gated user content inventory across forum threads and posts with user/type/forum/state/deleted/text filters.
- Added dry-run previews and immutable frozen target sets before queued execution.
- Added bounded bulk delete, restore, move, approve, reindex and reprocess actions with a 5,000-target safety boundary.
- Reused existing moderation persistence/audit behavior for state-changing operations and native search lifecycle changes for discoverability updates.
- Added `ContentPipeline::preprocess()` for side-effect-free reprocessing through the canonical pre-persist stages.
- Added durable operation/item progress, SKIP LOCKED processing claims, stale-work recovery and the `content.manager.execute` queue job.
- Added native CSRF-protected content-manager and operation-progress surfaces, including a bounded cPanel-safe manual processing fallback.
- Activated backend-authoritative `content_manager.access` / `content_manager.execute` defaults for moderator and administrator templates and re-check execute permission at worker time.
- Added additive migration `20260919090000_content_manager_system`, architecture documentation and regression coverage.
- Hardened UTF-8-safe content excerpt truncation.

### 12.04 — Turkish Spellcheck and Dictionaries

- Replaced the spellcheck placeholder with a provider-neutral advisory spelling-assistance service and pipeline processor.
- Added a conservative first-party Turkish provider with Unicode-safe issue offsets and deterministic suggestions for common misspellings.
- Added extensible language-provider registry plus database-backed personal and site dictionaries.
- Added backend permission defaults for spellcheck use, own dictionary management and administrator-only site dictionary management.
- Added authenticated `/editor/spellcheck` and CSRF-protected `/account/spellcheck-dictionary` native web surfaces.
- Added rich-editor spelling checks with marked context, selectable findings, one-click replacements and stale-result protection.
- Added additive migration `20260919080000_spellcheck_system` and regression coverage across provider, dictionary, pipeline and editor/web integrations.

### 12.03 — AI Privacy, Cost and Prompt Management

- Integrated AI provider credentials with the encrypted first-party secret store.
- Added sensitive-data redaction before external AI requests while preserving original-content fingerprints.
- Added versioned prompt registry and prompt-version propagation to prompt-based and custom providers.
- Added normalized token usage, configurable micro-cost calculation and durable usage metrics.
- Added per-forum provider, prompt, redaction, threshold and pricing policies.
- Added persisted false-positive/false-negative moderation feedback and `ai.manage`-gated feedback service.
- Added additive migration `20260919070000_ai_moderation_privacy_cost_policy` and architecture/regression coverage.

### 12.02 — AI Content Moderation

- Added provider abstraction for OpenAI, Gemini, Anthropic, OpenRouter and hardened custom HTTPS endpoints.
- Added normalized moderation risk scoring with allow, flag, queue and reject actions.
- Added timeout/provider-error fallback that defaults to human review.
- Added exact-content human overrides, persistent decisions and `ai.moderation.override` permission defaults.
- Added SSRF-resistant endpoint validation and pinned HTTPS transport.
- Integrated AI moderation into the common forum content pipeline while retaining an AI-disabled pass-through mode.
- Added additive migration `20260918030000_ai_moderation_workflow` and provider/policy/override regression coverage.

### Root front-controller routing repair — 0.0.7.08-dev

- Fixed cPanel/LiteSpeed installs where the home page loaded but clean routes such as `/search`, `/members`, `/faq` and `/stats` returned server-level 404 responses.
- The release pipeline continues to preserve hosting-managed `.htaccess` files, while the installer now merges Forwext routing into both the project root and `public/` document-root layouts without deleting existing cPanel PHP handlers.
- Runtime bootstrap now self-heals both managed routing files for already-installed sites, allowing a normal side-update from `0.0.7.07-dev` to repair routing without reinstalling or resetting the database.
- Added packaging-policy regression coverage that requires preserved `.htaccess` files to have matching installer/runtime repair paths.

### cPanel/subfolder routing hotfix — 0.0.7.07-dev

- Fixed navigation 404s when Forwext is served from a URL subfolder such as `/public`.
- Runtime web factories now reconcile an empty configured canonical path with the actual front-controller script directory without overriding an explicitly configured canonical base path.
- The installer now includes its own URL directory in the suggested canonical Site URL, so `/public/install.php` proposes a canonical URL ending in `/public`.
- Added post-install smoke coverage for `/`, `/search`, `/members`, `/faq`, `/members/online` and `/stats` at both document-root and `/public` deployment paths.
- Added mandatory `php -l` validation for all first-party PHP sources before PHPUnit and packaging.

### Post-install web bootstrap hotfix — 0.0.7.06-dev

- Fixed the production route contract for `DisciplineAccountHandler`, which caused every post-install web bootstrap to fail with a `TypeError` and return HTTP 500.
- Added a real post-install web smoke test that runs `InstallationService`, loads the installed version, builds the production router, renders `/`, applies SEO/CSP decoration and requires HTTP 200.
- The post-install smoke now runs against both MySQL 8.4 and MariaDB 10.11, preventing “installer succeeds but site immediately fails” releases.
- Added privacy-safe runtime failure correlation: bootstrap exceptions show a short reference and write a redacted diagnostic chain to `storage/logs/runtime.log`.
- Installed-version loading is now covered by the runtime failure boundary so malformed runtime state receives the same correlated diagnostics.

### Installer / cPanel compatibility hotfix — 0.0.7.05-dev

- Preserved cPanel-managed PHP handlers by keeping public `.htaccess` out of generated release payloads and merging Forwext routing rules through the installer instead of overwriting the file.
- Added legacy-runtime guards so an unsupported PHP runtime reports the PHP 8.4+ requirement instead of failing with a parser error.
- Added correlated installer failure diagnostics under `storage/logs/install.log` without exposing secrets to the browser.
- Added MariaDB 10.11 alongside MySQL 8.4 migration smoke coverage.
- Fixed the release/runtime version contract so published Forwext side-update versions such as `0.0.7.05-dev` are accepted by the installer.
- Release CI now validates `VERSION` with the same application parser before producing or publishing packages.
- The installer validates the project version before changing `.htaccess`, secrets, generated configuration or database state.

### 12.01 — Common Content Pipeline

- Added the canonical `validation → spam → spellcheck → AI moderation → moderation policy → persist → notify → index` contract.
- Added a fail-fast pipeline registry requiring exactly one processor for every pre-persist stage.
- Added strict UTF-8/control-character/length validation and adapted the existing `AbuseEngine` into the spam stage.
- Added review propagation plus after-persist abuse finalization using the real thread/post target id.
- Added explicit pass-through spellcheck and AI moderation extension points reserved for roadmap 12.04 and 12.02.
- Added transactional persist/notify/index orchestration so index queue failures roll back the same content write.
- Added durable search-change enqueue through the existing search lifecycle queue.
- Integrated the pipeline with thread creation, first posts, replies and post edits.
- Added regression coverage for canonical stage order, rollback behavior, registry completeness, review behavior and search enqueue.
- No schema or permission migration is required for 12.01.

### 11.05 — Staff Bug Dashboard and Duplicate Workflow

- Added permission-gated `/bugs/staff` queue with title/summary search and status, severity, category and assignee filters.
- Added staff assignment, category, severity and lifecycle controls to the existing bug detail surface.
- Added advisory duplicate similarity suggestions plus explicit canonical duplicate linking; similarity never changes state automatically.
- Added persisted duplicate relations with foreign keys/indexes and migration `20260918025000_bug_staff_workflow`.
- Added staff summary/category analytics and filtered CSV export with spreadsheet formula-injection mitigation.
- Added centralized `bug` audit scope covering reporter/staff replies, workflow mutations, duplicate links and exports.
- Added `bug.report.export` and `bug.audit.view` backend permissions with safe built-in template defaults.
- Added regression coverage for duplicate ranking, atomic explicit linking, audit behavior, dashboard escaping and CSV safety.

### 11.04 — My Bug Reports

- Added authenticated `/bugs` reporter history and permission-aware `/bugs/{reportId}` detail/follow-up flow.
- Added append-only public bug messages plus granular own/all reply permissions.
- Added durable staff-reply, reporter-follow-up and status-change notifications through the existing notification subsystem.
- Added report-authorized private attachment downloads with stored size/SHA-256 verification.
- Added additive migration `20260918024000_bug_report_conversation` and IDOR/XSS/notification regression coverage.

### 11.03 — Bug Report Form and Attachments

- Added global authenticated bug-report access and native CSRF-protected `/bugs/report` GET/POST UX.
- Added reproduction steps, expected/actual results and path-only source-page context.
- Added private screenshot/file uploads using the shared hardened upload inspection pipeline.
- Preserved 11.02 server-derived diagnostic context as authoritative rather than trusting hidden form data.
- Added additive migration `20260918023000_bug_report_form_intake` plus atomic submission/storage-compensation tests.

### 11.02 — Automatic Bug Diagnostic Context

- Added privacy-safe URL path, route, user, forum/thread/post, theme/module, browser/device and request-id capture.
- Raw query strings, request bodies, cookies, IP addresses and User-Agent text are not persisted.
- Added HMAC User-Agent fingerprinting and bounded browser/OS/device classification.
- Added atomic bug-report + diagnostic-context submission and migration `20260918022000_bug_diagnostic_context`.
- Added regression coverage for privacy stripping, route context, fallbacks and transaction participation.

### 11.01 — Bug Report Domain and Workflow

- Added typed bug-report severity, category and `new/in_review/resolved/rejected/duplicate` lifecycle states.
- Added permission-aware create/own/all access, granular assignment, optimistic-lock mutations and append-only public/staff tracking history.
- Added backend IDOR/BOLA protection plus assignee eligibility checks against bug staff access.
- Added additive migration `20260918021000_bug_report_workflow` with starter categories, indexes, foreign keys and safe permission-template defaults.
- Added regression coverage for lifecycle/finalization, history visibility, category/severity changes and assignment security.

### 10.06 — Support Panel, My Tickets, Reporting and Audit

- Added authenticated My Tickets and permission-gated staff support dashboard surfaces.
- Added SLA breach/response-time/category reporting over existing ticket state.
- Added `support.report.view` and `support.audit.view` permissions.
- Reused the central audit stream with a new `support` scope and atomic privacy-safe support mutation records.
- Added migration `20260918020000_support_reporting_audit` plus permission/audit/XSS regression coverage.

### 10.05 — FAQ ↔ Support Bridge

- Added permission-safe FAQ recommendations during ticket creation and after ticket resolution.
- Added bounded DB candidate discovery followed by authoritative `FaqService` visibility rechecks.
- Added granular staff FAQ-draft suggestions from public ticket replies only.
- Added FAQ-manager review that converts accepted suggestions into inactive/staff-visible FAQ drafts.
- Added migration `20260918015000_faq_support_bridge` and `support.faq_draft.suggest` permission defaults.

### 10.04 — FAQ System

- Added category/question/answer FAQ domain with tags, language, visibility, ordering and SEO fields.
- Added helpful analytics, permission-gated JSON import/export and privacy-safe exported content.
- Added `faq.article` native search indexing with server-derived public/member/staff scopes.
- Added public/native FAQ pages, management surface, dedicated CSRF and escaped rendering.
- Added FAQPage SEO structured data plus sitemap/feed discovery for public active content.
- Added additive migration `20260918014000_faq_system` and regression coverage.

### 10.03 — Support Conversation and Staff Tools

- Added append-only requester/staff ticket conversation, staff-only internal notes and immutable workflow history.
- Added granular support permissions for all-ticket reply, internal notes, assignment, escalation, merge, split and canned-response administration.
- Added first-response SLA recording, resolved-ticket reopening, canned responses and durable support notifications.
- Added non-destructive same-requester merge with message provenance plus public-message-only ticket split.
- Added native permission-aware ticket detail/staff-tool UX and ticket-authorized private attachment downloads.
- Added additive migration `20260918013000_support_conversation_tools` and regression coverage.

### 10.02 — Ticket Creation UX and Form System

- Added category-specific dynamic support fields with server-side validation and historical value snapshots.
- Added required ticket description, typed thread/account/marketplace context links and private support attachments.
- Reused hardened upload inspection/private storage primitives without weakening forum/post attachment authorization.
- Added per-user hourly/daily and duplicate-payload database rate limits using privacy-safe fingerprints.
- Added native CSRF-protected `/support/new` GET/POST creation UX.
- Added additive migration `20260918012000_support_ticket_intake` and regression coverage for manipulation, rate-limit, attachment and XSS paths.

### 10.01 — Internal Support/Ticket Domain

- Added typed support category, priority, lifecycle and SLA metadata domain models.
- Added requester/staff permission boundaries with backend own-vs-all ticket checks and assignee eligibility validation.
- Added optimistic-lock ticket persistence and additive migration `20260918011000_support_ticket_domain`.
- Added editable category SLA defaults whose due dates are snapshotted onto each ticket.
- Added regression coverage for SLA calculation, IDOR/BOLA boundaries, lifecycle transitions and staff assignment policy.
- Dynamic forms, conversation tools, FAQ integration and support dashboard/reporting remain in their dedicated 10.02-10.06 steps.

### 09.07 — Independent Moderation Oversight

- Added a separate append-only SHA-256 moderation hash chain with monotonic sequence and row-locked chain state.
- Added explicit paged integrity verification covering payload, sequence, previous-link, chain hash and final state.
- Added review cases and anomaly flags without allowing chained moderation entries to be edited or deleted.
- Enforced backend self-review protection even for users holding `audit.review`.
- Added native `/moderation/oversight` UI and additive migration `20260918010000_independent_moderation_oversight`.
- The tamper-evident chain begins at 09.07 activation; older operational audit events are not falsely presented as retroactively verified.

### 09.06 — Core Moderator/Admin Audit

- Added a central moderator/admin audit event model with scope, actor, action, target, request-id and before/after snapshots.
- Added persistence-time recursive sensitive-data redaction and transaction-only audit writes.
- Redirected the existing moderation audit adapter to the central stream and added mandatory audit recording to the existing forum-metadata ACP service.
- Added native `/moderation/audit` browsing with backend `audit.view` permission checks and actor/request-id filters.
- Added additive migration `20260918005000_core_audit_stream`; legacy moderation event metadata is preserved while legacy snapshot payloads are safely redacted during import.
- Hash-chain/independent oversight remains intentionally scoped to 09.07.

### 09.05 — Anti-Spam / Abuse Tools

- Added privacy-safe fixed-window automated rules for registration, thread and post abuse using user/identity/IP/device/content signals.
- Integrated registration review/reject with account admission and thread/post review with the existing approval queue; reject decisions stop persistence.
- Added native `/moderation/abuse` rule/event management plus a dedicated Moderation Workspace Anti-spam section.
- Added transactional spam cleanup through the existing content moderation soft-delete, permission and audit paths.
- Added `moderation.abuse.view`, `moderation.abuse.manage_rules` and `moderation.abuse.cleanup`.
- Added additive idempotent migration `20260918004000_abuse_prevention`; no database reset or mandatory advanced runtime service is introduced.
- Added bounded daily retention cleanup for stale fixed-window counters and old resolved abuse events; unresolved review items are preserved.

### 09.04 — Warning / Discipline / Ban

- Added configurable warning definitions with points and optional expiry, durable warning/restriction/suspension/ban history, and typed revocation.
- Added temporary suspension plus temporary/permanent ban enforcement for login and ordinary authenticated sessions.
- Added posting/content restrictions as backend permission-engine user denies for current and planned first-party content creation permissions.
- Added granular discipline permissions, moderation audit events, durable user notifications, native moderation/account UI and real moderation-workspace sources.
- Added a stable `discipline:<action-id>` appeal reference and `moderation.discipline.appeal_available` domain hook for later Support integration.
- Added additive idempotent migration `20260918003000_discipline_system`; no database reset or mandatory advanced runtime service is introduced.

### 09.03 — Approval / Moderation Queue

- Added a typed first-party approval queue registry/provider contract so current and future moderated content domains share one queue instead of parallel moderation applications.
- Added a permission-aware forum provider for pending threads/posts with native PHP bulk approve/reject UI under `/moderation/approval`.
- Reused `moderation.access`, `moderation.manage`, node-scoped view/moderate permissions and `forum.moderation.bulk` with backend enforcement.
- Added first-class thread/post reject moderation actions that persist the existing `rejected` lifecycle state, write per-item audit events and preserve bulk audit summaries.
- Added row-locked stale-decision protection: approve/reject only succeeds while stored content is still `pending`.
- Integrated the common queue back into the existing moderation workspace and reused the same-origin moderation mutation guard and request-id correlation.
- Added regression coverage for provider ownership, permission fail-closed behavior, dispatch deduplication, escaping, reject permission mapping and stale-decision protection.
- No database migration or additional cPanel runtime service is required.

### 06.01 — Node, Category and Forum Hierarchy

- Added first-class category, forum, page and link nodes with opaque 128-bit ids and globally unique canonical slugs.
- Added true subforum support while keeping page/link nodes as hierarchy leaves.
- Added deterministic sibling ordering, root-to-current breadcrumbs and bounded hierarchy validation for orphan parents, duplicate ids/slugs, illegal leaf parents, cycles and excessive depth.
- Added listed, unlisted and disabled visibility with ancestor propagation while keeping visibility strictly separate from authorization.
- Added node-scoped `forum.view` authorization through the shared actor-bound permission gate; disabled node chains fail closed before permission lookup.
- Added forum settings for thread/reply policy, approval policy, default sorting and threads-per-page without prematurely implementing thread/post tables.
- Added safe page/link payload validation, including credential-free HTTPS-only external links and bounded page source text.
- Added transactional database persistence with row-locked hierarchy revalidation and child-aware deletion refusal.
- Added migration `20260915235930_forum_nodes`, installer registry coverage, domain/repository/authorization/migration tests and architecture documentation.
- GitHub CI passed PHP 8.4 and PHP 8.5 PHPUnit, strict-types, Composer metadata, production dependency, full/update package-build and release checks.

### 05.07 — Permission Security Test Matrix

- Added actor-bound `PermissionGate` so UI visibility and backend enforcement use the same trusted authenticated actor and shared authorization decision.
- Added generic permission-denial exceptions without leaking repository/provider internals.
- Added mandatory IDOR/BOLA, moderator/admin bypass, multi-role deny, inheritance, node/global precedence, numeric-limit and UI/backend parity regression tests.
- Kept the matrix inside the standard PHPUnit tree so permission regressions block both PHP 8.4/8.5 CI and release package production.
- Added permission-security documentation and the extension rule for future protected first-party/add-on authorization shapes.

### 05.06 — Content and System Permission Namespaces

- Registered 83 typed first-party permission definitions across 28 namespaces spanning forum, moderation, independent audit, ACP, profile, support, FAQ, bug reports, portfolio, invite/referral, AI, spellcheck, user-content management, freshness, giveaway, Easter Egg, trophies, promotions/rewards, marketplace, payments, subscriptions, ads/notices, analytics, appearance and API surfaces.
- Added shared `PermissionAuthorizer` and persisted user-access assignment loading for first-party runtime integration.
- Replaced temporary profile music and custom-profile-URL authorization baselines with shared-engine resolvers while preserving their domain interfaces.
- Added a narrow compatibility group only for real pre-05.x users missing persisted primary-group assignments; it grants no new staff/ACP/marketplace/etc. capabilities.
- Added migration `20260915235900_permission_namespaces`, starter-template bridge rules, installer registration, catalog/assignment/authorizer/runtime/migration tests and architecture documentation.
- Fixed the initially invalid hour-24 migration timestamp after CI rejected it; the final migration id is valid and chronological.

### 05.05 — Role Appearance and Banner System

- Added a dedicated role-presentation model that remains separate from authorization and cannot grant, deny or alter permissions.
- Added validated role colors, optional gradients, built-in icons, banners, patterns and animations without accepting arbitrary HTML, CSS declarations or external URLs.
- Added independent mobile, profile and post visibility controls while keeping role name and priority authoritative in the existing role model.
- Added parameterized one-to-one role-appearance persistence with cascading cleanup when a role is deleted.
- Added an escaped native PHP renderer plus responsive CSS with built-in pattern/icon/animation support and reduced-motion handling.
- Added migration `20260915230000_role_appearance`, installer registration, repository/domain/renderer/migration tests and architecture documentation.
- Fixed the clean-install migration registry so the previously completed 05.01 role/group, 05.02 permission-engine and 05.03 permission-template migrations are also guaranteed to run and are protected by a regression test.

### 05.04 — Permission Analyzer and Explanation UX

- Added a permission analyzer that delegates authorization to the existing permission engine rather than duplicating precedence logic.
- Added human-readable allow/deny summaries, effective numeric-limit explanations and privacy-safe fail-closed messages.
- Added ordered visualization for node user, global user, node membership, global membership and secure fallback layers.
- Added explicit states for not-applicable, no-rule, inherited, allowed, denied, fail-closed and not-reached outcomes.
- Added an escaped accessible native PHP explanation renderer with stable state/effect/outcome hooks for later ACP styling.
- Added analyzer/renderer tests and architecture documentation without adding a migration or new runtime dependency.

### 05.03 — Permission Templates and Starter Profiles

- Added typed permission-template domain objects, validation and repository boundaries for reusable starter profiles.
- Added five protected built-in profiles: new user, member, verified member, moderator and administrator.
- Added a transactional one-click template applier that upserts only template-owned permission keys into normal global rules, preserving unrelated custom rules for later customization.
- Added safe starter permission definitions for forum access/content creation, numeric daily content limits, moderation access/management and ACP access/management.
- Added migration `20260915220000_permission_templates` with template/rule persistence, foreign-key integrity and deterministic built-in seed verification.
- Added template-domain, transactional writer and migration coverage while keeping the cPanel baseline free of new runtime services or PHP extensions.

### 05.02 — Global and Node Permission Engine

- Added typed flag/numeric permission definitions with `allow`, `deny` and `inherit` effects for user, group and role subjects.
- Added deterministic precedence: node user → global user → node membership → global membership → implicit deny.
- Added same-tier deny-over-allow handling, inheritance fall-through and most-restrictive numeric aggregation for combined group/role limits.
- Added direct per-user overrides and generic node/forum-scoped rules without prematurely coupling the engine to a forum table introduced later.
- Added fail-closed handling for unknown permissions, malformed rules and repository failures without exposing internal exception details.
- Added parameterized database lookup, migration `20260915210000_permission_engine`, tests and a machine-readable decision trace for the later permission analyzer.

### 05.01 — Role and User-Group Model

- Added explicit user-group and role domain models so membership classification remains separate from functional/presentation roles.
- Added custom, staff and protected system role kinds with stable identifiers and bounded priority metadata.
- Added one primary group, multiple secondary groups and independent direct role assignments with domain-level duplicate/invariant protection.
- Added normalized group, role and membership/assignment tables with restrictive role/group deletion and cascading cleanup on user deletion.
- Added domain/migration tests and architecture documentation while preserving the minimum cPanel deployment profile.

### 04.08 — Custom Profile URL System

- Added a dedicated `ProfileUrlPermissionResolver` boundary so the shared 05.x role/group engine can govern custom profile URLs without rewriting URL persistence, services or HTTP handlers.
- Added canonical 3–32 character lowercase ASCII profile slugs with single-hyphen rules and a configurable case-insensitive reserved-name policy for system, staff and brand namespaces.
- Added permanent custom-URL claim history plus one-current-slug-per-user persistence through migration `20260915143000_custom_profile_urls`; retired slugs are never reassigned, including after account deletion.
- Added transaction-safe owner serialization, row-locked claim checks, database uniqueness constraints and duplicate-key race mapping so competing claims fail as a normal unavailable-slug result instead of a database 500.
- Added configurable abuse controls with a 24-hour default change cooldown, a 30-day default window and three actual changes per window while keeping same-slug submissions idempotent.
- Added native `/u/{slug}` profile routing with privacy-aware `308` historical/case canonicalization only after profile visibility is authorized; hidden profiles remain `404` without leaking the current URL.
- Added authenticated `/account/profile-url` GET/POST settings with shared CSRF middleware, a dedicated CSRF scope, domain-separated key derivation and generic non-disclosing assignment failures.
- Added an owner-only profile link to the custom-URL settings surface while keeping backend ownership/permission enforcement authoritative.
- Added domain/store race-limit-history tests, migration-schema tests, HTTP privacy/canonicalization tests, a real CSRF cookie/token round trip and architecture/security documentation.
- Completed Main Step 04 and advanced the roadmap to `05.01 — Role and user-group model`.

### 04.07 — Profile Music System

- Added a dedicated `ProfileMusicPermissionResolver` boundary for use, upload, external-source, autoplay and moderation capabilities so the shared 05.x role/group engine can replace baseline permissions without rewriting the music domain.
- Added persisted per-user music source/preferences for enablement, title, visibility, volume, mute, autoplay and loop, plus append-only moderation history with actor, UTC timestamp and bounded reason codes.
- Added private content-addressed MP3/Ogg/WAV/M4A uploads with binary-signature validation, bounded size limits, safe replacement ordering and no direct public-storage URLs.
- Added exact-host HTTPS external-source policy with no credentials, fragments, wildcard inheritance, IP literals or localhost; external music remains disabled by default and requires an explicit host allowlist.
- Added dynamic CSP `media-src` construction from the normalized external allowlist and fail-closed rendering when a previously stored host is later removed from policy.
- Added the native `/members/{username}/music` protected endpoint with current account/profile/music permission checks, moderation checks, `private, no-store`, nosniff and single byte-range `206`/`416` delivery.
- Added a responsive native `<audio>` profile player with persisted volume/mute/loop state and first-party initialization script; HTML never emits the `autoplay` attribute, programmatic autoplay starts muted and coarse-pointer/mobile clients require user interaction.
- Added versioned migration `20260915140000_profile_music`, domain/persistence tests, HTTP range/player/CSP tests and machine-readable profile-music policy documentation.
- Kept the cPanel baseline free of Node, Redis, worker daemons, ffmpeg and media-transcoding extensions.

### 04.06 — Profile and Profile Media System

- Added persisted per-user profile data for about text, avatar/banner references, profile/section visibility, normalized social links and configurable profile tabs.
- Added owner-safe profile authorization with public/member/private visibility and an explicit future integration boundary for the shared role/permission engine.
- Added private avatar/banner storage with JPEG/PNG/WebP validation, byte/dimension limits, content-addressed names and safe replacement/remove ordering.
- Added native PHP `/members`, `/members/{username}`, avatar and banner routes through the shared Router instead of embedding SQL in the public entry point.
- Added validated authentication-session viewer resolution; request/query/form input cannot promote anonymous visitors to member or staff visibility.
- Added privacy-preserving 404 behavior for unknown/hidden profiles and media, plus `private, no-store` protected media delivery and nosniff headers.
- Added escaped plain-text profile rendering and HTTPS-only social links with safe outbound-link attributes.
- Added versioned migration `20260915130000_user_profile_media`, profile directory/query services, persistence tests and HTTP privacy/media tests.
- Kept the cPanel baseline free of Node, Redis, daemons and image-manipulation extensions; stock web composition uses local private storage and existing file/database session drivers.

### 04.05 — OAuth and Connected Accounts

- Added provider-neutral OAuth/connected-account architecture with first-party Google and Discord providers.
- Added state-bound authorization transactions with PKCE, bounded expiry and redirect validation rather than trusting callback query data directly.
- Added verified-email account linking rules, duplicate external-identity prevention and safe connected-account persistence.
- Added unlink safety so removing an external provider cannot strand an account without another valid authentication path.
- Added encrypted secret-store references for provider client secrets and disabled-by-default provider configuration.
- Added versioned connected-account persistence/migration support plus provider, transaction, linking, duplicate-prevention and unlink tests.

### 04.04 — MFA, Passkeys and Device Security

- Added encrypted TOTP enrollment/verification with persisted accepted-counter replay protection.
- Added CSPRNG recovery codes with digest-only persistence, single-use consumption and regeneration invalidation.
- Added strictest-wins group MFA policy enforcement plus secure pending enrollment for users newly placed under mandatory MFA.
- Added credential-version-bound trusted-device tokens with hash-only validators and policy-controlled login bypass.
- Added login MFA gating so successful primary authentication cannot create a full authenticated session before a required second factor succeeds.
- Added fresh sensitive-action challenges satisfied by TOTP, recovery code or passkey verification without treating ordinary sessions/trusted-device tokens as fresh proof.
- Added WebAuthn/passkey registration and assertion through the reviewed `web-auth/webauthn-lib` runtime dependency with required user verification, one-time server challenges and credential-state persistence.
- Added versioned migration `20260915120000_mfa_device_security` for TOTP, recovery codes, MFA/WebAuthn challenges, passkeys, trusted devices and group-policy membership data.
- Added MFA login/passkey/TOTP/recovery test coverage and machine-readable MFA security policy documentation.
- Added cPanel-safe browser installation entrypoints that encrypt DB secrets, run all current core migrations through the 03.03 engine and lock completed installation state.
- Added GitHub CI installation-package generation so production Composer dependencies are bundled into a downloadable ZIP and Composer is not required on cPanel runtime.

### 04.03 — Login, Session, Remember-Me and Recovery

- Added Argon2id-preferred password hashing with bcrypt fallback, bounded password policy, dummy verification for unknown identities and rehash without credential-version churn.
- Added transactional password credential persistence and integrated mandatory credential provisioning into password-based registration.
- Added credential-version invalidation so real password changes/resets invalidate existing authenticated sessions and persistent login credentials.
- Added 256-bit CSPRNG authentication sessions with fixation-safe rotation and hash-only database session identifiers; migration removes the legacy raw `session_id` column without rewriting historical migrations.
- Added privacy-preserving device tracking and login history using HMAC fingerprints rather than raw IP addresses, submitted identifiers or User-Agent strings.
- Added independent identity and network login throttle buckets: repeated attacks against one account and password spraying from one source are limited separately.
- Added replay-aware rotating remember-me selector/validator token families with hash-only persistence and family revocation on stale-token reuse.
- Added one-time hash-only password-reset and password-confirmation challenge tokens, with reset-driven credential-version increment and remember-token revocation.
- Added remember-token session restoration, bounded authentication maintenance cleanup and generic enumeration-resistant public authentication failures.
- Added versioned migration `20260914253000_authentication_runtime` for credentials, devices, login history, auth throttles, remember tokens and auth challenges.
- Added authentication/session, login, remember/recovery, migration, maintenance and independent throttle tests.

### 04.02 — Registration, Email Verification and Anti-Abuse

- Added safe-default registration modes: open, approval, invite-only and closed; fresh installations remain closed until policy/legal/CAPTCHA configuration is ready.
- Added provider-neutral CAPTCHA verification plus first-party Cloudflare Turnstile server-side Siteverify validation with secret-store loading, remote IP, UUID idempotency key and optional hostname/action enforcement.
- Added privacy-preserving registration abuse fingerprints using HMAC-SHA256 instead of raw IP/email rate-limit keys, plus atomic database rate-limit buckets.
- Added disposable-email policy abstraction and built-in managed domain-set checker.
- Added CSPRNG invite issuance with hash-only persistence, expiry/disable/max-use controls and row-locked atomic consumption.
- Added 256-bit email-verification tokens with SHA-256-only persistence, expiry, one-time consumption and controlled pending-email → active/pending-approval state transitions.
- Added versioned terms/privacy acceptance snapshots recording document type/version/content digest and a privacy-preserving client fingerprint.
- Added versioned migration `20260914243000_registration_security` for invites, email-verification tokens, legal acceptances and registration rate-limit buckets.
- Added bounded maintenance cleanup for old verification tokens, old rate-limit buckets and expired/disabled invites while preserving legal acceptance audit records.
- Added orchestration, persistence, Turnstile, invite, rate-limit, email-verification and maintenance tests.
- Kept password hashing/login credentials out of registration storage; authentication credentials begin in roadmap step 04.03.

### 04.01 — User Domain and Account Lifecycle

- Added opaque CSPRNG 128-bit user identifiers and canonical `User` aggregate state.
- Added username normalization with ASCII-safe baseline and fail-closed Unicode NFKC/case-fold requirements through optional Intl/Mbstring capabilities.
- Added canonical case-insensitive email identity with strict local-part policy, lowercase storage and optional IDN-to-Punycode conversion.
- Added canonical locale and named-IANA timezone value objects.
- Added pending-email, pending-approval, active, suspended, banned, deactivated and deletion-pending account states with explicit legal transition policy.
- Added typed per-user custom-field value storage for string/integer/boolean/JSON data while keeping visibility/edit authorization outside the aggregate.
- Added mutation history/domain events that record changed fields, actor, state transition and reason code without duplicating old/new email, username or custom-field values.
- Added transactional database repository with no-op unchanged saves and optimistic aggregate versioning to prevent lost updates.
- Added versioned migration `20260914233000_user_domain` for users, custom-field values and user history plus unique canonical username/email constraints.
- Added domain/repository/migration tests covering normalization, state transitions, custom fields, history, persistence and concurrency behavior.

### 03.07 — Search Driver Foundation and Capability Resolver

- Added native MySQL/MariaDB FULLTEXT search with parameterized query text, type/locale filters and access-scope candidate filtering.
- Restricted search-driver results to document type/id plus score so indexed titles, bodies and snippets cannot bypass final domain authorization.
- Added provider-neutral external search client/driver contracts while keeping external search optional for the cPanel baseline.
- Added reusable PHP/runtime/server capability matrix covering required and optional extensions, functions, SAPI, INI limits, PDO drivers and database server capabilities.
- Added conservative MySQL/MariaDB server detection and InnoDB FULLTEXT capability reporting without executing dangerous functions during probing.
- Added versioned migration `20260914223000_search_index` for search documents, access scopes and native FULLTEXT index verification.
- Added native search as the default deployment driver with fail-closed capability requirements for actual installation selection.
- Added tests for query normalization/parameterization, access-scope indexing, external adapter result limits, migration verification and minimum/optional capability resolution.
- Completed Main Step 03 and advanced the roadmap to 04.01 User domain and account lifecycle.

### 03.06 — Queue / Scheduler / Realtime Drivers

- Added binary-safe DB and Redis queue drivers with delayed availability, reservation visibility timeouts and bounded max-attempt metadata.
- Added random reservation ownership tokens so stale workers cannot acknowledge, retry or fail a newer reservation.
- Added database row-lock reservation with `FOR UPDATE SKIP LOCKED`, bounded expired-job cleanup and persistent dead-letter storage.
- Added atomic Redis Lua enqueue/reserve/retry/ack/fail transitions with Redis Cluster hash-tagged per-queue keys.
- Added five-field UTC cron parsing with lists, ranges, steps and standard day-of-month/day-of-week OR semantics.
- Added scheduler registry/dispatcher plus DB and Redis per-task/per-minute claim stores to prevent duplicate cron enqueue operations.
- Added polling, SSE and WebSocket realtime transports over one persisted sequence/cursor model; WebSocket persists before broadcast and SSE payloads are binary-safe base64.
- Added versioned core migration `20260914213000_queue_scheduler_realtime` for jobs, failed jobs, scheduler claims and realtime message history.
- Added cPanel-safe defaults: database queue/claims and polling realtime, while retaining optional Redis/WebSocket advanced deployment paths.
- Added tests for DB reservation locking/token ownership, Redis atomic queue scripts/cluster slots, cron due logic, scheduler dedup/retry, realtime cursors/SSE/WebSocket and migration verification.

### 03.05 — Storage and Media Drivers

- Added strict internal storage paths that reject absolute paths, traversal, backslashes, control characters and unsafe segments.
- Added explicit public/private visibility scopes and local storage with physically separate roots.
- Added binary-safe string and stream read/write APIs so large media can be transferred without mandatory full buffering.
- Added atomic local writes, bounded chunk copying, SHA-256/size metadata, restrictive file modes and symlink traversal rejection.
- Added public URL generation only for public local objects; private local objects never receive direct URLs.
- Added SDK-neutral S3-compatible client/driver contracts with streaming upload/read, object metadata, delete, public URL and bounded temporary private URL signing.
- Added S3 visibility verification and secret-store configuration references for object-storage credentials.
- Added tests for traversal rejection, public/private namespace separation, binary preservation, multi-megabyte stream behavior and S3 visibility/signing semantics.

### 03.04 — Cache / Session / Lock Drivers

- Added shared cache/session/lock contracts with safe key validation and clock abstraction.
- Added file-backed cache/session drivers with TTL, atomic writes, restrictive permissions, symlink rejection and bounded session garbage collection.
- Added database-backed cache/session drivers plus tag invalidation and bounded expiry cleanup.
- Added optional Redis cache/session drivers and a concrete `ext-redis` adapter without making Redis mandatory for the minimum cPanel profile.
- Added local file locks plus database and Redis distributed lease locks with random ownership tokens and token-safe release.
- Added cache stampede protection through double-checked regeneration locking.
- Added versioned core migration `20260914203000_infrastructure_drivers` for cache, cache-tag, session and lock tables.
- Added driver behavior tests covering TTL/tag invalidation, sessions, file/Redis/DB locking, stampede prevention and infrastructure migration verification.
- Added cPanel-safe default driver configuration and documented consistency/security boundaries.

### 03.03 — Migration / Install / Upgrade Engine

- Added core/module/add-on migration ownership and sortable versioned migration IDs.
- Added mandatory idempotency, explicit transactional declaration and post-run verification contracts.
- Added source fingerprinting so modified previously-recorded migrations fail integrity checks instead of silently mutating history.
- Added persistent MySQL migration history with running/applied/failed states, batches, attempts, timestamps and safe failure codes.
- Added recovery hook for partially applied non-transactional migrations and fail-closed migration execution reporting.
- Added installed-version store and install/upgrade coordinator; installed version advances only after every migration succeeds and verifies.
- Added exact source-version enforcement for upgrades and atomic protected file persistence for installed-version state.
- Documented MySQL DDL transaction limitations and external update-lock requirement rather than promising unsafe rollback semantics.

### 03.02 — Domain Entity / Repository / Service Layer

- Added canonical opaque `EntityId` and identity-bearing `Entity` contract.
- Added explicit DTO, application-service and domain-service architectural boundaries.
- Added generic repository contract plus abstract repository with typed not-found behavior and wrong-entity fail-closed protection.
- Added structured validator/violation/result/exception model plus composite validation.
- Added immutable domain-event contract, aggregate event recording/release and synchronous in-process dispatcher.
- Documented authorization, transaction, validation and durable-event-delivery boundaries so domain primitives do not become hidden security/infrastructure shortcuts.
- Added unit tests for identity values, repository persistence/type safety, structured validation and domain-event release/dispatch behavior.

### 03.01 — Database Connection and Typed Query Builder

- Added PDO MySQL connection configuration/factory with native prepared statements, utf8mb4 defaults and non-persistent connections.
- Added generic database connection executor with typed parameter binding and generic non-leaking exception wrapping.
- Added nested transaction/savepoint handling and transaction-required query enforcement for row locks.
- Added validated identifier handling plus typed SELECT/INSERT/UPDATE/DELETE builders with no public raw-WHERE escape hatch.
- Added fail-closed protection against accidental full-table UPDATE/DELETE operations.
- Added bounded pagination with separate count queries and overflow checks.
- Added `FOR UPDATE` / shared row-lock query modes that require an active transaction.
- Added optimistic compare-and-swap updates with mandatory version increment and exactly-one-row enforcement.
- Added builder/service tests plus optional in-memory PDO transaction/prepared-statement tests when `pdo_sqlite` is available.
- Added explicit PHP runtime requirements for OpenSSL, PDO and PDO MySQL.

### 02.07 — HTTP Security and Runtime Health

- Added context/scope/lifetime-bound HMAC CSRF tokens and browser-route CSRF middleware with secure `__Host-` context cookies.
- Added CSRF failure rotation with a fresh replacement token and protection against unsafe-method bypass.
- Added trusted-host enforcement before application dispatch.
- Added conservative CORS policy/service plus middleware; credentials require exact origins and wildcard+credentials is rejected.
- Added browser/API security-header profiles including CSP, nosniff, referrer, frame-ancestor and permissions policies.
- Added host-bound signed maintenance bypass tokens and maintenance middleware with public-path exclusions.
- Added runtime `ErrorHandler` with masked correlation IDs, generic production 500 responses and no-stack-trace production output.
- Added storage/error loggers that pass context through `SecretMasker` before writing.
- Added runtime health contracts and service for liveness/readiness/version/capability reporting with redacted machine-readable data.
- Added tests for CSRF replay/scope/expiry behavior, CORS, trusted hosts, security headers, maintenance bypass, masked error handling and health output.

### 02.06 — Router, Canonical URL and Trusted Proxy Handling

- Added route registration, named-route URL generation, typed placeholders, path-variable decoding and request dispatch.
- Added route-level middleware ordering and global fallback handling for 404/405 responses.
- Added central canonical URL generator using configured origin/base path rather than untrusted request Host headers.
- Added IPv4/IPv6 CIDR matcher and trusted-proxy resolver for client IP/scheme/host/port derivation.
- Added Cloudflare `CF-Connecting-IP` support only when the direct peer is in the separately configured Cloudflare proxy set.
- Added fail-closed forwarded-chain parsing: forwarded headers are ignored for untrusted peers and malformed forwarded chains do not become client truth.
- Added `RequestContextMiddleware` to attach resolved network context to request attributes.
- Added tests for static/variable routes, constraints, URL encoding, 404/405, route middleware order, canonical URLs, trusted proxies and Cloudflare handling.

### 02.05 — Request, Response and Middleware

- Added typed HTTP method/request/response primitives and immutable-style request attributes/response mutations.
- Added case-insensitive validated header handling with CRLF/NUL injection rejection.
- Added bounded, throwing JSON request parsing and JSON response encoding.
- Added secure response-cookie serialization with SameSite and `__Secure-` / `__Host-` invariants.
- Added PHP `$_FILES` tree normalization and verified HTTP-upload movement primitive.
- Added reusable deterministic middleware/request-handler pipeline.
- Added request-ID correlation with strict incoming validation and CSPRNG fallback generation.
- Added unit tests for headers, JSON, cookies, nested uploads, middleware order/reuse and request-ID propagation.

### 02.04 — Configuration, Environments and Secrets

- Added explicit production/development/test/install/maintenance environment model.
- Added deterministic defaults → generated config → prefixed environment override loading.
- Added update-protected generated config and cPanel-compatible master-key file strategy with environment-key preference.
- Added AES-256-GCM authenticated secret encryption with CSPRNG IVs, versioned payloads and tamper failure.
- Added locked/atomic encrypted file secret store with restrictive permissions and symlink rejection.
- Added centralized structured secret masking for logs/debug contexts.
- Added tests for configuration precedence/type decoding, encrypted secret persistence, tamper detection, key persistence and masking.

### 02.03 — Application Kernel and DI Container

- Added the core DI container with explicit bindings, transient/singleton lifetimes and lazy factories.
- Added constructor autowiring with fail-closed handling for ambiguous/unresolvable dependencies.
- Added circular-dependency detection that reports the active resolution path.
- Added isolated test overrides while rejecting silent production service replacement.
- Added application-kernel lifecycle/state management and deterministic two-phase provider registration/boot.
- Added unit tests for container lifecycle, lazy resolution, autowiring, circular dependencies, overrides and kernel provider ordering/idempotence.

### 02.02 — Code Standards and Static Analysis

- Set PHP 8.4 minimum and PHP 8.4/8.5 compatibility targets in executable quality policy/configuration.
- Added PSR-12 PHP_CodeSniffer configuration and repository-wide `strict_types=1` enforcement.
- Added PHPStan `max` and Psalm error-level `1` configurations.
- Added PHPUnit 12 test configuration plus architecture tests for quality-policy invariants.
- Added ESLint 10 flat config, strict TypeScript base options and Prettier formatting configuration for modern frontend/package development.
- Pinned TypeScript to the supported 5.9 line rather than using an unsupported newer compiler with the selected typescript-eslint line.
- Inventoried all new development-only dependencies with license/security metadata; none are production runtime dependencies.

### 02.01 — Monorepo / Repository Layout

- Created every required top-level project boundary: `app`, `core`, `modules`, `addons`, `themes`, `resources`, `database`, `storage`, `public`, `frontend`, `packages`, `tests`, `docs` and `tools`.
- Defined ownership and dependency-direction rules for core, first-party modules and third-party add-ons.
- Established `public/` as the preferred HTTP document root and `storage/` as mutable update-protected runtime state.
- Added repository/runtime ignore rules so local secrets, dependency working trees and mutable storage are not accidentally committed.
- Added a machine-readable repository-layout contract for future validation/release tooling.

### 01.06 — Version, Delivery and Continuation Protocol

- Adopted SemVer 2.0.0 for releasable Forwext versions and added root `VERSION` (`0.0.0-dev` development marker).
- Defined changelog/version synchronization and prerelease naming rules.
- Formalized the new-session continuation sequence and required progress fields.
- Reaffirmed mandatory full + update ZIP artifacts for every releasable version once packaging tooling exists.
- Defined exact source→target update validation, migration requirements, protected site data and post-update rebuild/health gates.
- Corrected remaining legacy `03.02` persistent-install references to binding v2 step `03.03`.
- Added a machine-readable release/delivery policy.
- Completed Main Step 01 and advanced development to 02.01.

### 01.05 — License and Third-Party Dependency Policy

- Selected **Apache License 2.0** as the Forwext project license and added the authoritative root `LICENSE`.
- Added root `NOTICE` and `THIRD_PARTY_NOTICES.md` files.
- Defined license compatibility review categories and explicit treatment of copyleft, source-available, proprietary and unclear-provenance components.
- Defined mandatory dependency inventory, security/advisory, maintenance/freshness, version-pinning and release-compliance rules.
- Added a machine-readable dependency inventory; no third-party runtime dependency is currently approved/vendored.

### 01.04 — Clean-room and Intellectual Property Boundaries

- Added the normative Forwext clean-room/IP policy.
- Restricted XenForo, MyBB and other forum products to behavioral-reference use by default.
- Explicitly prohibited copying/adapting proprietary code, templates, phrases, schemas/migrations, assets, leaked packages and AI-generated rewrites based on prohibited material.
- Defined an enhanced clean-room process for high-risk parity work.
- Added contributor provenance and AI-assistance rules.
- Added a machine-readable clean-room policy manifest and a pull-request provenance checklist.
- Added `CONTRIBUTING.md` so clean-room, architecture, security and completion rules are visible before code contribution begins.

### 01.03 — Technical Glossary and Domain Names

- Expanded the normative glossary with canonical User, Group, Role, Permission, Node, Forum, Thread, Post, Ticket, Report, Moderation Case, Marketplace Listing, Giveaway, Module, Add-on, Widget and UI Slot definitions.
- Fixed important non-synonym boundaries such as Role vs Group, Module vs Add-on, Ticket vs Thread and Report vs Moderation Case.
- Added machine-readable canonical PHP/API names and domain invariants for future code/API consistency.

### 01.02 — Usability Constitution

- Added the normative simple-by-default / deep-on-demand usability constitution.
- Defined mandatory Basic/Advanced progressive disclosure behavior.
- Defined safe-default priority, explanation/help, preview, reset/undo/revision/rollback and dangerous-action rules.
- Defined permission-aware UX requirements and the rule that UI hiding never substitutes for backend authorization.
- Added mobile, accessibility, reduced-motion, form validation and cPanel performance expectations.
- Added a machine-readable usability policy manifest for later ACP/UI implementation.
- Synced the repository to the binding v2.0 master plan (20 main steps / 138 real sub-steps).
- Corrected the first persistent installation milestone to 03.03.
- Expanded the 1.0 product/scope contracts to the full v2 first-party system set.