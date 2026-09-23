# Forwext Project Status

This file is the canonical human-readable continuation pointer for Forwext. The current GitHub `main` branch, implementation, tests and migration history remain the final source of truth when any status text is stale.

```text
PROJECT = Forwext
PLAN_VERSION = v2.0
CURRENT_VERSION = 0.0.7.38-dev
LAST_COMPLETED_MAIN_STEP = 15
LAST_COMPLETED_SUBSTEP = 16.02
CURRENT_STEP = 16.03
LAST_COMMIT = 4f9d3bd2918a9b66b1c4cabab5b9f27979950d38
BLOCKERS = none
NEXT_STEP = 16.03 - Background/pattern/gradient/asset sistemi
```

## Current position

- Target: **Forwext 1.0.0 Production**, not an MVP/demo/prototype.
- Binding roadmap: **20 main steps / 138 real sub-steps**, plan v2.0.
- Repository: `benjamin1734/Warext-Studios-Forwext-Open-Source-Forum-Software`, default branch `main`.
- Project license: **Apache-2.0**.
- Completed main steps: `01`, `02`, `03`, `04`, `05`, `06`, `07`, `08`, `09`, `10`, `11`, `12`, `13`, `14`, `15`; main step `16` is active.
- Completed sub-steps: `01.01–01.06`, `02.01–02.07`, `03.01–03.07`, `04.01–04.08`, `05.01–05.07`, `06.01–06.08`, `07.01–07.07`, `08.01–08.06`, `09.01–09.07`, `10.01–10.06`, `11.01–11.05`, `12.01–12.08`, `13.01–13.08`, `14.01–14.08`, `15.01–15.06`, `16.01–16.02`.
- Current sub-step: `16.03 — Background/pattern/gradient/asset sistemi`.
- Remaining roadmap work after 16.02: **32 real sub-steps**.
- Minimum deployment remains PHP 8.4+, MySQL/MariaDB and Apache/LiteSpeed/Nginx with a first-class native PHP frontend; Composer/npm/Node/SSH/Redis/Docker/Supervisor are not mandatory on normal cPanel runtime.
- Advanced deployments may add Redis, workers, WebSocket/SSE providers, S3-compatible storage, external search and Docker/VDS infrastructure without breaking the minimum profile.

`LAST_COMMIT` records the implementation/fix commit that completed the last roadmap sub-step. Status-only, changelog-only and unrelated contract-correction commits are intentionally not used as the roadmap completion pointer.

## Completed in 16.02

- Added typed component appearance targets for header, navigation, footer, forum, thread, post, profile, button, input, modal, badge, role banner, editor, table and alert.
- Added a versioned component appearance manifest whose fixed properties bind only to validated design-token keys; arbitrary selectors/raw CSS are not accepted.
- Added registry validation for required targets, duplicates, property names and design-token references.
- Added deterministic `--forwext-component-*` CSS-variable compilation and shared native PHP runtime integration.
- Migrated current header/navigation/cards/profile/buttons/inputs/badges/alerts to component variables while preserving their existing visual defaults.
- Connected role-banner CSS to the shared component contract with an isolated-embed fallback.
- Exposed the same component appearance manifest through the optional TypeScript/React package.
- Added component-specific color/shadow semantic tokens required to preserve existing badge, alert, contrast and role-banner visuals.
- Added registry/compiler/native-surface tests covering every binding-roadmap component target.
- No database migration or new permission/audit event is required because 16.02 still exposes immutable shipped presentation bindings only.
- Implementation commit: `4f9d3bd2918a9b66b1c4cabab5b9f27979950d38`.
- GitHub Actions build run `35898405999`: success; strict-types, PHP lint, PHPUnit PHP 8.4/8.5, production PHP 8.4 dependency baseline and cPanel full-package build passed.
- Database migration smoke run `35898406003`: success on MySQL 8.4 and MariaDB 10.11 with post-install web bootstrap smoke.
- Version: `0.0.7.38-dev`.
- Next: `16.03 — Background/pattern/gradient/asset sistemi`.

## Completed in 16.01

- Added a versioned canonical design-token manifest covering color, typography, spacing, radius, border, shadow, motion and semantic categories.
- Added strict PHP token parsing, category/value validation, duplicate/reference integrity checks and reference-cycle detection.
- Added fail-closed CSS primitive policy rejecting statement delimiters/control characters plus raw url(), expression(), var() and @import injection paths.
- Added deterministic `--forwext-*` CSS custom-property compilation and reduced-motion duration overrides.
- Integrated the native PHP frontend with semantic compatibility aliases so existing visual behavior remains stable while component migration can proceed incrementally.
- Added the shared TypeScript/React consumption boundary over the same canonical JSON manifest without adding Node/npm to the cPanel runtime.
- Added regression coverage for required categories, semantic resolution, unsafe values, cycles, compiler output and native/TypeScript manifest sharing.
- No database migration or new backend permission/audit event is required because 16.01 introduces immutable shipped presentation defaults and no user-controlled mutation surface.
- Implementation commit: `7b970dbcf8356bfdebac5f3d24914f9da6b1127a`.
- GitHub Actions build run `35897236806`: success; strict-types, PHP lint, PHPUnit PHP 8.4/8.5, production PHP 8.4 dependency baseline and cPanel full-package build passed.
- Database migration smoke run `35897236805`: success on MySQL 8.4 and MariaDB 10.11 with post-install web bootstrap smoke.
- Version: `0.0.7.37-dev`.
- Next: `16.02 — Component görünüm ayarları`.

## Completed in 15.06

- Added a native PHP analytics report builder with bounded UTC date ranges, fixed allowlisted filters and owner-scoped saved reports.
- Added scoped analytics permissions for forum, content, operations and commerce while retaining `analytics.view_site` as the compatibility super-permission.
- Added backend-enforced report-use, CSV/JSON export, manage-all and unaggregated permissions; UI visibility does not replace authorization.
- Added privacy aggregation with an effective minimum count of 5 unless explicitly authorized, and suppressed-row accounting without revealing suppressed values.
- Added centralized administration audit for report save/delete, dedicated CSRF protection, strict saved-report ID validation and parameterized report filters.
- Added CSV spreadsheet-formula neutralization plus private/no-store/noindex/nosniff export response policy.
- Added additive/idempotent migration `20260923201500_analytics_report_builder` for saved-report persistence and conservative permission defaults.
- Added report model/export/privacy/web/migration regression coverage and architecture documentation.
- Feature commit: `d95f0f2d2430ad2252ecbae01ed5c06638e94141`; link syntax correction: `3097d35a891061d35914c1cd6daf3138995ed3ce`; final regression correction: `e7bd41d16aff7af814ff969acf57b09de5c2b6e1`.
- GitHub Actions build run `35896110292`: success; strict-types, PHP lint, PHPUnit PHP 8.4/8.5, production PHP 8.4 dependency baseline and cPanel full-package build passed.
- Database migration smoke run `35896110441`: success on MySQL 8.4 and MariaDB 10.11 with post-install web bootstrap smoke.
- Version: `0.0.7.36-dev`.
- Next: `16.01 — Design token motoru`.

## Completed in 15.05

- Added privacy-aware structural `marketplace.listing.view` recording for successful Marketplace detail HTML requests.
- Added backend-authorized `/admin/analytics/commerce` with fixed 7/30/90-day UTC windows and cross-links from all analytics dashboards.
- Added listing creation/current inventory, recorded listing views, external outbound clicks, external CTR and order lifecycle metrics.
- Added currency-separated first-paid-transition GMV, succeeded refunds and net payment-flow metrics without mixing currencies.
- Added currency-separated advertising impression/click CTR and configured estimated-revenue metrics; these are explicitly not settlement revenue.
- Added referral click/attribution cohort, review/qualified/rejected, reward-unit and per-campaign conversion metrics without querying IP/device fingerprints.
- Added giveaway creation, unique participant, weighted-entry and draw/redraw analytics without querying anti-abuse fingerprints.
- Added additive/idempotent migration `20260923195000_commerce_analytics_indexes`, migration-registry coverage, snapshot/web/privacy tests and architecture documentation.
- Final implementation commit: `5bb212419cf021b8faf8915f7356761928ffcf19`; listing-view producer commit: `15445be9087611d674ea9d8527b264b2984c0564`.
- GitHub Actions build run `35892034814`: success; strict-types, PHP lint, PHPUnit PHP 8.4/8.5, production PHP 8.4 dependency baseline and cPanel full-package build passed.
- Database migration smoke run `35892035230`: success on MySQL 8.4 and MariaDB 10.11 with post-install web bootstrap smoke.
- Version: `0.0.7.35-dev`.
- Next: `15.06 — Rapor builder/export ve erişim yetkileri`.

## Completed in 15.04

- Added backend-authorized `/admin/analytics/operations` with fixed 7/30/90-day UTC windows and cross-links from the existing analytics dashboards.
- Added moderation report volume, grouped-case terminal counts/average terminal timing and warning/restriction/suspension/ban metrics from authoritative moderation tables.
- Added support created/resolved/closed volume, average first-response/resolution timing and cohort-based first-response/resolution SLA breach metrics.
- Added bug created/resolved/rejected/duplicate volume, average finalization time and per-category breakdowns.
- Added staff workload aggregation across current report/support/bug assignments plus selected-window discipline and central audit action metadata; the displayed total is operational workload, not a staff-quality score.
- Kept the dashboard metadata-only: report/ticket/bug free text, discipline reason text and audit/history JSON payloads are not queried.
- Reused backend `analytics.view_site` authorization and private/no-store/noindex response policy.
- Added additive/idempotent migration `20260923190000_operations_analytics_indexes` and registered it after the 15.03 analytics migration.
- Added snapshot validation, web/privacy wiring and migration-registry regression coverage plus architecture/milestone documentation.
- Final implementation/fix head: `2dfab4ed875a9a3b4f04432615490a9a379ac1f8`.
- GitHub Actions build run `35889912569`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production PHP 8.4 dependency baseline and cPanel full-package build passed.
- Database migration smoke run `35889912544`: success on MySQL 8.4 and MariaDB 10.11, including post-install web bootstrap smoke.
- Version: `0.0.7.34-dev`.
- Next: `15.05 — Marketplace/revenue/referral/giveaway analizleri`.

