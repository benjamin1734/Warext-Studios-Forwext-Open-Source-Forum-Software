# Changelog

All notable Forwext development changes are recorded here.

Forwext follows the binding project roadmap during pre-release development. Semantic version release entries become authoritative once releasable packaging begins.

## Unreleased

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
- Added deny-by-default CORS policy/preflight middleware with credential/wildcard safeguards.
- Added trusted-host exact/wildcard policy evaluated against the trusted effective request host.
- Added centralized CSP, nosniff, referrer, permissions, frame and HTTPS-only HSTS response-header baseline.
- Added rate-limit policy/store contract, deterministic in-memory driver and locked cPanel-compatible file driver.
- Added JSON-lines structured logging with secret masking plus query-string-free correlated access logging.
- Added production-safe/debug-masked error handling with request-ID correlation and logging-failure fallback.
- Added healthy/degraded/unhealthy health aggregation, runtime/writable-directory checks and minimal-by-default health handler.
- Added edge-case tests for CSRF, CORS, trusted hosts, security headers, rate limits, logging/errors and health behavior.
- Completed Main Step 02 and advanced development to 03.01.

### 02.06 — Router, Canonical URL and Proxy

- Added named route registration, friendly single-segment parameters, method-aware dispatch and static-route precedence.
- Added fail-closed ambiguous-route detection plus 404/405/Allow behavior.
- Added named path/absolute URL generation with RFC 3986 query encoding.
- Added a shared base-path contract for root and subfolder installations.
- Added configured canonical-origin parsing and fixed-target HTTPS/canonical redirects.
- Added explicit IPv4/IPv6 CIDR trusted-proxy handling with right-to-left `X-Forwarded-For` resolution.
- Added RFC `Forwarded` origin/client support for trusted peers in addition to common `X-Forwarded-*` headers.
- Added Cloudflare-aware client IP/scheme support gated by configured Cloudflare source CIDRs.
- Added redirect-loop protection that refuses untrusted forwarded-origin claims instead of trusting them.
- Added tests for routing, URL generation, encoded-path safety, CIDR resolution, trusted/untrusted proxies, Cloudflare and TLS-termination canonical behavior.

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
