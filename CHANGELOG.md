# Changelog

All notable Forwext development changes are recorded here.

Forwext follows the binding project roadmap during pre-release development. Semantic version release entries become authoritative once releasable packaging begins.

## Unreleased

### 17.05 — System Operations ACP

- Added native PHP `/admin/system/operations` with granular backend authorization for health/capabilities, logs, jobs/cron, backups, maintenance and repair.
- Added runtime/database health, migration/database integrity diagnostics and permission-aware queue/failed-job operations without exposing job payloads.
- Added bounded/redacted structured-log reading and typed first-party maintenance task execution through the normal queue.
- Added protected logical database backups with schema, byte-safe row records, manifest verification and SHA-256, with no ACP download/restore endpoint.
- Added atomic maintenance-mode control, bounded scheduler-claim pruning and symlink-safe cache cleanup with typed confirmations, CSRF and Administration audit.
- Added additive/idempotent MySQL/MariaDB permission policy plus regression/security/UX coverage.
- Implementation/fix commits: `d59772788a053f505eaf6e8e0d9c308b62b2b0f6`, `6d810509963e802f567039d1974f55b53453084d`, `34f6dfefc4508cd6a88669bea711f2ba8a02012f`, `5a752591543030db40562d7e09aabb6a56b49f7f`, `b37ed765d7ffb1b424e1b83beda2dde491cad76f`, `bb1fb09fd0a3fb98fb5b34850eeded331612eb7a`, `bc6a87728dcb8b3c4c0c4dfd92462cfa1f3b986c`, `a0a0f7e8add5b3d6ab2e44734fedbe8a5f89efae`, `b06a0af490a3fd5d91ce3d4de0350cb375057c3d`, `757625f36d98115194090b14cb73c5b22e2883e1`, `af752d8c2c582f5d888af3e07af255b5db8dfb49`.
- GitHub Actions build run `36113226857` and DB smoke run `36113226864` both succeeded.

### 17.04 — System / Integration ACP

- Added typed configuration and dedicated backend authorization for mail, OAuth, Turnstile, AI, storage, cache, queue, search, realtime and API/webhook readiness.
- Added native PHP `/admin/integrations` with server-side search/filter, safe generated-config reset, environment-override visibility and runtime capabilities.
- Added locked/atomic generated configuration plus encrypted secret management with secret values excluded from snapshots and Administration audit payloads.
- Added typed URL/path/list/range validation, exact-key secret deletion confirmation and read-only step-19 API/webhook switches.
- Added dedicated ACP navigation plus migration/security/UX regression coverage.
- Implementation/fix commits: `bd50bd9bc92977b5122fb217ff2f3ffafe7438d7`, `cbe1c7197d71f793eb7e684be2186591388542a9`, `b33ada8e0ed75d06056e2df725b887ce3d461fed`, `c3e608c22938282fdb7ffd66bde0ead9b99c511b`, `cd2c09ae13d08239599868bb88d75e3387178eaf`, `f252c2e66c16950a1991f48e229c9637a84ba326`, `70ea4a30b48f5d02f4f1bfc3331b8f3665697fd7`, `ffadd32c0768f942659a041b3767e7b304642618`, `5b3097f6f9a736eb919a9490e7388493ace1747c`, `c2e9bd45acd61d87a6782f5470e07690ac7f06fd`.
- GitHub Actions build run `36108451982` and DB smoke run `36108451988` both succeeded.

### 17.03 — First-party Module Manager

- Added typed enabled/disabled/uninstalled lifecycle state, dependency/conflict graph validation and dedicated `module.manage` authorization.
- Added native PHP `/admin/modules` with CSRF lifecycle controls, exact-key uninstall confirmation, scoped settings and dependency visibility.
- Added global/forum/group/thread/post typed setting overrides with reset-to-fallback and validated targets.
- Added keep/delete-data uninstall policy, dependent-data purge protection, transactional database purge/audit state and retryable storage cleanup.
- Added runtime route gating plus module-conditional Easter Egg, Advertising and Analytics ambient middleware.
- Added additive/idempotent MySQL/MariaDB module lifecycle/settings/purge migration and regression coverage.
- Implementation commits: `a25b013688068f46da8b0c714205a08f59ab2987`, `3f7552ce1ea09a0b90f0bdd57f46ca6118f77a8d`, `d2a59de0cd36a262e80060a9e5c39c40117cc9c1`, `d1a34f92d9a863fa361da68561a910af26fd64e3`, `67ed20083e97688a7fd72185bc16a9fe68232c46`, `230ec7e4aa9e2426ce85cd97c4ecf341a6d8e88e`, `fc75d5e2bd90151e3719186085905a813191467e`, `d4e2442d915e69f9b7532d2b0c9a237a3f3b6f35`.
- GitHub Actions build run `36104953356` and DB smoke run `36104953305` both succeeded.

### 17.02 — Users / Roles / Forums / Moderation ACP

