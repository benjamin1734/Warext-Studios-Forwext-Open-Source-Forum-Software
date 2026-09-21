# Privacy-Aware Analytics Event Model (15.01)

## Scope

Forwext 15.01 introduces the shared first-party analytics event registry and persistence boundary used by later reporting and business-intelligence steps.

The event layer is intentionally not a second audit log and not a copy of authoritative financial/domain records. It captures bounded behavioral and conversion signals while later dashboards continue to read exact state such as GMV, order totals, moderation records and SLA timestamps from their owning domain tables.

## Core event categories

The registry covers every domain required by roadmap step 15.01:

- forum;
- user;
- content;
- support;
- bug;
- marketplace;
- referral;
- giveaway;
- moderation.

Core definitions include forum views/search, registration/login/activity, thread/post/reaction events, support and bug lifecycle events, Marketplace listing/order/purchase/review events, referral attribution/reward events, giveaway entry/win events and moderation report/action/warning events.

Each event definition declares:

- stable event key;
- category;
- retention period;
- whether actor identity may be collected;
- whether session identity may be collected;
- whether a subject identity may be collected;
- whether forum identity may be collected;
- exact allowlisted dimension keys.

Unknown events and duplicate registry keys are rejected.

## Privacy boundary

Raw personal identifiers are not persisted in `forwext_analytics_events`.

User ids, session ids and subject entity ids are converted to deterministic HMAC-SHA256 hashes before persistence. The analytics HMAC key is a domain-separated subkey derived from the installation master key (`forwext.analytics.privacy.v1`).

The event table does not contain raw IP address, user-agent, email, request URI, query string or raw user-id fields.

Forum ids are allowed as structural site dimensions because later permission-aware forum reporting needs to group by forum/node. Analytics read surfaces introduced in later steps must still enforce forum/site analytics permissions.

## Dimension policy

Dimensions are not arbitrary JSON payloads.

Every key must be allowlisted by the event definition. String values must be short token-like values and cannot contain free-form text, URLs/e-mail-like content or raw IP addresses. This keeps analytics payloads useful for grouping without becoming a side channel for post bodies, names, e-mail addresses, URLs or request data.

Examples of intended dimensions are `device`, `route`, `moderation_state`, `currency`, `campaign`, `reward_type` and `action`.

## Persistence

Migration `20260921230000_analytics_event_model` creates `forwext_analytics_events` with:

- event id/key/category;
- optional actor/session HMAC hashes;
- optional subject type and subject HMAC hash;
- optional forum id;
- allowlisted dimensions JSON;
- occurrence UTC timestamp/day;
- recorded UTC timestamp.

Indexes support event/day, category/day, forum/day, actor/day, subject/day and retention scans.

Financial amounts are deliberately not duplicated into this generic event store. Later Marketplace/revenue reports use authoritative Marketplace order/payment data and may join or correlate it with conversion events.

## Runtime producers

The native web runtime currently records two foundational request events after a successful HTML GET response:

- `user.active` for authenticated users;
- `forum.view` when the route resolves to a forum directly or through a thread.

The existing browser/device classifier is reused and only its bounded device class is recorded. Authentication session cookie content is passed only to the privacy hasher and is never stored raw.

Runtime recording is best-effort: analytics storage failure or a pending migration cannot turn an otherwise healthy forum page into a 500 response.

Additional domain services use the same registry/recorder contract as their reporting steps are implemented; there is no separate ad-hoc analytics schema per module.

## Retention

Retention is part of each event definition rather than a single unlimited global lifetime.

`AnalyticsMaintenanceTasks` registers the daily `analytics.retention.prune` maintenance task. `AnalyticsRetentionJobHandler` executes bounded deletion per event key using each definition's retention policy. The task uses the existing Forwext maintenance queue/scheduler profile and does not introduce Redis as a minimum cPanel requirement.

## Security and access

15.01 exposes no public raw-event read endpoint.

Existing analytics permissions (`analytics.view_own`, `analytics.view_forum`, `analytics.view_site`, `analytics.export`) remain the authorization vocabulary for the dashboards/report builder introduced by later 15.x steps.

Because the event model is append-oriented analytics rather than an administrative mutation surface, event collection itself does not require an end-user management permission. Read/export authorization is enforced at reporting boundaries.

## Failure and upgrade behavior

The analytics request middleware uses best-effort writes. A code deployment that reaches the web tier before its migration completes therefore continues serving forum pages while event writes are temporarily skipped.

The migration is additive and does not reset or rewrite users, forums, posts, Marketplace orders, moderation records or previous subsystem analytics.