## Completed in 15.03

- Added forum/category/thread performance aggregation, reactions, bookmarks, watched threads/forums, follow performance and privacy-aware search analytics.
- Added structural `content.thread.view` references, recursive category aggregation and HMAC-keyed safe search-term daily aggregation with redaction for unsafe query classes.
- Added `/admin/analytics/content` with backend `analytics.view_site` enforcement, strict 7/30/90-day ranges and private/no-store/noindex responses.
- Added migration `20260921232000_content_engagement_analytics`, structural/privacy/query-index coverage and analytics dashboard cross-linking.
- Added follow-leader aggregation and the final PHPUnit compatibility/search-normalization corrections present on current `main`.
- Final 15.03-labeled correction commit: `70ee749345c444bf996c9ad16256261a20b21679`; cumulative validation includes all 15.03 work in `2dfab4ed875a9a3b4f04432615490a9a379ac1f8`.
- GitHub Actions build run `35889912569`: success; PHP 8.4/8.5 PHPUnit and packaging passed on the cumulative validated head.
- Database migration smoke run `35889912544`: success on MySQL 8.4 and MariaDB 10.11 on the cumulative validated head.
- Version sequence advances directly to `0.0.7.34-dev` because 15.03 and 15.04 were validated and closed together after the queued CI chain; no separate releasable 0.0.7.33 artifact was published.

## Completed in 15.02

- Added a permission-gated site analytics dashboard at `/admin/analytics` with 7/30/90-day windows.
- Added DAU, MAU, active-account count, registrations, visible thread/post totals, current online, 24-hour/7-day peak and 7-day/30-day activity-retention metrics.
- Registration/thread/post/current-presence metrics use authoritative domain tables; distinct-user activity metrics use only the 15.01 HMAC `actor_hash` event identity.
- Added equal-length previous-window growth comparison and UTC daily trend rows, including zero-activity days.
- Presence heartbeat now records best-effort `user.active` with the same domain-separated analytics privacy key as the main runtime.
- Added backend `analytics.view_site` enforcement, strict range validation and private/no-store/noindex response policy.
- Added migration `20260921231000_forum_analytics_dashboard` for query-support indexes and administrator-only default site-analytics permission.
- Added dashboard model/privacy/web/migration regression coverage and `docs/architecture/forum-analytics-dashboard.md`.
- Final implementation/fix commit: `c79a77735c294c849d52176eed9b5afe648fae9c`; regression-test commit: `b79e7b3481b717911a21bfe6e81ac768146d57b9`.
- GitHub Actions build run `35885522027`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35885741563`: success on MySQL 8.4 and MariaDB 10.11.
- Version: `0.0.7.32-dev`.
- Next: `15.03 — İçerik ve engagement analizleri`.
## Completed in 15.01

- Added the shared privacy-aware analytics event registry covering forum, user, content, support, bug, Marketplace, referral, giveaway and moderation domains.
- Each event definition now owns its retention period, identity collection permissions and exact dimension allowlist.
- Added installation-specific HMAC-SHA256 pseudonymization for actor, session and subject identities with a domain-separated analytics privacy key.
- Added strict dimension token policy and raw-IP rejection so analytics cannot become a side channel for e-mail, URL, request/body or free-form content storage.
- Added migration `20260921230000_analytics_event_model` with category/event/forum/actor/subject/retention indexes and no raw personal request identifiers.
- Added strict and best-effort recorder paths; web runtime records `user.active` and `forum.view` only after successful HTML GET responses and resolves thread-owned forums.
- Reused the existing bounded browser/device classifier instead of storing raw user-agent strings.
- Added daily bounded per-event retention maintenance through the existing scheduler/maintenance queue abstraction.
- Added registry/privacy/runtime/retention regression coverage plus architecture documentation at `docs/architecture/analytics-event-model.md`.
- Final implementation/fix commit: `8736f3478c306c9d221f7e50b1f13904d8374085`; final regression-test correction: `88444be88da8d6609c3f24c0261b0248b3c3b7ef`.
- GitHub Actions build run `35633962639`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35633962656`: success on MySQL 8.4 and MariaDB 10.11.
- Version: `0.0.7.31-dev`.
- Next: `15.02 — Forum analiz dashboardu`.
## Completed in 14.08

- Added advertisement, notice and announcement campaign types with registered site/content/thread placements.
- Added runtime route wildcard, forum, primary/secondary group, desktop/mobile and UTC time-window targeting.
- Added rolling frequency caps using domain-separated HMAC viewer identities; raw account/anonymous identities are not persisted in advertising events.
- Added native response-pipeline decoration with fail-open behavior so placement failures leave the underlying forum page intact.
- Added tracked campaign click redirects with server-side destination lookup, relative/HTTPS URL policy and rapid-repeat analytics deduplication.
- Added impression/click event persistence and 30-day CTR plus estimated revenue/value reporting.
- Added `/admin/advertising` with real forum/group selectors, route/device/time targeting, frequency controls and performance reporting.
- Added backend `ads.manage` / `notice.manage` permission enforcement, dedicated advertising CSRF and central administration audit transactions.
- Added migration `20260921223000_advertising_notice_system` and registered it in the core installer.
- Added architecture documentation at `docs/architecture/advertising-notice-placement.md` and milestone notes at `docs/changelog/14.08-advertising-notice-placement.md`.
- Final implementation/fix commit: `d70effd8240f0badb8adb1c0badd805354e4b0ed`; final regression-test commit: `e32f0f1b7b0b56e2487194dce75be13b4f463552`.
- GitHub Actions build run `35631552545`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35631552587`: success on MySQL 8.4 and MariaDB 10.11.
- Version: `0.0.7.30-dev`.
- Next: `15.01 — Analytics event modeli`.
## Completed in 14.07

- Added timed and lifetime upgrade plans with stable keys, active state, price/currency, duration snapshots and deterministic ordering.
- Added durable user subscriptions with `active` / `expired` / `revoked` lifecycle and one aggregate entitlement per user/plan.
- Added runtime role overlays through the existing `UserAccessAssignment` path and runtime flag-permission overlays through the common permission repository.
- Runtime authorization checks the database UTC end time directly, so expired access disappears even before durable cleanup changes the row state.
- Added safe role eligibility and sensitive-permission filtering so staff/system/protected roles and administration-grade permission classes are not directly exposed as upgrade entitlements.
- Added provider-backed subscription purchase snapshots with idempotency, immutable amount/currency/duration, provider reference/action state and one-active-payment-attempt protection.
- Added verified server-to-server subscription payment webhooks with provider/reference/amount/currency validation, provider-scoped event dedupe, monotonic state application and SHA-256-only raw-payload persistence.
- Added one-time paid activation, timed renewal from the existing future end date, lifetime entitlement semantics, manual admin grant/renew, explicit revoke and bounded expiry normalization.
- Added native member `/account/upgrades`, purchase POST, `/admin/subscriptions`, dedicated subscription CSRF and `Upgrades` member navigation.
- Added migration `20260921220000_subscription_upgrade_system` for plans, bindings, subscriptions, purchases, webhook events and entitlement history plus built-in permission defaults.
- Added `/account/upgrades` to post-install navigation smoke for both root and `/public` deployments and added regression coverage for entitlement/security/webhook wiring.
- Added architecture documentation at `docs/architecture/subscription-user-upgrades.md` and milestone notes at `docs/changelog/14.07-subscription-user-upgrades.md`.
- Final implementation/fix commit: `b343eec151a98e1d37316db6d34fd6def5dd8193`; final regression-test correction: `aa08744311a29537df306af049c763a57807f0b8`.
- GitHub Actions build run `35627236876`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35627236887`: success on MySQL 8.4 and MariaDB 10.11.
- Version: `0.0.7.29-dev`.
- Next: `14.08 — Reklam/notice/placement sistemi`.
## Completed in 14.06