- Added native PHP ACP surfaces for users, groups/roles/banner/permission analysis, forums, content and moderation.
- Added audited user access assignment, group/role management, role appearance and forum-node mutations under backend `acp.manage`.
- Kept warning/restriction/suspension/ban/revoke in the existing DisciplineService and content/moderation mutations in their existing first-party services.
- Added production PermissionEngine-backed analyzer, self-lockout protection, real operational counters and safe forum hierarchy management.
- No new schema migration was required.
- Implementation commits: `b360787b975f4aeed69932b41ec486648fc6c71f`, `5373b874f07cde7d0b59bdde80651593a82d0100`, `f1af2bf54c5fbdd7e89470ac8d90bbb1dad33f64`.
- GitHub Actions build run `36039908005` and DB smoke run `36039908059` both succeeded.

### 17.01 — ACP Information Architecture

- Added backend-authorized native PHP Administration dashboard at `/admin`.
- Reused `acp.access` and each subsystem's existing permission instead of creating an administrator bypass.
- Added permission-aware global search, real action-needed queue counts, per-user favorites/recent navigation and reusable breadcrumbs.
- Added POST + CSRF navigation/favorite mutations and validated static same-origin management targets.
- Added additive/idempotent MySQL/MariaDB ACP navigation preference migration.
- Implementation commits: `ec6d344a4f062a8ecf01aa2e8cc406706bb1c3a7`, `c3c89250926a4370ba3a22b5168f597e9e6d92ee`, `ae0303aebe2ffac9ff573993125571d9714baf3f`, `b686bc1d7bde7aced1dbd16ee98d5358892da5aa`, `023eceda2b718a53007c81ae0b98476bc001240d`, `6388a2d63a56a842bbc8aecd13373e20bfa59b9c`.
- GitHub Actions build run `35913774719` and database migration smoke run `35913774535` both succeeded.

### 16.08 — Appearance Studio Guided Configuration

- Added backend-authorized native PHP Appearance Studio at `/admin/appearance`.
- Added Basic/Advanced progressive disclosure, safe typed presets, bounded static-metadata search and guided-state reset.
- Added isolated desktop/tablet/mobile preset preview with reduced-motion support and no JavaScript dependency.
- Added task-language setup assistant links into the real Theme and Layout Builder revision workflows.
- Kept advanced entries permission-aware and non-actionable without `appearance.advanced`; live publish/rollback remains in existing audited services.
- No migration is required because the guide is request-scoped and carries no durable appearance configuration.
- Implementation commits: `2e87c786cb03ac2e225d77cd4198e96bbd9e7d33`, `7680e172a9f4fd29db83acf6b436d8dcb98c82e8`, `ec9b8feb80df681299a5130a39879f50b1b66425`.
- GitHub Actions build run `35911852262` and DB smoke run `35911851992` both succeeded.

### 16.07 — Theme / Template / Language / Revision System

- Added base/child themes, published-parent inheritance and immutable staging/published revision pointers.
- Added bounded template/phrase/custom CSS/custom JavaScript payloads with Turkish phrase fallback.
- Added escaped compile-to-PHP templates with no eval, protected revision cache files and SHA-256 manifest verification.
- Added revision diff/rollback plus `appearance.manage` / `appearance.advanced` backend authorization and Administration audit events.
- Added `/admin/appearance/themes` with CSRF/noindex/no-store management, parent selection, staging history, diff, rollback and publish controls.
- Added revision-addressed same-origin published CSS/JavaScript assets and MySQL/MariaDB migration coverage.
- Implementation commits: `5e48de8ff6074f338941e5371a5284d15aa7325f`, `4a0f5601b257c9be15883d1e8a05d1be36cb3cc8`.
- GitHub Actions build run `35907617317` and DB smoke run `35907617153` both succeeded.

### 16.06 — Drag-drop Page / Layout Builder

- Added versioned layout documents, immutable draft revisions and separate draft/published pointers.
- Added registered widget/slot validation, route/audience/device conditions and optimistic-concurrency safe publishing.
- Added permission-aware audited save/import/publish operations and versioned 1 MiB-bounded JSON import/export.
- Added MySQL/MariaDB layout/revision migration with SHA-256 integrity verification and concurrent-creation fail-closed behavior.
- Added the native admin drag/drop builder with reorder, duplicate/remove, enabled toggle, bounded undo/redo and desktop/tablet/mobile preview.
- Added CSRF/noindex/no-store handling and Web Crypto placement ids.
- Implementation commits: `351dae525535ab17e8cbc37369cdec1206e9ad72`, `d162277187fff3a930678c219923e07eb6b26e9e`, `f8ede650e579531b0129d05c47ba586f49af1845`.
- GitHub Actions build run `35905773495` and DB smoke run `35905773443` both succeeded.

### 16.05 — Layout Regions / UI Slots / Widgets

- Added typed header/main/sidebar/footer/page regions and deterministic named UI slots.
- Added core/module/add-on contributor and ownership boundaries for slots/widgets.
- Added native PHP widget rendering, conditional sidebar output and a core footer widget proving the end-to-end registry path.
- Added optional cache integration with bounded TTL and targeted invalidation tags.
- Added authenticated viewer cache isolation: cached authenticated widgets require an opaque viewer id in the hashed context; otherwise caching is bypassed.
- Kept widget/layout visibility strictly separate from backend authorization.
- Added registry/render/cache/native-shell regression coverage.
- Feature commit: `28847efe8c134efebd8eaec9fc6ff1b69dab1a88`; cache-isolation correction: `98f814bf7464a27f90c93c8f148aba83999a97de`.
- GitHub Actions build run `35903527419` and DB smoke run `35903527408` both succeeded.

