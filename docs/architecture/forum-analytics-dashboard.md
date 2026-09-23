# Forum Analytics Dashboard (15.02)

## Scope

Forwext 15.02 adds a first-party site analytics dashboard on top of the privacy-aware event model introduced in 15.01.

The dashboard is available at `/admin/analytics` and is protected by backend `analytics.view_site` authorization. It supports fixed 7, 30 and 90 day windows.

## Source-of-truth policy

The dashboard intentionally does not treat the generic event store as the source of truth for all metrics.

Authoritative domain tables provide exact state/count metrics:

- active account count: `forwext_users`;
- registrations: `forwext_users.created_at_utc`;
- visible/non-deleted/non-merged thread counts: `forwext_threads`;
- visible/non-deleted post counts: `forwext_posts` joined to eligible threads;
- current online count: `forwext_user_presence`.

The privacy-aware analytics event table provides behavioral metrics that require distinct-user activity across time:

- DAU;
- MAU;
- daily active-user trend;
- 7-day activity retention;
- 30-day activity retention;
- 24-hour and 7-day online-activity peak buckets.

This split prevents temporary analytics-event loss from changing authoritative registration/thread/post totals.

## Activity identity and privacy

DAU/MAU/retention/peak calculations use only the pseudonymous `actor_hash` produced by 15.01. Raw user ids, e-mail addresses, IP addresses, request URLs and user-agent strings are not queried or exposed by the dashboard.

`user.active` is produced through successful HTML GET activity and the presence heartbeat path. The heartbeat uses the same installation-specific `forwext.analytics.privacy.v1` HMAC key as the main web runtime.

## Metric definitions

- DAU: distinct pseudonymous actors with `user.active` during the current UTC day.
- MAU: distinct pseudonymous actors with `user.active` during the current UTC day plus the previous 29 UTC days.
- Current online: active accounts whose presence was updated in the last 300 seconds.
- 24h / 7d peak: maximum distinct pseudonymous actors observed in a five-minute `user.active` bucket in the requested historical horizon.
- 7d activity retention: actors active exactly seven UTC calendar days ago who are also active in the current UTC day.
- 30d activity retention: actors active exactly thirty UTC calendar days ago who are also active in the current UTC day.

Retention is therefore an activity-retention metric, not registration-cohort retention. The UI labels it explicitly to avoid ambiguity.

## Growth windows

For the selected 7/30/90-day range, registration, thread and post counts are compared against the immediately preceding equal-length calendar window.

When the previous window has zero events:

- current=0 returns 0% growth;
- current>0 is shown as a new baseline instead of an infinite percentage.

## Daily table

The dashboard renders one row for every UTC day in the selected window, including zero-activity days. Each row contains:

- registrations;
- visible threads created;
- visible posts created;
- distinct active users.

## Query/index profile

Migration `20260921231000_forum_analytics_dashboard` adds only query-support indexes required by the dashboard:

- user creation time;
- thread creation/state;
- post creation/state;
- presence last-seen;
- analytics `user.active` time/actor access.

The migration is additive, idempotent and works on the supported MySQL/MariaDB profile. It does not create summary tables or require Redis, Elasticsearch, background BI services or Node.js.

## Permission defaults

`analytics.view_site` already belongs to the first-party permission catalog.

15.02 seeds conservative template defaults:

- administrator: allow;
- moderator/member/verified/new_user: deny.

Explicit permission customization remains possible through the shared permission engine.

## HTTP/security behavior

`/admin/analytics` is GET-only. It accepts only `days=7`, `days=30` or `days=90`; unsupported ranges return HTTP 400.

Unauthenticated requests return HTTP 401 and unauthorized authenticated users return HTTP 403.

Responses use `private, no-store` and `X-Robots-Tag: noindex,nofollow`.

Because the page performs no mutation, it does not require CSRF middleware.

## Relationship to later analytics roadmap

15.02 covers forum-level operational/growth KPIs only.

- 15.03 adds content/engagement analysis.
- 15.04 adds moderation/support/bug analysis.
- 15.05 adds Marketplace/revenue/referral/giveaway analysis.
- 15.06 adds report builder/export/access aggregation.