- Added first-party Marketplace delivery types for download, license, key and manual fulfillment, with immutable per-order-item delivery snapshots so later listing configuration changes do not rewrite existing orders.
- Added private delivery assets backed by the shared storage/attachment inspection stack, controlled MIME allowlists, SHA-256/size integrity checks and authenticated download responses with no-store, nosniff and no-referrer protections.
- Added encrypted license/key pools with HMAC fingerprints, duplicate suppression, atomic checkout-time reservation, paid-settlement activation, cancellation release and buyer-only reveal flows.
- Added manual seller fulfillment that is available only for paid eligible orders and advances shared order delivery state/history through the same domain service.
- Added shared `marketplace.delivery.manage_own` and `marketplace.delivery.manage_all` permissions with backend-authoritative seller/staff checks and conservative permission-template defaults.
- Added native PHP delivery management at `/marketplace/manage/delivery/{listingId}`, including verified private file upload, delivery-type configuration and bounded bulk key ingestion.
- Added native order fulfillment routes at `/marketplace/orders/{orderId}/delivery/{itemId}/{action}` for manual fulfillment, buyer reveal and controlled download, all with order/item binding and IDOR-safe access checks.
- Added order-detail delivery state/actions, append-only order history presentation and a Marketplace-order support/dispute link using the common Support context registry.
- Added `marketplace_order` support context resolution for buyer/seller/authorized staff while preventing unrelated users from probing order existence.
- Added migration `20260919215000_marketplace_digital_delivery` for delivery assets/settings/key pools/order-item delivery state plus upgrade-safe order-item snapshots and permission defaults.
- Expanded post-install web smoke coverage to every core navigation page in both root and `/public` subfolder deployments, treating both 404 and 5xx responses as failures.
- Added regression coverage for the new permissions, order-support IDOR boundary, delivery web routes, CSRF wiring and secret/download response policy.
- Added architecture documentation at `docs/architecture/marketplace-digital-delivery.md`.
- Final implementation commit: `f5f79aae32b63e56579185dbc5ad6a9c3e972ed0`; final test correction: `c3d67a6bd5558304a05a1d7e3fa19d8e463a22b4`.
- GitHub Actions build run `35591402493`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35591402451`: success on MySQL 8.4 and MariaDB 10.11, including the expanded navigation/subfolder smoke.
- Version: `0.0.7.28-dev`.
- Next: `14.07 — Abonelik/user upgrades`.

## Completed in 14.05

- Added a provider-agnostic `PaymentProvider` contract and injectable `PaymentProviderRegistry`; the default cPanel runtime remains valid with zero configured providers.
- Added typed durable payment attempts, provider results, verified webhook events, full-refund records and provider cancellation/refund capability metadata.
- Added transactional/idempotent initiation with locked Marketplace orders, persistence-level idempotency and a single active nonterminal attempt per order.
- Added strict amount/currency/buyer/provider-reference matching, monotonic state progression and row-locked webhook application.
- Added HTTPS-only provider checkout URLs, same-origin return/cancel paths, no-credential redirects and no-referrer external handoff.
- Added public server-to-server webhook routing with provider cryptographic-verification boundary; raw webhook payloads are not stored, only SHA-256 plus normalized event metadata.
- Added synchronous and asynchronous full-refund support with attempt-scoped provider refund-reference correlation and duplicate-refund prevention.
- Added payment/provider cancellation and buyer order-cancellation coordination so an active provider attempt cannot be orphaned by an unpaid-order cancellation.
- Added late-paid reconciliation for cancelled orders: order cancellation is preserved, paid state is recorded, staff/user warning is surfaced and successful refund clears the reconciliation flag.
- Added shared `payment.manage` / `payment.refund` permissions with conservative administrator defaults.
- Added native PHP payment initiation and `/admin/payments` operations surfaces, with CSRF on human mutations while provider webhooks deliberately remain session/CSRF-independent.
- Added migrations `20260919214000_payment_abstraction` and `20260919214500_payment_refund_reference_scope`.
- Added regression coverage for registry behavior, idempotency, active-attempt exclusion, webhook fail-closed verification/deduplication, asynchronous refund completion, cancellation coordination, late-paid reconciliation and web-surface security wiring.
- Added architecture documentation at `docs/architecture/payment-provider-abstraction.md`.
- Final implementation/fix commit: `6f80b195eeac7f8355b585b5d3b3e3f0b3491e26`.
- GitHub Actions build run `35588197013`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel full/update packaging passed.
- Database migration smoke run `35588196971`: success on MySQL 8.4 and MariaDB 10.11.
- Version: `0.0.7.27-dev`.
- Next: `14.06 — Dijital teslimat ve sipariş yönetimi`.

## Completed in 14.04

- Added first-party internal-sale capability per listing, persistent carts, transactional checkout and durable orders without prematurely coupling the domain to any payment provider.
- Added separate typed order, payment and delivery state models so 14.05 payment providers and 14.06 fulfillment can advance the same order record independently.
- Added server-generated 128-bit checkout idempotency keys, pre/post-lock idempotency checks and `FOR UPDATE` cart locking.
- Checkout revalidates every listing and is all-or-nothing; multi-seller/multi-currency carts are split into deterministic seller/currency order groups.
- Added immutable item title/unit-price snapshots, billing snapshots and bounded receipt metadata so historical orders are unaffected by later listing edits.
- Added backend-authoritative buyer/seller/order-manager access, unrelated-user IDOR protection and historical own-order access after later permission changes.
- Added stale-cart privacy hardening: inaccessible/non-public listings do not expose historical title or price from the live listing record.
- Added pending unpaid cancellation with synchronized order/payment/delivery cancellation, append-only order history and central administration audit.
- Added deduplicated buyer/seller order-created notifications that cannot roll back already committed checkout state.
- Added native PHP internal-sale settings, cart, checkout, order list and order detail flows using existing Marketplace CSRF infrastructure.
- Added migration `20260919213000_marketplace_native_purchase` for five native-purchase tables and shared permission defaults.
- Added database invariants for order-item ownership, currency agreement, subtotal equality and stored line-total verification.
- Added regression coverage for idempotent checkout, seller/currency splitting, snapshots, self-purchase prevention, stale-cart privacy, order IDOR, staff access, cancellation and historical order access.
- Added architecture documentation at `docs/architecture/marketplace-native-purchase.md`.
- Final implementation/fix commit: `2171307f49f1c262c700bf9e24ad639891003d2e`.
- GitHub Actions build run `35582912598`: success; strict-types, PHP lint, PHPUnit on PHP 8.4 and PHP 8.5, production dependency baseline and cPanel full/update packaging passed.
- Database migration smoke run `35582912425`: success on MySQL 8.4 and MariaDB 10.11.
- Version: `0.0.7.26-dev`.
- Next: `14.05 — Ödeme sağlayıcı abstraction`.

## Completed in 14.03

- Added durable one-to-one external-sale links and privacy-aware click tracking without introducing native order/payment semantics early.
- Added a fail-closed external URL policy: HTTPS only, explicit host allowlist, optional label-boundary subdomains, no IP literals, userinfo, fragments or custom ports, and host binding revalidation at handoff time.
- Added runtime seller-permission enforcement using the shared `marketplace.external_link.use` permission; revoking the permission immediately hides a previously configured external handoff.
- Added native seller configuration UX, a Forwext warning/interstitial and CSRF-protected POST confirmation before the external 303 redirect.
- Added server-side-only redirect target resolution, UTM source/medium/campaign enrichment that preserves seller-supplied values, `Referrer-Policy: no-referrer`, no-store and noindex response policy.
- Added central audit for configuration changes while excluding full URL query strings from audit snapshots; click records store listing, optional viewer, host and UTC timestamp only.
- Added migration `20260919212000_marketplace_external_sale` and fail-closed runtime configuration under `marketplace.external_sale`.
- Added URL-policy, permission and migration-registry regression coverage plus `docs/architecture/marketplace-external-sale.md`.
- Verified the new 14.03 PHP files with PHP 8.4 syntax lint and exercised the URL policy in a local runtime harness for allowlist, subdomain-boundary, unsafe-scheme/host and UTM cases. Full-repository PHPUnit could not be executed in the isolated local container because repository/dependency network access is unavailable.
- Final implementation/fix commit: `8bcc64a59e2b0246ff4deb73339c185e6fe493d1`.
- Version: `0.0.7.25-dev`.
- Next: `14.04 — Dahili satın alım modu`.

## Completed in 14.02

- Added native PHP marketplace browse/detail/management flows with grid/list presentation, bounded pagination and text/category/tag/currency/price/featured filtering and sorting.
- Added public seller storefronts, marketplace profile-tab integration, core navigation and permission-aware global search indexing for active/sold listings.
- Added one-review-per-user ratings, common content-pipeline moderation, separate review moderation authority and visible-only rating aggregates.
- Added audited featured/pinned placement with expiry-aware ordering and dedicated `marketplace.feature.manage` capability.
- Added secure listing media upload/download through the shared attachment inspector and private storage; public access uses controlled media-id routes with listing visibility checks and digest verification.
- Added backend-authoritative own/all listing management and lifecycle actions while retaining the 14.01 transition rules and ownership checks.
- Added migration `20260919211000_marketplace_discovery_ux` for reviews, promotions, query indexes, permission-template defaults, profile tabs and upgrade reindexing.
- Added architecture documentation at `docs/architecture/marketplace-listing-ux.md` and regression coverage for discovery, permissions, profile/search integration, reviews, promotions and media isolation.
- Final implementation/fix commit: `e7147c7a8ad6af4ac960f76abe1d219772796295`.
- The final code keeps external-sale redirects in 14.03 and native checkout/order/payment in later marketplace substeps.
- Next: `14.03 — Haricî link yönlendirme modu`.

## Completed in 14.01

- Added a first-party marketplace listing domain with immutable seller identity, category, unique slug, title/description, integer minor-unit price, three-letter currency, tags, typed media metadata, typed category custom values, lifecycle state and UTC timestamps.
- Added hierarchical marketplace categories with optional parent, stable key/slug, enabled state, sort order, self-parent/cycle detection and an eight-ancestor depth limit.
- Added typed category custom fields for text, integer, boolean and select values; select options are explicit, required values are backend-validated and existing fields cannot be moved across categories after creation.
- Added internal marketplace media metadata constraints for JPEG/PNG/WebP and listing-owned private-storage style paths; arbitrary external media URLs and cross-listing paths are rejected at the domain boundary.
- Added explicit listing lifecycle rules: new listings start draft; sellers may submit/pause/mark sold/close through service transitions; direct state edits are rejected; only `marketplace.listing.manage_all` can approve pending listings or archive terminal listings.
- Added backend-authoritative seller ownership using existing `marketplace.listing.create`, `marketplace.listing.manage_own`, `marketplace.listing.manage_all` and `marketplace.listing.view`; added `marketplace.category.manage` for category/custom-field schema administration.
- Added conservative permission-template defaults: members/verified users can create/manage their own listings, moderators can manage all listings but not category schema by default, and administrators receive full category/listing management.
- Added central administration audit for category/custom-field mutations and staff listing approval/archive; listing create/update/state transitions retain dedicated marketplace lifecycle history.
- Added native `/admin/marketplace/categories` management with dedicated CSRF, category parent selection, enable/sort controls and typed custom-field editing.
- Added migration `20260919210000_marketplace_domain` creating categories, listings, tags, media metadata, custom-field definitions/values and listing history.
- Kept listing grid/search/reviews/seller-profile/featured-pinned UX in 14.02, external-link mode in 14.03 and payment/order concerns in their later owning substeps.
- Added architecture documentation at `docs/architecture/marketplace-domain.md` and regression coverage for money/media validation, category cycles, required typed fields, immutable field category/seller, permission boundaries and draft→pending→staff-approval lifecycle.
- Completion commits: `f12a21851c8292e356e97a979c0adcceaa94c99d`, `5ac18d1d89d5073efc382fd22848b5684c393c2f`, `b6e369440028b3f3b11b650fc65a29e2750ca55d`, `cedbe6254320eff2997745ec85b89ac1e8ad1305`, `2025833cf8040174935dfc273ff7b026ace6bc49`.
- GitHub Actions build run `35463934099`: success; strict-types, lint, PHPUnit on supported PHP jobs, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35463934061`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `14.02 — Marketplace listing UX ve arama`.