### 16.04 — Responsive Mobile / Desktop Control

- Added validated non-overlapping mobile/tablet/desktop breakpoint definitions.
- Added typed visibility/font/spacing/layout overrides on fixed first-party responsive targets.
- Added deterministic responsive CSS compilation without raw selector or media-query injection.
- Added progressively enhanced mobile navigation with accessible state, Escape close and focus restoration.
- Added skip-link, visible focus treatment, reduced-motion behavior and RTL preparation.
- Shared the responsive manifest with the optional TypeScript/React frontend and kept the native PHP frontend first-class.
- Added registry/compiler/native-shell/mobile-nav regression coverage.
- Implementation commit: `4a258a546c8ad8b00de1d0cd3eb6ccbbe6bb6869`.
- GitHub Actions build run `35901991674` and DB smoke run `35901991880` both succeeded.

### 16.03 — Background / Pattern / Gradient / Asset System

- Added typed solid, gradient, safe raster image and built-in pattern backgrounds.
- Added bounded opacity, scale, rotation and blend controls plus animated gradients with reduced-motion fallback.
- Added site/header/category/profile scopes with opaque entity-id validation for category/profile targeting.
- Added safe same-origin raster asset references under `assets/appearance/`, rejecting traversal, external origins, SVG and query/fragment injection.
- Added base-path-aware isolated pseudo-layer compilation and native site/header/profile scope integration.
- Exposed the same background manifest to the optional TypeScript/React package.
- Added domain, registry, compiler, asset-policy and web-surface regression coverage.
- Implementation head: `5850fc03c1e412a0ad119dde96be6aacb2ee72e6`.
- GitHub Actions build run `35901010331` and DB smoke run `35901010425` both succeeded.

### 16.02 — Component Appearance Settings

- Added typed appearance targets covering header/navigation/footer/forum/thread/post/profile/buttons/inputs/modals/badges/role banners/editor/tables/alerts.
- Added a versioned component manifest that binds fixed appearance properties only to validated design tokens.
- Added required-target, duplicate, property and token-reference validation plus deterministic `--forwext-component-*` compilation.
- Migrated existing native PHP header/navigation/cards/profile/buttons/inputs/badges/alerts to component variables without changing their default visual intent.
- Connected role-banner CSS and the optional TypeScript/React package to the same shared appearance contract.
- Added component-specific semantic tokens for existing contrast, badge, alert and role-banner visuals.
- Added registry/compiler/native-surface regression coverage.
- Implementation commit: `4f9d3bd2918a9b66b1c4cabab5b9f27979950d38`.
- GitHub Actions build run `35898405999` and DB smoke run `35898406003` both succeeded.

### 16.01 — Design Token Engine

- Added a versioned canonical design-token manifest for color, typography, spacing, radius, border, shadow, motion and semantic tokens.
- Added strict PHP parsing, category/value validation, duplicate/reference integrity and cycle detection.
- Added safe primitive CSS policies and semantic reference-only tokens.
- Added deterministic `--forwext-*` CSS compilation with reduced-motion overrides.
- Integrated native PHP styling through semantic compatibility aliases without changing the cPanel runtime profile.
- Added a TypeScript/React boundary that consumes the same canonical JSON manifest.
- Added regression tests for security, category completeness, compiler output and cross-frontend sharing.
- Implementation commit: `7b970dbcf8356bfdebac5f3d24914f9da6b1127a`.
- GitHub Actions build run `35897236806` and DB smoke run `35897236805` both succeeded.

### 15.06 — Report Builder / Export / Analytics Access

- Added a native PHP analytics report builder with bounded UTC date ranges, fixed allowlisted filters and owner-scoped saved reports.
- Added scoped forum/content/operations/commerce analytics permissions while retaining `analytics.view_site` as a compatibility super-permission.
- Added backend-enforced report-use, export, manage-all and unaggregated permissions.
- Added privacy aggregation with a minimum effective count of 5 unless explicitly authorized for lower thresholds.
- Added CSV/JSON export through the same permission/privacy execution path and neutralized spreadsheet-formula cells in CSV.
- Added centralized audit for saved-report mutations, dedicated CSRF, strict ID/filter validation and private/no-store/noindex/nosniff response policy.
- Added additive migration `20260923201500_analytics_report_builder` and conservative permission-template defaults.
- Feature commit: `d95f0f2d2430ad2252ecbae01ed5c06638e94141`; final regression correction: `e7bd41d16aff7af814ff969acf57b09de5c2b6e1`.
- GitHub Actions build run `35896110292` and DB smoke run `35896110441` both succeeded.

### 15.05 — Marketplace / Revenue / Referral / Giveaway Analytics

