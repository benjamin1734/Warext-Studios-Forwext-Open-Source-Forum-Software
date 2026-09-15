# Changelog

All notable Forwext development changes are recorded here.

Forwext follows the binding project roadmap during pre-release development. Semantic version release entries become authoritative once releasable packaging begins.

## Unreleased

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