## Completed in 13.08

- Added a shared reward definition/provider/ledger contract used by first-party referral, giveaway and trophy flows instead of each subsystem inventing its own access-assignment fulfillment path.
- Added durable idempotent reward grants with pending/applied/failed/revoked states, source identity, provider/target snapshots, retryability and shared assignment-ownership tracking.
- Added built-in secondary-group and role reward providers on the existing 05.01 access model; only non-system secondary groups and unprotected custom roles are eligible.
- Rejected system groups, protected roles, staff roles and system roles both from provider target discovery and again at apply time so reward automation cannot escalate ACP/moderation privileges.
- Added ownership-safe reward revocation: manual/pre-existing assignments are not claimed by the reward engine, and managed assignments are removed only when no other active entitlement still requires the same target.
- Bridged 13.02 referral qualification to the shared reward gateway while retaining the referral ledger as authoritative when common fulfillment is temporarily unavailable.
- Bridged 13.05 giveaway winner selection/redraw to reward bindings; redraw revokes the superseded winner's managed reward source and fulfills the replacement winner independently of immutable draw/audit state.
- Bridged 13.07 trophy award/revoke to reward bindings without allowing reward failures to roll back trophy grant/history state.
- Added rule-based User Promotions using account age, visible post count, qualified referral count, current giveaway wins and active trophy count; matching rules grant configured shared rewards.
- Added optional ownership-safe `revoke_when_unqualified` promotion policy through additive migration `20260919181500_promotion_revocation_policy`.
- Added bounded promotion evaluation and cPanel/manual single-user/batch controls plus optional advanced scheduler jobs; added 15-minute bounded retry support for pending/failed shared reward grants.
- Added native `/admin/rewards` and `/admin/promotions` workflows with dedicated CSRF, backend-authoritative `reward.manage` / `promotion.manage`, reward definitions, source bindings, retry controls and promotion rule management.
- Added central administration audit for reward definitions/bindings/retries and promotion definitions/manual evaluations.
- Added migrations `20260919180000_reward_promotion_system`, `20260919181000_promotion_system` and additive `20260919181500_promotion_revocation_policy`.
- Added architecture documentation at `docs/architecture/reward-promotion-system.md` and regression coverage for promotion revoke policy, protected/staff/system role rejection, system-group rejection, primary-group duplication avoidance and bounded maintenance schedules.
- Completion commits: `846bf031c05320679744d5cd2f3430a1b8db9407`, `e4776292b2892db2b7d79e48b78dfa4a2946f923`, `e89313ef5dd3a377f0db7ed729bf430e768115bb`, `4565cec5b99a4ec1104cfd279a558c975456fab3`, `300ef441868b66f895d5f8a40ce131455e083594`, `076a3d362c5614e690cafd4c2dc54a1888a98dfc`, `08c884d79eb2fbc5d427f710e873edd8ebaeef48`.
- GitHub Actions build run `35463225449`: success; strict-types, lint, PHPUnit on supported PHP jobs, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35463225405`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Main step 13 is complete. Next: `14.01 — Marketplace domain ve kategori sistemi`.

## Completed in 13.07

- Added first-party trophy/badge/achievement definitions with stable keys, kind, active state, priority, description, optional same-origin icon/banner paths and typed rule configuration.
- Added deterministic built-in rule metrics for account age, visible post count, qualified referrals and current giveaway wins while keeping manual grants as an explicit rule type.
- Added durable one-per-user/definition grant lifecycle plus append-only award/revoke history with source, actor, reason and timestamp.
- Added automatic idempotent rule evaluation with an hourly bounded 200-user batch, persistent cursor and explicit cPanel-safe single-user evaluation action.
- Made manual revocation authoritative against the automatic evaluator: a revoked rule grant is not silently re-awarded; only an authorized manual award can restore it.
- Added backend-authoritative `trophy.view`, `trophy.manage` and `trophy.award` defaults with separate definition-management and award/revoke authority.
- Added central administration audit for definition create/update, manual award/revoke and human-triggered user evaluation; automated rule grants retain durable domain history without inventing a human actor.
- Added shared `trophy.awarded` / `trophy.revoked` notifications after committed grant mutations; notification failure is non-authoritative and cannot roll back a persisted achievement.
- Added the `achievements` profile tab for existing/new profiles, active achievement cards ordered by priority, same-origin icon/banner presentation and recent award/revoke history.
- Added native `/admin/trophies` management with dedicated CSRF, definition editing, rule thresholds, manual award/revoke by username and bounded rule evaluation.
- Added migration `20260919170000_trophy_system` for definitions, grants, append-only history and evaluation cursor state.
- Hardened icon/banner paths against traversal, external/protocol-relative URLs, query strings, fragments and control characters.
- Added rule idempotency, revocation, priority ordering, profile history, asset safety and scheduler regression tests plus `docs/architecture/trophy-system.md`.
- Feature/fix commits: `f3c417600192b2f1eb568ef351996342b2aac50e`, `ace0c158d74cdc43ce26f22ee79e2d31aa412207`, `75523b638fafa8e9bcc5b4295ff6b11dfdeed13f`, `749d7d89e6a0bcf35171195bc54a77b9359568cd`, `46c90927621d42a8a2f4e61d9e89bec259a026d9`, `aad78cc0593d7c1044f242c367d4b4d55182a326`, `0189a1ca16d87d293d2a4ca0cf576042305e0a15`.
- GitHub Actions build run `35457856516`: success; strict-types, lint, PHPUnit on PHP 8.4/8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35457856525`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `13.08 — User promotions ve ödül provider sistemi`.

## Completed in 13.06

- Added first-party Easter Egg definitions with per-definition enabled state, priority, exact route and/or path-prefix targeting, optional UTC date window, automatic/query-token triggers, visual badge labels and CSS-only none/pulse/glow/confetti presentation.
- Added global runtime kill-switch seeded safely OFF; disabling it bypasses every Easter Egg definition without changing or deleting configuration.
- Added optional primary/secondary user-group visibility using the existing access-assignment model; empty group scope remains visible to any viewer who already has page access.
- Added fail-open response decoration: only successful GET/HEAD HTML responses are modified, while Easter Egg subsystem failures return the original forum response instead of causing a site-wide 500.
- Added Router global middleware support so route attributes are resolved before common middleware; Router-native pages support route-name + path matching and legacy Community/Report/Moderation HTML surfaces receive path-pattern decoration from the front controller.
- Added XSS-safe rendering, maximum three surprises per response, CSS-only animations and `prefers-reduced-motion` handling without weakening the existing script CSP.
- Added native `/admin/easter-eggs` management with dedicated CSRF protection, backend-authoritative `easteregg.manage`, global switch, create/edit, schedule, trigger, page/route, animation/badge and group controls.
- Added central administration audit for global toggle and definition create/update while omitting surprise message text from audit snapshots.
- Added migrations `20260919160000_easter_egg_system` and additive `20260919160500_easter_egg_path_nullable`; route-name-only definitions remain compatible without rewriting the applied base migration.
- Kept the 13.06 badge strictly presentational; persistent trophies/badges/achievement history are intentionally owned by 13.07.
- Added kill-switch, routing, date/token/group, XSS, reduced-motion, fail-open and Router global-middleware regression coverage plus `docs/architecture/easter-egg-system.md`.
- Feature/fix commits: `916d66920ade8daf9fd99d428900fa06bf09f431`, `80b67df85b9f0cbc0fa1e7ce8034dc6af3a3117d`, `456bf8e8b742d2b02f40b8036aa9d538d43bd00d`, `2d87d939b4f58ad5c11e9f0586c42a7b8e0a2f03`, `a97a52137a6179cfe58d0d4d6003d0f49bcc58f5`; test/docs hardening: `424c4db8251dafb0859896fd57e002d3a51886e2`.
- GitHub Actions build run `35454717154`: success; strict-types, lint, PHPUnit on PHP 8.4/8.5, production dependency baseline and cPanel packaging passed.
- Database migration smoke run `35454717114`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `13.07 — Trophy/rozet/başarım sistemi`.