- Added privacy-aware structural `marketplace.listing.view` recording for successful Marketplace detail HTML requests.
- Added backend-authorized `/admin/analytics/commerce` with fixed 7/30/90-day UTC windows.
- Added listing/current inventory, listing-view, external-click, external CTR and order funnel metrics.
- Added currency-separated first-paid-transition GMV, succeeded refunds and net payment-flow rows.
- Added currency-separated advertising CTR and configured estimated-revenue rows.
- Added referral attribution cohort and per-campaign conversion metrics plus granted reward units.
- Added giveaway unique participant, weighted-entry and draw/redraw analytics.
- Sensitive payment/referral/giveaway payloads and anti-abuse fingerprints are not queried.
- Added migration `20260923195000_commerce_analytics_indexes`, tests and architecture documentation.
- Final implementation commit: `5bb212419cf021b8faf8915f7356761928ffcf19`; producer commit: `15445be9087611d674ea9d8527b264b2984c0564`.
- GitHub Actions build run `35892034814` and DB smoke run `35892035230` both succeeded.

### 15.04 — Moderation / Support / Bug Analytics

- Added backend-authorized `/admin/analytics/operations` with fixed 7/30/90-day UTC windows.
- Added moderation report volume, grouped case terminal counts/duration and warning/restriction/suspension/ban metrics.
- Added support ticket volume, first-response/resolution timing and cohort-based SLA breach metrics.
- Added bug lifecycle/finalization metrics and category breakdowns.
- Added staff workload aggregation from current report/support/bug assignments plus selected-window discipline and central audit action metadata.
- Kept the dashboard metadata-only: report/ticket/bug free text, discipline reason text and audit/history JSON payloads are not queried.
- Added additive migration `20260923190000_operations_analytics_indexes`, migration-registry coverage, dashboard wiring/privacy tests and architecture documentation.
- Reused `analytics.view_site`, private/no-store/noindex response policy and the native PHP/cPanel-first runtime.
- Final implementation/fix head: `2dfab4ed875a9a3b4f04432615490a9a379ac1f8`.
- GitHub Actions build run `35889912569` succeeded across strict-types, PHP lint, PHPUnit PHP 8.4/8.5, production dependency baseline and cPanel full-package build.
- Database migration smoke run `35889912544` succeeded on MySQL 8.4 and MariaDB 10.11 with post-install web bootstrap smoke.

### 15.03 — Content and Engagement Analytics

- Added forum/category/thread performance aggregation, reactions, bookmarks, watches, follows and privacy-aware search analytics.
- Added structural `content.thread.view` analytics references, recursive category aggregation and safe search-term daily aggregation.
- Added `/admin/analytics/content` with backend `analytics.view_site` authorization and private/no-store/noindex responses.
- Added migration `20260921232000_content_engagement_analytics`, structural/privacy regression coverage and architecture documentation.
- Follow performance aggregation and related PHPUnit compatibility fixes are present on current `main`.
- Final 15.03-labeled correction commit: `70ee749345c444bf996c9ad16256261a20b21679`.
- Cumulative GitHub Actions build run `35889912569` and DB smoke run `35889912544` both succeeded with all 15.03 changes present.

### 15.02 — Forum Analytics Dashboard

- Added a site-wide forum analytics dashboard with fixed 7/30/90-day windows.
- Added authoritative active-account, registration, visible thread/post and current-presence metrics from their owning domain tables.
- Added privacy-aware DAU/MAU, daily active-user, 7-day/30-day activity-retention and 24-hour/7-day five-minute peak metrics using only pseudonymous `actor_hash` analytics identities.
- Added equal-length previous-period growth comparisons for registrations, threads and posts, including zero-base handling.
- Added one daily trend row per UTC day, including zero-activity dates.
- Added `user.active` recording to the presence heartbeat path using the same installation-specific analytics HMAC privacy key as the main web runtime.
- Added backend-authorized GET-only `/admin/analytics` with `analytics.view_site`, strict `days=7|30|90`, private no-store responses and noindex/nofollow.
- Added migration `20260921231000_forum_analytics_dashboard` with dashboard query indexes and conservative permission-template defaults: administrator allow, all standard non-admin templates deny.
- Added model, source-of-truth/privacy, heartbeat, migration-registry and web-route regression coverage.
- Added architecture documentation at `docs/architecture/forum-analytics-dashboard.md` and milestone notes at `docs/changelog/15.02-forum-analytics-dashboard.md`.
- Final implementation/fix commit: `c79a77735c294c849d52176eed9b5afe648fae9c`; dashboard regression-test commit: `b79e7b3481b717911a21bfe6e81ac768146d57b9`.
- GitHub Actions build run `35885522027`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35885741563`: success on MySQL 8.4 and MariaDB 10.11 for the final documented head.
### 15.01 — Analytics Event Model

- Added a privacy-aware event registry spanning forum, user, content, support, bug, Marketplace, referral, giveaway and moderation domains.
- Added per-event retention policy plus actor/session/subject/forum collection declarations and exact dimension allowlists.
- Added deterministic HMAC-SHA256 pseudonymization for user, session and subject identities using a domain-separated installation key.
- Added strict token-only dimension validation with explicit raw-IP rejection and no arbitrary free-form analytics payloads.
- Added `forwext_analytics_events` persistence with event/category/forum/actor/subject/retention indexes and no raw IP, user-agent, e-mail, request URI/query string or raw user-id fields.
- Added strict/best-effort analytics recording, runtime `user.active` and `forum.view` producers, thread-to-forum resolution and reuse of the bounded browser/device classifier.
- Added daily bounded per-event retention pruning through the existing scheduler/maintenance queue model.
- Added migration `20260921230000_analytics_event_model` and installer registry coverage.
- Added privacy, registry-domain, runtime-wiring and retention regression tests.
- Added architecture documentation at `docs/architecture/analytics-event-model.md` and milestone notes at `docs/changelog/15.01-analytics-event-model.md`.
- Final implementation/fix commit: `8736f3478c306c9d221f7e50b1f13904d8374085`; final regression-test correction: `88444be88da8d6609c3f24c0261b0248b3c3b7ef`.
- GitHub Actions build run `35633962639`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35633962656`: success on MySQL 8.4 and MariaDB 10.11.
### 14.08 — Advertising / Notice / Placement System