## Completed in 13.05

- Added cryptographically secure winner selection using a fresh 256-bit `random_bytes` seed, SHA-256 canonical population hashing and unbiased 63-bit rejection sampling over weighted entry tickets.
- Added immutable draw records with sequence/kind/parent lineage, disclosed seed, population hash, selected ticket, winner entry/user, proof hash, actor and timestamp.
- Added additive migrations `20260919153000_giveaway_draw_system` and `20260919153500_giveaway_draw_population` for draw history plus privacy-safe immutable population snapshots.
- Kept draw proof durable across later account/participation cleanup by snapshotting only entry id, pseudonymous user id and weight; IP/User-Agent and anti-abuse fingerprints are excluded.
- Made primary draw creation atomic under the giveaway row lock and database transaction; concurrent primary draws are also constrained by unique giveaway/sequence and parent-chain indexes.
- Restricted winner selection and redraw to backend-authoritative `giveaway.manage` and the closed giveaway lifecycle state.
- Added explicit redraw rules: an existing draw is required, a 10-500 byte public reason is mandatory, the old record remains immutable, all previous winners are excluded, and no-unused-participant redraws are rejected.
- Added central administration audit events for primary/redraw selection with proof metadata and previous/current winner references.
- Added durable `giveaway.winner` notification and best-effort `giveaway.winner_replaced` notification with proof-page navigation.
- Added native PHP management controls for primary draw and justified redraw plus authenticated `/giveaways/{giveawayId}/proof` audit/proof UI showing chain state, winner, seed, population hash, ticket and proof verification result.
- Added deterministic algorithm, atomic lock, permission, duplicate-primary, redraw exclusion/reason, notification and proof-chain regression tests plus `docs/architecture/giveaway-draw-system.md`.
- Feature commits: `f8c8cb4c7df7b3ab799aaee628e3ab4123bcff2f`, `060d6bc9fb111a548362dccb8d9df4a9d197ee13`, `9eb43463864dc8cf6d846b164c93ec8b355859ca`, `532a3cb7aae7ac579483a8233ed6a8cebdb81825`; test/docs hardening: `69f4097861d30a8374fe4fbe993f3b36ce943760`.
- GitHub Actions build run `35453970888`: success; strict-types, lint, PHPUnit on PHP 8.4/8.5, production dependency baseline and cPanel package checks passed.
- Database migration smoke run `35453970881`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `13.06 — Easter egg sistemi`.

## Completed in 13.04

- Added first-party giveaway participation with one durable row per user and weighted `entry_count`, making repeated submissions idempotent instead of granting extra chances.
- Added configurable eligibility policy for minimum account age, visible post count, verified/active account requirement, allowed roles, qualified inbound/outbound referral conditions, and per-network/per-device-signal duplicate limits.
- Added backend-authoritative `giveaway.enter` enforcement, owner self-entry blocking, account restriction checks, immutable eligibility after the giveaway start time, and serialized capacity/duplicate enforcement under a giveaway row lock.
- Added privacy-safe HMAC network/device signals using the installation registration fingerprint secret; raw IP addresses and User-Agent strings are not persisted in giveaway tables or audit snapshots.
- Added additive migration `20260919150000_giveaway_participation` with eligibility, eligible-role and entry tables, unique per-giveaway/user participation, fingerprint indexes and FK integrity.
- Added canonical role, visible-post and 13.02 referral context integration without creating a duplicate role, post or referral subsystem.
- Added native PHP participation UX: eligibility requirements/reasons on giveaway detail, CSRF-protected POST entry endpoint, success/failure feedback, and management controls for all 13.04 policy fields.
- Added central audit coverage for eligibility-policy mutations while keeping high-volume participant activity represented by its immutable participation record.
- Added regression coverage for eligibility rules, backend permissions, owner self-abuse, idempotency, duplicate-device detection, participant capacity and policy locking; added `docs/architecture/giveaway-participation.md`.
- Feature commits: `6887ec74b410614463d8d312af9a5bd023735d80`, `918830dd11ea743c00859fd6542bbb06e4b5ecd2`, `f8f4157125fa3c0029136424dd98395029b999e6`; test/docs commit: `53301fc1c5b7b552282baeb65e0028443b731c87`; bootstrap fix: `f39c749fffcfcb5e11a2610c1c1595eb26546240`.
- GitHub Actions build run `35448399439`: success; strict-types, lint, PHPUnit on supported PHP jobs and cPanel packaging passed.
- Database migration smoke run `35448399387`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `13.05 — Çekiliş kazanan seçimi ve şeffaflık`.

## Completed in 13.03

- Added a first-party giveaway domain with typed draft/scheduled/open/closed/cancelled lifecycle states, UTC start/end windows, descriptive prize data, participation terms, per-user entry allowance and optional maximum participant count.
- Added backend-authoritative `giveaway.view`, `giveaway.create` and `giveaway.manage` enforcement while keeping `giveaway.enter` reserved for 13.04.
- Enforced immutable ownership, owner/staff management boundaries, draft-only editing rules and deterministic scheduled → open → closed transitions.
- Added central audit coverage for human create/update/publish/cancel mutations and native search lifecycle updates through `giveaway.item`.
- Added native PHP `/giveaways`, `/giveaways/{giveawayId}` and CSRF-protected `/giveaways/manage` surfaces plus member navigation and permission-aware search scope integration.
- Added bounded lifecycle read-repair for the cPanel minimum profile and optional one-minute `giveaway.lifecycle` maintenance scheduling for advanced deployments.
- Added additive migration `20260919143000_giveaway_domain` with conservative permission-template defaults and search/discovery registration.
- Kept 13.04 eligibility/entry anti-abuse, 13.05 cryptographic winner selection and 13.08 shared reward fulfillment outside the 13.03 boundary.
- Added permission/lifecycle/scheduler/navigation regression coverage plus `docs/architecture/giveaway-system.md`.
- Feature commits: `d7e66fad0d5272e9950e1b56a39d2485dd234de8`, `560d6c90cec37066cc20175d24491dc961d9496c`; hardening/fix commits: `7f91334ba68e3c6fa4e41d731231daf153e5f606`, `31e2d2d28e564d3ccee688631ed2baa46796ab84`.
- GitHub Actions build run `35447590221`: success; strict-types, lint, PHPUnit on PHP 8.4/8.5, production dependency baseline and cPanel package checks passed.
- Database migration smoke run `35447590228`: success on MySQL 8.4 and MariaDB 10.11.
- Next: `13.04 — Çekiliş katılım/eligibility/anti-abuse`.

## Completed in 13.02

- Added a first-party referral campaign domain with active/start/end windows, attribution lifetime, qualification delay, duplicate-network/device thresholds, optional per-referrer caps and reward key/units.
- Kept security-sensitive registration invite gating separate from public referral attribution; existing `RegistrationInviteStore` remains authoritative for invite-only registration.
- Added cryptographically random per-user campaign links, click tracking, host-only referral capture cookie and same-origin redirect behavior without an open-redirect target.
- Added first-touch registration attribution after the successful account transaction using existing HMAC IP/device fingerprints; raw IP/user-agent values are not persisted by referral tables.
- Made referral attribution optional/graceful: malformed, expired or unavailable referral state cannot invalidate an otherwise successful registration.
- Added self-referral rejection, duplicate privacy-safe fingerprint review, active-account qualification, pending-account deferral, restricted-account rejection and optional referrer qualification caps.
- Added idempotent referral reward ledger persistence; common cross-system reward fulfillment remains intentionally assigned to 13.08.
- Added native member `/account/referrals`, staff `/referrals/manage` and public `/ref/{code}` surfaces with backend permission checks and dedicated CSRF protection for mutations.
- Added central audit coverage for campaign mutations and staff attribution decisions plus existing notification infrastructure for successful qualification.
- Added bounded manual qualification for cPanel deployments and optional ten-minute `referral.qualify` scheduler/queue integration for advanced deployments.
- Added additive migration `20260919140000_referral_system` for campaigns, links, clicks, attributions and rewards with conservative permission-template defaults.
- Added registration, anti-fraud, lifecycle/idempotency and maintenance-schedule regression coverage plus `docs/architecture/referral-system.md`.
- Feature commits: `91903f0941b0c348f2f0722b7e6029fe5b27e8f2`, `93c5266430c056fd3dbc4126b48b0526f2d0c5eb`, `acc5d44df732c45de2017bae41fa81f187b1a1ec`, `dfa06f8d98d5cf3e49150a7b1c74b2eb9074b5dc`, `37a9dd847bb3bad0cc8ad7530c2db781f7436fa9`; test/docs hardening: `9be41d11a9c855968c1fac180d4c55eafa13a3c5`, `8f604b7ad2596142c2507fd7b72715729335e81b`.
- GitHub Actions build run `35439089180`: success; strict-types, lint, PHPUnit PHP 8.4/8.5, production dependency baseline and cPanel package checks passed.
- Database migration smoke run `35439089182`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `13.03 — Çekiliş sistemi domain`.

## Completed in 13.01

- Added a first-party portfolio domain with projects, categories, tags, typed lifecycle states, comments, reactions, featured projects and per-project history.
- Added backend-authoritative `portfolio.*` permissions and conservative defaults for members, moderators and administrators.
- Added a native PHP portfolio index, project detail, project management and profile portfolio tab while keeping backend permission checks authoritative.
- Added public portfolio navigation and global-discovery/search integration through `portfolio.item`.
- Integrated project text and comments with the shared validation → spam → spellcheck → AI moderation → moderation policy pipeline, preserving graceful pass-through when optional AI is unavailable.
- Integrated portfolio projects/comments with the common moderation approval queue; staff approval/rejection is audited and project search index changes are synchronized.
- Added secure image media handling through the common attachment inspection policy: MIME/signature validation, pixel limits, metadata sanitation, private storage, integrity verification and actor-bound project authorization.
- Added migrations `20260919130000_portfolio_system` and `20260919133000_portfolio_media_storage` with existing-user profile-tab seeding and additive upgrade compatibility.
- Added regression coverage for domain validation, unsafe media paths, migration registration, navigation/search integration, backend edit authorization, publish-to-review behavior, featured authority and self-reaction prevention.
- Feature commits: `c943bfd2a666f2be9aed6962b306cb4c7557dabb`, `9eb476320f94304b652c0ab0191cc902d5e4c25b`, `9e02b3c9dea6da24944a115d104694bbc413aaba`; hardening/completion commit: `0eb61484c67e611b0676064848431b5cb0025438`.
- GitHub Actions build run `35438353652`: success; strict-types, PHP lint, PHPUnit PHP 8.4/8.5, production dependency baseline and cPanel package checks passed.
- Database migration smoke run `35438353632`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `13.02 — Davet/referans sistemi`.

## Completed in 12.08

- Defined and verified the minimum-runtime graceful-degradation contract for optional AI, worker and external-search services.
- Confirmed the normal forum content pipeline persists without an AI service and without any long-running worker runtime.
- Hardened stale AI configuration: removed/unregistered providers now return `provider_unavailable` and removed prompt versions return `configuration_error` instead of crashing the content request.
- Preserved moderation safety during AI failures: any AI operational/configuration fallback is mapped to human-review `queue`, never an automatic allow or reject caused solely by provider availability.
- Added `ResilientSearchDriver` for advanced deployments: search queries fall back to the maintained native driver when the optional primary fails.
- Search index writes update the fallback first and still raise a retryable failure when the optional primary write fails, allowing `SearchIndexLifecycleService` backoff/retry without leaving native search stale.
- Routed the native web search runtime through `ResilientSearchDriver` with `NativeDatabaseSearchDriver` as the baseline fallback.
- Documented that worker absence delays asynchronous work rather than blocking ordinary forum read/write paths; content-manager/freshness bounded manual paths and durable search-change retries remain available.
- Added `docs/architecture/graceful-degradation.md` with supported minimum profile, failure matrix and explicit non-degradable boundaries such as database/permission failures.
- Added `OptionalServiceGracefulDegradationTest` covering AI-disabled persistence, missing provider, stale prompt, external-search query fallback and retryable index synchronization.
- No schema migration was required; 12.08 changes runtime failure behavior and regression coverage only.
- Feature/completion commit: `c9b18c331ed0fe4a5647c6d3f7a9055756246266`.
- GitHub Actions build run `35436742890`: success; strict-types, PHP lint, PHPUnit PHP 8.4/8.5, production dependency baseline and cPanel FULL build passed.
- Database migration smoke run `35436742879`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Main step 12 is complete.
- Next: `13.01 — Portfolyo sistemi`.

## Completed in 12.07

- Unified AI moderation, spellcheck, user content manager and thread freshness around the same backend-authoritative permission and core audit boundaries.
- Reconciled the stale catalog-only `freshness.*` permission aliases with the real node-scoped `forum.thread.freshness.*` keys used by the 12.06 runtime.
- Added idempotent migration `20260919110000_content_governance_integration`: non-conflicting legacy global/node rules are copied to canonical keys, existing canonical rules win, legacy template/global/node rules and definitions are removed, and verification fails closed if aliases remain.
- Added central audit coverage for AI moderation feedback, personal/site spellcheck dictionary mutation, content-manager enqueue, freshness policy saves, thread renewal, review resolution and human-triggered maintenance.
- Kept raw spellcheck dictionary words and AI feedback notes out of audit snapshots; spellcheck entries use SHA-256 fingerprints and existing audit redaction remains active.
- Added native HTTP request-id propagation into these audit events through `HttpAuditRequestId`.
- Made audited single-operation mutations use `AuditRecorder::mutate` so state and audit persistence share the same transaction where the underlying drivers support it.
- Hardened manual freshness maintenance against cross-forum permission escalation: only threads whose forum currently grants `forum.thread.freshness.review` to the actor may be mutated.
- Kept scheduled freshness maintenance as a system operation without inventing a fake user actor; durable freshness/review state remains its system record.
- Added focused migration compatibility, canonical permission, content-manager audit, spellcheck privacy, AI feedback permission/privacy and freshness node-scope regression coverage.
- Feature commit: `0e0a1d4b6fcd64678d54b8c3f2232f5a08a9e1e8`; hardening/completion commit: `d20173c7267cf633b5d3dad859945c184c5b39c3`.
- GitHub Actions build run `35436134573`: success; strict-types, PHP lint, PHPUnit PHP 8.4/8.5, production dependency baseline and cPanel FULL build passed.
- Database migration smoke run `35436134592`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `12.08 — Failure ve graceful degradation testleri`.

## Completed in 12.06

- Added per-forum thread freshness policies with stale, author-notification, auto-unfeature, auto-lock, moderator-review and auto-archive thresholds plus renewal cooldowns.
- Added an independent `last_activity_at_utc` freshness clock so automated lifecycle mutations do not make old topics appear current.
- Added `Güncelliğini yitirmiş` and `Arşivlenmiş` freshness states through `ThreadFreshnessSnapshot`.
- Added author renewal with cooldown enforcement and staff `renew_any` authority for bypass/reopen workflows.
- Preserved manual moderator locks: staff renewal only removes locks applied by freshness automation.
- Added first-party stale-topic author notifications with per-freshness-cycle dedupe.
- Added durable moderator-review cases with keep, renew/reopen and archive resolutions.
- Added automatic stale lifecycle maintenance for notification, unfeature, review, lock and archive in bounded batches.
- Added `thread.freshness.maintain` maintenance-queue task every 15 minutes plus a bounded native manual maintenance fallback for cPanel deployments.
- Made archive functional: archived threads leave normal forum listings/native search and reject new post creation.
- Added search lifecycle synchronization for archive/reopen transitions, including related posts.
- Added new-post freshness updates; new activity clears stale-cycle markers and resolves obsolete pending freshness review cases as `activity`.
- Added backend-authoritative `forum.thread.freshness.renew_own`, `renew_any`, `review` and `manage_policy` permissions with conservative template defaults.
- Added CSRF-protected native surfaces for thread freshness/renewal, moderator reviews and administrator per-forum policy management.
- Added additive idempotent migration `20260919100000_thread_freshness_system` with archive columns, policy/state/review tables and supporting indexes.
- Added architecture and regression coverage for policy validation, badges, maintenance actions, queue scheduling and freshness integration.
- Feature commit: `e291998b61de62e1189c52d5289bdb44144b598e`; hardening/completion commit: `614416b4b5743b08f10a45eb5ab26b6a03706384`.
- GitHub Actions hardening build run `35431788993`: success; strict-types, lint, PHPUnit PHP 8.4/8.5, production dependency baseline and cPanel FULL build passed.
- Database migration smoke run `35431789007`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `12.07 — Cross-system audit/permission integration`.

## Completed in 12.05

- Added a permission-gated user content inventory across forum threads and posts with user, content-type, forum, moderation-state, soft-delete and bounded text filters.
- Added dry-run previews with thread/post target counts before any mutation is created.
- Added immutable frozen target sets so later content creation or filter changes cannot expand a queued bulk operation.
- Added bounded bulk `delete`, `restore`, `move`, `approve`, `reindex` and `reprocess` actions with a 5,000-target safety limit.
- Reused existing moderation persistence/audit behavior for delete, restore, move and approve instead of adding parallel mutation rules.
- Added search lifecycle synchronization after mutations; thread changes also enqueue related posts so inherited visibility/forum scope remains consistent.
- Added `ContentPipeline::preprocess()` so reprocess runs validation, spam, spellcheck, AI-moderation and moderation-policy stages without persistence, notification or direct indexing side effects.
- Added durable operation/item progress with queued/running/completed/partial/failed operation states and pending/processing/succeeded/skipped/failed target states.
- Added `FOR UPDATE SKIP LOCKED` bounded claims and stale processing recovery for interrupted workers.
- Added `content.manager.execute` queue jobs plus a native bounded manual-processing fallback suitable for cPanel environments without a long-running worker.
- Added backend-authoritative `content_manager.access` and `content_manager.execute` defaults for moderator/administrator templates, including permission re-check immediately before queued execution.
- Added actor-bound operation detail access, preventing an operation id from becoming an authorization token.
- Added native CSRF-protected `/content-manager` and `/content-manager/operations/{operationId}` management/progress surfaces.
- Added additive idempotent migration `20260919090000_content_manager_system`.
- Added architecture and regression coverage for dry-run/frozen-target semantics, execute permission enforcement, move validation and pipeline preprocessing.
- Hardened UTF-8 excerpt truncation so list rendering cannot split a multibyte character.
- Feature commit: `2ecdc03e3cf089499ad9d719ab7bddd0c6965853`; hardening/completion commit: `2c5607f6e7e02366293cc13d63862155a0bce5d8`.
- GitHub Actions build run `35430548522`: success; strict-types, lint, PHPUnit PHP 8.4/8.5, production dependency baseline and cPanel FULL build passed.
- Database migration smoke run `35430548524`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `12.06 — Konu güncellik politikaları`.