- Added first-party advertisement, notice and announcement campaigns with stable semantic placement keys.
- Added combined route wildcard, forum, user-group, desktop/mobile and UTC schedule targeting.
- Added HMAC-derived authenticated/anonymous frequency identities, rolling impression caps and an HttpOnly first-party anonymous token.
- Added fail-open native PHP HTML placement decoration so an advertising subsystem failure does not turn healthy forum pages into 500 responses.
- Added tracked click redirects with site-relative/HTTPS destination validation, no embedded credentials and rapid-repeat click analytics deduplication.
- Added privacy-bounded impression/click persistence plus 30-day CTR and estimated value/revenue aggregation.
- Added native `/admin/advertising` management with real forum/group selection, route patterns, device/time targeting, cap/window controls and analytics.
- Added backend `ads.manage` / `notice.manage` authorization, dedicated CSRF and centralized administration audit mutation.
- Added migration `20260921223000_advertising_notice_system` for campaigns, route/forum/group/device targets and indexed event/frequency analytics.
- Added domain, runtime selection, click-dedupe, web-wiring and migration-registry regression coverage.
- Added architecture documentation at `docs/architecture/advertising-notice-placement.md` and milestone notes at `docs/changelog/14.08-advertising-notice-placement.md`.
- Final implementation/fix commit: `d70effd8240f0badb8adb1c0badd805354e4b0ed`; final regression-test commit: `e32f0f1b7b0b56e2487194dce75be13b4f463552`.
- GitHub Actions build run `35631552545`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35631552587`: success on MySQL 8.4 and MariaDB 10.11.
### 14.07 — Subscription / User Upgrades

- Added timed and lifetime first-party upgrade plans with durable user entitlement state and immutable purchase snapshots.
- Added runtime role and flag-permission overlays so expiry/revocation affects authorization without rewriting normal user assignments.
- Excluded protected/staff/system roles and sensitive administration permission classes from direct upgrade entitlement binding.
- Added provider-backed subscription purchase initiation with idempotency, amount/currency/duration snapshots and one-active-payment-attempt protection.
- Added verified/deduplicated server-to-server subscription payment webhooks with provider reference, amount and currency validation plus SHA-256-only payload persistence.
- Added paid activation and renewal semantics that extend timed upgrades from their existing future end date and retain lifetime upgrades without an end time.
- Added runtime expiry enforcement plus bounded read-repair/admin normalization, manual grant/renew and explicit revocation.
- Added native `/account/upgrades`, purchase action and `/admin/subscriptions` surfaces with dedicated CSRF for human mutations and a separate verified webhook route.
- Added migration `20260921220000_subscription_upgrade_system` for plans, role/permission bindings, user subscriptions, purchases, webhook dedupe and entitlement history.
- Added member navigation/post-install smoke coverage plus regression tests for domain invariants, entitlement overlays, sensitive permission boundaries and webhook/CSRF wiring.
- Added architecture documentation at `docs/architecture/subscription-user-upgrades.md` and milestone notes at `docs/changelog/14.07-subscription-user-upgrades.md`.
- Final implementation/fix commit: `b343eec151a98e1d37316db6d34fd6def5dd8193`; final regression-test correction: `aa08744311a29537df306af049c763a57807f0b8`.
- GitHub Actions build run `35627236876`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35627236887`: success on MySQL 8.4 and MariaDB 10.11.
### 14.06 — Digital Delivery and Order Management