## Completed in 12.04

- Added a provider-neutral spellcheck contract with normalized language tags, typed Unicode issue offsets and bounded suggestions.
- Added the conservative first-party `TurkishSpellcheckProvider` for common Turkish misspellings without treating arbitrary technical/domain vocabulary as incorrect.
- Added extensible provider registry coverage demonstrating additional language providers can be registered without editor/pipeline changes.
- Added database-backed per-user and site-wide dictionaries with Turkish-aware word normalization.
- Added backend-authoritative `spellcheck.use`, `spellcheck.dictionary.manage_own` and `spellcheck.dictionary.manage_site` defaults; site-wide dictionary management is administrator-only by default.
- Added authenticated `POST /editor/spellcheck` with private/no-store responses and server-side permission enforcement.
- Added CSRF-protected `GET|POST /account/spellcheck-dictionary` management surface.
- Added rich-editor **Yazımı denetle** action, marked issue context, selectable highlights and one-click suggestions with stale-result protection.
- Added advisory `SpellcheckPipelineProcessor`; spelling findings never rewrite or reject valid content automatically.
- Added graceful pass-through behavior when spellcheck is not configured or the actor lacks the use permission.
- Added additive idempotent migration `20260919080000_spellcheck_system`.
- Added architecture, domain, pipeline, editor-view and web-handler regression coverage.
- Feature commit: `9bf96503232a1a8e066c1bb38dc3ddfc3a4558cf`.
- GitHub Actions build run `35429688616`: success; strict-types, lint and PHPUnit passed on PHP 8.4 and PHP 8.5.
- Database migration smoke run `35429688617`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `12.05 — Kullanıcı içerik yöneticisi`.

## Completed in 12.03

- Added encrypted AI provider credential integration through the existing first-party encrypted secret store without placing API keys in normal configuration or policy tables.
- Added privacy redaction for common email, IP, Turkish mobile-number and token-like secrets before external AI requests while preserving fingerprints from original content.
- Added versioned prompt registry with `core.v1` and provider request propagation for Gemini, Anthropic, OpenRouter and custom endpoints.
- Added normalized provider token usage, configurable input/output per-million-token pricing and integer-micro cost calculation.
- Added durable per-forum AI policies for enabled state, provider, prompt version, redaction, moderation thresholds and pricing.
- Added forum-node propagation through thread/post content pipeline contexts so AI execution and moderation policy resolve the same forum policy.
- Added durable AI usage/cost metrics and false-positive/false-negative feedback storage.
- Added `ai.manage`-gated feedback service and database-backed forum-policy/metrics/feedback repositories.
- Added additive idempotent migration `20260919070000_ai_moderation_privacy_cost_policy`.
- Added architecture documentation and regression coverage for redaction, encrypted credential namespacing, prompt/provider selection, forum-scoped metrics and cost calculation.
- Feature commit: `c50d98bb703b04e5e0ff6de1b707d207b57274bd`.
- GitHub Actions build run `35428696597`: success; strict-types, lint and PHPUnit passed on PHP 8.4 and PHP 8.5.
- Database migration smoke run `35428696476`: success on MySQL 8.4 and MariaDB 10.11 including post-install web bootstrap.
- Next: `12.04 — Yazım denetim sistemi`.

## Completed in 12.02

- Replaced the 12.01 AI pass-through extension point with a provider-neutral moderation service and registry.
- Added first-party OpenAI, Gemini, Anthropic, OpenRouter and custom HTTPS provider implementations.
- Added normalized risk scores and `allow / flag / queue / reject` policy thresholds.
- Added deterministic timeout/provider-error fallback that routes content to human review instead of silently allowing it.
- Added exact-content human overrides with backend permission enforcement and persistent override/decision storage.
- Added hardened HTTPS endpoint validation and pinned transport protections against private/reserved network access and unsafe request targets.
- Added additive idempotent migration `20260918030000_ai_moderation_workflow` and `ai.moderation.override` permission defaults.
- Integrated AI moderation and policy processors into `ForumContentPipelineFactory` while keeping AI optional.
- Added provider, policy, fallback, override and persistence regression coverage.
- Feature commit: `4219669f7840a84e427ac15ac459937ba12d8770`.
- GitHub Actions build run `35428220289`: success.
- Database migration smoke run `35428220245`: success on MySQL 8.4 and MariaDB 10.11.

## Completed in 12.01

- Added the canonical `validation → spam → spellcheck → AI moderation → moderation policy → persist → notify → index` content pipeline contract.
- Added fail-fast stage registration so every pre-persist stage is present exactly once and persist/notify/index remain engine-owned.
- Added strict default UTF-8, control-character, empty-content and byte-limit validation.
- Adapted the existing first-party `AbuseEngine` into the spam stage with reject-before-persist and review propagation.
- Added after-persist abuse finalization so review events receive the real `forum.thread` / `forum.post` target id.
- Added explicit spellcheck and AI moderation pass-through extension points without implementing 12.04/12.02 behavior early.
- Added transactionally ordered persist → notify → index execution, with search-index change enqueue in the same transaction.
- Added `ForumContentPipelineFactory` backed by the existing durable search lifecycle queue.
- Integrated the pipeline with thread creation, first-post creation, replies and post edits while preserving existing permission/forum-state checks.
- Added regression coverage for canonical stage order, registry completeness, pre-persist validation rejection, transaction rollback, review behavior and search enqueue.
- No database migration or new permission key was required for 12.01.
- Feature commit: `c9379444d854798ffc104e66a0dade0cf90ef217`.
- GitHub Actions build run `35385211861`: success; strict-types and PHPUnit passed on PHP 8.4 and PHP 8.5, production dependency minimum was verified and the cPanel full package was built successfully.
- MySQL migration smoke run `35385211863`: success.
- Main step 12 remains active. Next: `12.02 — AI içerik denetimi` for provider abstraction, risk score, allow/flag/queue/reject, timeout fallback and human override.

## Completed in 11.05

- Added permission-gated `/bugs/staff` dashboard with title/summary search, status/severity/category filters and assignee/unassigned filtering.
- Added staff assignment/unassignment plus category, severity and lifecycle controls to the existing bug detail surface with backend authorization remaining authoritative.
- Added advisory duplicate similarity detection using bounded title/summary token overlap; suggestions never mutate report state automatically.
- Added explicit canonical duplicate linking and unlink/reopen workflow with self-link, duplicate-chain and lifecycle integrity checks.
- Added persisted `forwext_bug_report_duplicates` relation with source/canonical/actor foreign keys, indexes and historical actor deletion compatibility.
- Added staff summary/category analytics and filtered CSV export capped at 1,000 rows with spreadsheet formula-injection mitigation.
- Added centralized `bug` audit scope for reporter/staff replies, assignment, status, category, severity, duplicate link/unlink and export operations.
- Added `bug.report.export` and `bug.audit.view` permissions with moderator/administrator allow defaults and ordinary-member deny defaults.
- Added additive idempotent migration `20260918025000_bug_staff_workflow`.
- Added regression coverage for duplicate ranking/no-auto-mutation, explicit link/unlink lifecycle, atomic audit behavior, dashboard XSS escaping and CSV safety.
- Feature commit: `f23cafca1bd05a3662a90992cade35c2383f9838`.
- GitHub Actions build run `35381543107`: success; strict-types and PHPUnit passed on PHP 8.4 and PHP 8.5, and the cPanel full package was built successfully.
- MySQL migration smoke run `35381543138`: success.
- Main step 11 is complete. Next: `12.01 — Ortak content pipeline` with the binding order validation → spam → spellcheck → AI moderation → moderation policy → persist → notify → index.

## Completed in 11.04

- Added authenticated `/bugs` **Hata Bildirimlerim** list with own-report-only retrieval, category, status, severity, creation/update dates and permission-aware detail links.
- Added member navigation access to **Hata Bildirimlerim** while preserving the icon-only global **Hata bildir** entry.
- Added permission-aware `GET|POST /bugs/{reportId}` detail with original intake, private attachments, public reporter/staff responses and visibility-filtered workflow history.
- Added `bug.report.reply_own` for reporter follow-up information and `bug.report.reply_all` for staff public responses.
- Added append-only bug-report conversation persistence with nullable historical authors.
- Added durable first-party notifications for staff replies, reporter follow-up to the current assignee and real status transitions.
- Added report-authorized private attachment downloads with persisted byte-size and SHA-256 integrity verification.
- Added additive idempotent migration `20260918024000_bug_report_conversation` and safe built-in permission-template defaults.
- Added regression coverage for cross-account IDOR denial, reply/status notification hooks, XSS escaping and authenticated navigation visibility.
- Feature commit: `717b6873e351f0e560eb5dd214eefc8aa08ea6fc`.
- GitHub Actions build run `35378143632`: success.
- MySQL migration smoke run `35378143698`: success.
- Duplicate detection/linking, staff bug dashboard, assignment UI, filters, search, analytics, export and centralized bug audit remain scoped to 11.05.

## Completed in 11.03