- Added download, license, key and manual Marketplace delivery modes with immutable checkout-time delivery snapshots.
- Added private delivery assets using the shared upload/storage pipeline, MIME allowlists, SHA-256/size integrity validation and controlled buyer downloads.
- Added encrypted license/key inventory with duplicate-safe HMAC fingerprints, atomic reservation, paid activation and cancellation release.
- Added paid-order manual seller fulfillment plus buyer-only secret reveal/download flows and synchronized order delivery state.
- Added backend `marketplace.delivery.manage_own` / `marketplace.delivery.manage_all` permissions, IDOR-safe order/item access and central audit integration.
- Added native PHP delivery setup, order delivery actions, append-only order history and Marketplace-order support/dispute context.
- Added migration `20260919215000_marketplace_digital_delivery`, permission defaults and Support `marketplace_order` context wiring.
- Expanded post-install navigation smoke coverage to all ten core navigation routes in root and `/public` subfolder deployments, rejecting both 404 and 5xx results.
- Added regression tests for permission catalog completeness, support-context privacy, delivery route/CSRF wiring and security response policy.
- Added architecture documentation at `docs/architecture/marketplace-digital-delivery.md`.
- Final implementation commit: `f5f79aae32b63e56579185dbc5ad6a9c3e972ed0`; final test correction: `c3d67a6bd5558304a05a1d7e3fa19d8e463a22b4`.
- GitHub Actions build run `35591402493`: success.
- Database migration smoke run `35591402451`: success on MySQL 8.4 and MariaDB 10.11.

### 14.05 — Payment Provider Abstraction

- Added provider-agnostic payment contracts, typed attempt/refund lifecycles and an injectable duplicate-safe `PaymentProviderRegistry` without making a commercial payment SDK mandatory for cPanel deployments.
- Added durable payment attempts, verified webhook event deduplication and full-refund records with database idempotency boundaries.
- Added row-locked initiation/state transitions, one-active-attempt protection and immutable order amount/currency/buyer matching to prevent parallel/double-charge races.
- Added same-origin return/cancel path validation plus HTTPS-only external provider checkout handoff with no embedded credentials.
- Added server-to-server `/payments/webhooks/{providerKey}` handling that preserves raw request bytes for provider verification while persisting only normalized metadata and SHA-256 payload hashes.
- Added monotonic webhook state application, provider reference/amount/currency checks and stale-event protection.
- Added synchronous and asynchronous full-refund handling with provider refund-reference correlation and idempotent retries.
- Added provider cancellation, buyer order-cancellation coordination and fail-safe late-paid reconciliation that preserves cancelled orders while surfacing `payment_reconciliation_required`.
- Added automatic cleanup of reconciliation warnings after a successful full refund and cleanup of stale active-attempt metadata after failed/cancelled attempts.
- Added backend `payment.manage` / `payment.refund` permissions with conservative administrator-only defaults.
- Added native PHP buyer payment initiation, payment-state feedback, provider redirects and `/admin/payments` operations UI with CSRF on all human mutations.
- Added migrations `20260919214000_payment_abstraction` and `20260919214500_payment_refund_reference_scope`.
- Added regression coverage for provider registry rules, initiation idempotency, active-attempt exclusion, webhook verification/deduplication, asynchronous refunds, cancellation coordination, late-paid reconciliation and web CSRF/webhook wiring.
- Added architecture documentation at `docs/architecture/payment-provider-abstraction.md`.
- Final implementation/fix commit: `6f80b195eeac7f8355b585b5d3b3e3f0b3491e26`.
- GitHub Actions build run `35588197013`: success.
- Database migration smoke run `35588196971`: success on MySQL 8.4 and MariaDB 10.11.

### 14.04 — Native Purchase Mode

- Added first-party internal-sale settings, buyer carts, transactional checkout, seller/currency-split orders and separate order/payment/delivery lifecycle states.
- Added server-generated checkout idempotency keys, cart row locking, repeated idempotency checks and all-or-nothing validation before order creation.
- Added immutable order-item title/price snapshots, billing snapshots and bounded receipt metadata so later listing edits do not rewrite historical transactions.
- Added buyer/seller/staff order access with backend IDOR protection, global `marketplace.order.manage` access and historical ownership access that survives later permission changes.
- Added unpaid pending-order cancellation with atomic order/payment/delivery cancellation, order history and central audit.
- Added buyer/seller order-created notifications with dedupe and fail-open notification delivery after durable checkout.
- Added native PHP cart, checkout, orders, order detail and per-listing internal-sale management flows with Marketplace CSRF protection.
- Added stale-cart privacy hardening so listings that become inaccessible no longer leak title or price through the cart.
- Added migration `20260919213000_marketplace_native_purchase` for internal-sale settings, carts, orders, order items and order history plus permission-template defaults.
- Added persistence invariants for item/order currency, subtotal equality and stored line-total verification.
- Added regression coverage for idempotent checkout, multi-seller order splitting, price/title snapshots, self-purchase prevention, IDOR, cancellation and historical order access.
- Added architecture documentation at `docs/architecture/marketplace-native-purchase.md`.
- Final implementation/fix commit: `2171307f49f1c262c700bf9e24ad639891003d2e`.
- GitHub Actions build run `35582912598`: success.
- Database migration smoke run `35582912425`: success on MySQL 8.4 and MariaDB 10.11.

### 14.03 — External Redirect Purchasing Mode

- Added one-to-one external-sale link configuration for marketplace listings with durable privacy-safe click tracking.
- Added a fail-closed HTTPS destination policy backed by explicit host allowlists, optional DNS-boundary subdomain support, IP/userinfo/custom-port/fragment rejection and persisted host binding.
- Added backend-authoritative `marketplace.external_link.use` enforcement for configuration and runtime suppression when a seller later loses the permission.
- Added seller management UI plus an internal warning screen and CSRF-protected POST handoff; redirect targets are loaded only from server-side persisted configuration.
- Added missing UTM source/medium/campaign enrichment without overwriting seller-supplied UTM values and applied `Referrer-Policy: no-referrer` on warning/redirect responses.
- Added central audit snapshots that exclude full destination query strings, and analytics fail-open behavior so click-write failures do not block an otherwise valid handoff.
- Added migration `20260919212000_marketplace_external_sale`, fail-closed runtime config, permission defaults, URL-policy/permission/registry regression coverage and `docs/architecture/marketplace-external-sale.md`.
- Security follow-up commits include runtime seller-permission enforcement `6a0a96275527097003185732346b852cceac568c` and safe UTM separator handling `8bcc64a59e2b0246ff4deb73339c185e6fe493d1`.

### 14.02 — Marketplace Listing UX and Search

- Added native marketplace browse/detail/management UX with grid/list views, bounded pagination, filters, sorting and seller storefronts.
- Integrated Marketplace into profile tabs, navigation and permission-aware global search for active/sold listings.
- Added one-review-per-user ratings, review moderation, featured/pinned placement and expiry-aware browse ordering.
- Added secure marketplace media upload/download through the shared attachment inspector and private storage with controlled media-id routes.
- Added migration `20260919211000_marketplace_discovery_ux`, permission-template defaults, upgrade reindexing, regression coverage and architecture documentation.
- Final implementation/fix commit: `e7147c7a8ad6af4ac960f76abe1d219772796295`.

### 14.01 — Marketplace Domain and Category System

- Added marketplace listings with immutable seller identity, category, unique slug, minor-unit price/currency, description, tags, internal media metadata, typed custom values and explicit lifecycle states.
- Added hierarchical categories with cycle/depth protection and category-scoped text/integer/boolean/select custom fields.
- Added backend ownership/state-transition rules and the new `marketplace.category.manage` permission with conservative template defaults.
- Added native `/admin/marketplace/categories` category/custom-field management with dedicated CSRF and central audit.
- Added migration `20260919210000_marketplace_domain` for categories, listings, tags, media metadata, custom fields/values and lifecycle history.
- Hardened required custom-field values, custom-field category immutability, seller immutability and internal media ownership paths.
- Added marketplace domain/permission regression tests and `docs/architecture/marketplace-domain.md`.
- Completion commits: `f12a21851c8292e356e97a979c0adcceaa94c99d`, `5ac18d1d89d5073efc382fd22848b5684c393c2f`, `b6e369440028b3f3b11b650fc65a29e2750ca55d`, `cedbe6254320eff2997745ec85b89ac1e8ad1305`, `2025833cf8040174935dfc273ff7b026ace6bc49`.
- GitHub Actions build run `35463934099`: success.
- Database migration smoke run `35463934061`: success on MySQL 8.4 and MariaDB 10.11.

### 13.08 — User Promotions and Shared Reward Provider

- Added a shared reward provider/ledger API for first-party referral, giveaway and trophy fulfillment with durable idempotent grant state and retry support.
- Added safe role and secondary-group reward providers on the existing access model; system groups and protected/staff/system roles are excluded from automatic reward targets.
- Added ownership-aware revocation so manual/pre-existing assignments are preserved and shared entitlements are not removed while another active source still requires them.
- Added giveaway/trophy reward bindings plus direct referral reward dispatch through the same gateway.
- Added rule-based User Promotions for account age, visible posts, qualified referrals, current giveaway wins and active trophy count.
- Added optional `revoke_when_unqualified`, bounded cPanel/manual evaluation, advanced scheduler support and reward retry maintenance.
- Added backend `promotion.manage` / `reward.manage`, dedicated CSRF, central audit and native `/admin/promotions` + `/admin/rewards` management workflows.
- Added migrations `20260919180000_reward_promotion_system`, `20260919181000_promotion_system` and `20260919181500_promotion_revocation_policy`.
- Added privilege/revocation/maintenance regression tests and `docs/architecture/reward-promotion-system.md`.
- Completion commits: `846bf031c05320679744d5cd2f3430a1b8db9407`, `e4776292b2892db2b7d79e48b78dfa4a2946f923`, `e89313ef5dd3a377f0db7ed729bf430e768115bb`, `4565cec5b99a4ec1104cfd279a558c975456fab3`, `300ef441868b66f895d5f8a40ce131455e083594`, `076a3d362c5614e690cafd4c2dc54a1888a98dfc`, `08c884d79eb2fbc5d427f710e873edd8ebaeef48`.
- GitHub Actions build run `35463225449`: success.
- Database migration smoke run `35463225405`: success on MySQL 8.4 and MariaDB 10.11.

### 13.07 — Trophy, Badge and Achievement System