- Added authenticated CSRF-protected native `GET|POST /bugs/report` submission UX.
- Added reproduction steps, expected result and actual result fields with backend length validation.
- Added a global authenticated **Hata bildir** action to the shared native page shell so bug reporting is reachable from every first-party page using that shell.
- Added a first-party client enhancer that propagates only `window.location.pathname`; query strings/fragments are never copied into bug-report source context.
- Kept the 11.02 server-derived diagnostic record authoritative and stored the browser/user supplied source path separately as convenience metadata.
- Added screenshot/file upload using verified PHP upload sources, signature-based attachment inspection, image safety checks/metadata sanitation and private storage.
- Added a maximum of five attachments per report under the shared 25 MiB per-file policy.
- Added storage compensation so private objects already written during a failed SQL submission are removed.
- Added additive idempotent migration `20260918023000_bug_report_form_intake` for reproduction/expected/actual/source intake plus attachment metadata.
- Added regression coverage for atomic workflow+diagnostic+intake+attachment persistence, source-path privacy, form escaping and global authenticated bug-report access.
- Feature commit: `b1a064a24eabdad35dde42958f08dfb8f3c50664`.
- UX correction commit: `9bf0208f03623c6a1af9038467876f36b69c4461` keeps the global entry icon-only while retaining accessible labelling.
- GitHub Actions build run `35376839885`: success.
- MySQL migration smoke run `35376839901`: success.
- Reporter-facing list/detail/responses/additional-info/notification tracking remains scoped to 11.04; duplicate/staff dashboard/search/analytics/export/audit remains 11.05.

## Completed in 11.02

- Added privacy-safe automatic bug diagnostic context capture for query-free URL path, matched route, authenticated user id, forum/thread/post route ids, theme/module, browser/OS/device summary and request-id.
- Raw query strings, request bodies, cookies, IP addresses and User-Agent text are deliberately not persisted.
- Reused the existing secret-backed authentication fingerprint engine for HMAC User-Agent fingerprints.
- Added bounded browser/device classification so oversized User-Agent headers cannot block bug reporting.
- Added one-to-one diagnostic persistence with report/user foreign keys and indexes for route/module/entity/request/client correlation.
- Added `BugReportSubmissionService` so bug report creation, creation history and diagnostic persistence share one database transaction and participate safely in an already-open transaction.
- Added additive idempotent migration `20260918022000_bug_diagnostic_context`.
- Added regression coverage for query-secret removal, canonical route entity extraction, theme/module fallback, request-id, browser/device classification and atomic submission.
- Feature commit: `a6c3ba384187cd6258aeb77020dae1029d8c1694`.
- GitHub Actions build run `35375056105`: success.
- MySQL migration smoke run `35375056111`: success.
- Page-level form, reproduction steps, expected/actual fields and screenshots/files remain scoped to 11.03.

## Completed in 11.01

- Added typed bug-report severity, configurable categories and `new/in_review/resolved/rejected/duplicate` lifecycle states.
- Added immutable bug-report aggregate with reporter, optional assignee, terminal finalized timestamp, UTC timestamps and optimistic-lock version.
- Added permission-aware create/own/all access plus category, severity, lifecycle and assignment operations.
- Added dedicated `bug.report.assign` permission and assignee validation against `bug.report.view_all`.
- Enforced backend IDOR/BOLA protection for single-report/history access and all staff mutations.
- Added append-only public/staff tracking history for creation, status, assignment, severity and category changes.
- Added additive idempotent migration `20260918021000_bug_report_workflow` with categories, reports, history, indexes, FKs and safe built-in permission defaults.
- Feature commit: `48c7c8ef5f84e89ab6e239676872bfda65a482bd`.
- GitHub Actions build run `35374163970`: success.
- MySQL migration smoke run `35374163988`: success.
- Automatic diagnostic context remains 11.02; form/attachments 11.03; reporter UI 11.04; duplicate detection/dashboard/search/export/audit 11.05.

## Completed in 10.06

- Added a permission-aware user `/support/tickets` "Taleplerim" surface backed by the existing requester-scoped support repository.
- Added a staff support dashboard with active queue, SLA/response-time summary metrics and per-category aggregate reporting.
- Added support reporting DTO/repository/service boundaries so UI rendering does not embed raw reporting SQL.
- Added response-time and resolution-SLA calculations from persisted ticket timestamps without background workers.
- Added category totals/open/resolved/SLA-breach metrics for operational reporting.
- Added support audit entries for ticket create/reply/note/assignment/status/escalation/merge/split/canned-response and FAQ-draft workflow mutations.
- Integrated support audit with the shared first-party audit scope rather than creating an unrelated logging silo.
- Added granular support reporting/audit permissions and safe built-in template defaults.
- Added native `/support/staff` dashboard and linked ticket flows while preserving backend authorization as the source of truth.
- Added additive idempotent migration `20260918016000_support_reporting_audit`.
- Added regression coverage for reporting calculations, permission boundaries, HTML escaping and support audit recording.
- Feature commit: `3d95c790037c60bbe4f17311c14262c5c7be8d16`.
- GitHub Actions build run `35369479835`: success.
- MySQL migration smoke run `35369479951`: success.
- Main roadmap step 10 is now complete; work advances to 11.01.

## Completed in 10.05

- Added bounded category/tag/question/answer FAQ recommendation candidate discovery for support flows.
- Recommendation SQL only narrows candidates; every result is re-authorized through the existing FAQ service so private/member/staff visibility cannot leak.
- Added FAQ suggestions during ticket creation and prefilled the support subject from the recommendation query.
- Added related FAQ guidance to resolved/closed ticket detail pages using ticket category + subject + original description.
- Added granular `support.faq_draft.suggest` permission with safe built-in template defaults.
- Only public staff replies belonging to the same ticket can become FAQ draft suggestions; requester messages/internal notes/cross-ticket ids are rejected.
- Added `/faq/manage/support-drafts` review workflow. FAQ managers may reject a suggestion or convert it into an inactive, staff-visible normal FAQ article draft.
- Draft application and FAQ article creation share one DB transaction; applying a suggestion never auto-publishes it.
- Added additive idempotent migration `20260918015000_faq_support_bridge` with source-message uniqueness and ticket/message/user/category foreign keys.
- Added XSS/visibility/draft-application regression coverage.
- Feature commit: `d0d8f5b0b64f45576ac1722b79bcd51e684991d1`.
- GitHub Actions build run `35368232044`: success.
- MySQL migration smoke run `35368232074`: success.
- My Tickets, staff dashboard, SLA/response-time/category reporting and support audit remain scoped to 10.06.

## Completed in 10.04

- Added typed FAQ categories/articles with language, ordering, public/member/staff visibility and active state.
- Added article tags, unique language-local SEO slugs and optional SEO title/description.
- Effective FAQ visibility always uses the stricter category/article level and is enforced consistently in page access and search indexing.
- Added authenticated one-vote-per-user helpful analytics with aggregate vote count/helpful ratio and no user identities in export.
- Added versioned, permission-gated JSON import/export with domain validation, bounded payload limits and atomic persistence.
- Added native `faq.article` search content source, server-derived `faq.members`/`faq.staff` scopes and permission-aware global-search result redirects.
- Category policy changes queue all affected existing articles for search re-indexing, preventing stale visibility scopes.
- Added public `/faq`, canonical `/faq/{language}/{slug}`, id redirect and `/faq/manage` native surfaces with dedicated CSRF scope and escaped output.
- Added FAQPage/CollectionPage SEO metadata plus public FAQ sitemap and RSS/Atom discovery.
- Added public SSS main-navigation entry.
- Added additive idempotent migration `20260918014000_faq_system` with FAQ tables, indexes, FKs and built-in permission defaults.
- Feature commit: `89346cc833153eba142689f51f984cd4ecd406fc`.
- GitHub Actions build run `35364781069`: success.
- MySQL migration smoke run `35364780993`: success.
- Ticket-to-FAQ recommendations, post-resolution FAQ guidance and staff-reply-to-draft workflow remain scoped to 10.05.

## Completed in 10.03

- Added append-only requester/staff public ticket conversation and staff-only internal notes.
- Added granular support permissions for staff replies, internal notes, assignment, escalation, merge, split and canned-response administration while preserving the shared permission engine as authority.
- Added reusable canned responses with key/title snapshots on historical messages.
- Added first-response SLA recording on the first real staff public reply and automatic resolved-to-open reopening when conversation resumes.
- Added append-only public/staff workflow history for ticket creation, status, assignment, escalation, merge, split and first response.
- Added monotonic escalation levels 1-5 with a database-level concurrent downgrade guard.
- Added non-destructive same-requester merge: source closes, source conversation is preserved, messages are copied to target with provenance and partial/truncated merge is refused at the bounded safety ceiling.
- Added public-message-only split; internal notes cannot leak into newly requester-visible tickets.
- Added durable first-party notifications for staff replies, requester replies to current assignee, assignment, status changes and split-created tickets.
- Added native CSRF-protected `/support/tickets/{ticketId}` conversation/staff-tools surface with server-side username assignment resolution.
- Added ticket-authorized private support attachment downloads with persisted byte-size/SHA-256 integrity verification.
- Added additive idempotent migration `20260918013000_support_conversation_tools`, seven granular support permissions and built-in template defaults.
- Feature commit: `1ca50f71b63da3d187aeb51089595fbbe022f3d9`.
- GitHub Actions build run `35362580409`: success.
- MySQL migration smoke run `35362580529`: success.
- FAQ content/recommendation remains 10.04-10.05; My Tickets/staff dashboard/SLA reporting/support audit remains 10.06.

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