- Added trophy/badge/achievement definitions with priority, active state, same-origin icon/banner paths and typed manual/rule configuration.
- Added account-age, visible-post, qualified-referral and current-giveaway-win rules with idempotent automatic evaluation and a persistent batch cursor.
- Added durable grants plus append-only award/revoke history; manual revocation blocks silent automatic re-award.
- Added backend `trophy.view`, `trophy.manage` and `trophy.award` separation, dedicated CSRF and central audit for human mutations.
- Added native `/admin/trophies` management and an `achievements` profile tab with priority ordering, icon/banner presentation and history.
- Added shared award/revoke notifications that do not roll back committed grant state when notification delivery fails.
- Added migration `20260919170000_trophy_system`, hourly bounded evaluation support, regression tests and architecture documentation.
- Completion commits: `f3c417600192b2f1eb568ef351996342b2aac50e`, `ace0c158d74cdc43ce26f22ee79e2d31aa412207`, `75523b638fafa8e9bcc5b4295ff6b11dfdeed13f`, `749d7d89e6a0bcf35171195bc54a77b9359568cd`, `46c90927621d42a8a2f4e61d9e89bec259a026d9`, `aad78cc0593d7c1044f242c367d4b4d55182a326`, `0189a1ca16d87d293d2a4ca0cf576042305e0a15`.
- GitHub Actions build run `35457856516`: success.
- Database migration smoke run `35457856525`: success on MySQL 8.4 and MariaDB 10.11.

### 13.06 — Easter Egg System

- Added centrally managed Easter Egg definitions with route/path, date-window, query trigger, group visibility, message, visual badge and CSS-only animation controls.
- Added global safe-off kill-switch, per-definition enablement, backend `easteregg.manage` enforcement, dedicated CSRF and central administration audit.
- Added fail-open HTML response decoration for Router-native and legacy Community/Report/Moderation paths without affecting APIs, redirects, errors or POST responses.
- Added XSS-safe rendering, maximum-three response cap and reduced-motion behavior.
- Added migrations `20260919160000_easter_egg_system` and `20260919160500_easter_egg_path_nullable`.
- Added native `/admin/easter-eggs` management, routing/runtime regression tests and architecture documentation.
- Completion commits: `916d66920ade8daf9fd99d428900fa06bf09f431`, `80b67df85b9f0cbc0fa1e7ce8034dc6af3a3117d`, `456bf8e8b742d2b02f40b8036aa9d538d43bd00d`, `2d87d939b4f58ad5c11e9f0586c42a7b8e0a2f03`, `a97a52137a6179cfe58d0d4d6003d0f49bcc58f5`, `424c4db8251dafb0859896fd57e002d3a51886e2`.
- GitHub Actions build run `35454717154`: success.
- Database migration smoke run `35454717114`: success on MySQL 8.4 and MariaDB 10.11.

### 13.05 — Giveaway Winner Selection and Transparency

- Added CSPRNG-backed weighted winner selection using fresh 256-bit seeds, canonical SHA-256 population snapshots and unbiased rejection sampling.
- Added immutable primary/redraw chains with public-safe redraw reasons, previous-winner exclusion, proof hashes and central administration audit.
- Added migrations `20260919153000_giveaway_draw_system` and `20260919153500_giveaway_draw_population` with durable privacy-safe draw snapshots.
- Added atomic row-lock winner selection, backend `giveaway.manage` enforcement and closed-state requirements.
- Added winner and replaced-winner notifications plus native PHP management and proof/audit UI.
- Added deterministic proof recomputation and regression coverage for duplicate primary draws, redraw rules, notification, permissions and transaction locking.
- Completion commits: `f8c8cb4c7df7b3ab799aaee628e3ab4123bcff2f`, `060d6bc9fb111a548362dccb8d9df4a9d197ee13`, `9eb43463864dc8cf6d846b164c93ec8b355859ca`, `532a3cb7aae7ac579483a8233ed6a8cebdb81825`, `69f4097861d30a8374fe4fbe993f3b36ce943760`.
- GitHub Actions build run `35453970888`: success.
- Database migration smoke run `35453970881`: success on MySQL 8.4 and MariaDB 10.11.

### 13.04 — Giveaway Participation, Eligibility and Anti-Abuse

- Added durable idempotent participation with weighted entry counts and distinct-user participant capacity.
- Added account-age, visible-post, verified-account, role and referral eligibility policy plus privacy-safe duplicate network/device controls.
- Added backend permission enforcement, owner self-entry prevention, row-lock serialized entry checks and eligibility-policy locking once a giveaway starts.
- Added migration `20260919150000_giveaway_participation` and canonical integrations with roles, forum posts and the 13.02 referral attribution model.
- Added native PHP eligibility/entry UI, CSRF-protected participation POST handling and management controls.
- Added HMAC-only abuse signals; raw IP/User-Agent data is not stored in giveaway participation data or audit snapshots.
- Added participation/anti-abuse regression tests and architecture documentation.
- Completion commits: `6887ec74b410614463d8d312af9a5bd023735d80`, `918830dd11ea743c00859fd6542bbb06e4b5ecd2`, `f8f4157125fa3c0029136424dd98395029b999e6`, `53301fc1c5b7b552282baeb65e0028443b731c87`, `f39c749fffcfcb5e11a2610c1c1595eb26546240`.
- GitHub Actions build run `35448399439`: success.
- Database migration smoke run `35448399387`: success on MySQL 8.4 and MariaDB 10.11.

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