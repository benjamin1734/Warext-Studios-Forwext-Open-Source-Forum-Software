# Content and Engagement Analytics (15.03)

## Scope

Forwext 15.03 adds site-wide content/engagement analysis for forum, category and thread performance, reactions, views, watches, follows, search terms and conversion-like engagement tables.

The native PHP administration surface is `/admin/analytics/content`. It is protected by backend `analytics.view_site` authorization and uses the same fixed 7/30/90-day windows as the 15.02 forum analytics dashboard.

## Source-of-truth split

15.03 does not duplicate authoritative interaction state into generic analytics events.

Authoritative domain tables provide:

- reactions: `forwext_post_reactions`;
- bookmarks: `forwext_post_bookmarks`;
- watched threads: `forwext_watched_threads`;
- watched forums: `forwext_watched_forums`;
- follows: `forwext_user_follows`;
- forum/category/thread/post structural metadata: forum/thread/post domain tables.

The privacy-aware analytics event store provides behavioral view/search signals:

- forum views: `forum.view`;
- thread views: `content.thread.view`;
- search executions and privacy classes: `forum.search`.

## Structural content references

15.01 originally pseudonymized actor/session/subject identities and retained forum id as a structural dimension. 15.03 extends the event model with optional `content_type` + `content_id` structural references.

These references are not user identities. They exist so content performance can be joined efficiently to a thread/post/listing later without reversing a user HMAC.

Structural references are fail-closed:

- both type and id must be present together;
- event definitions must explicitly set `collectContent=true`;
- events without that declaration are rejected by the recorder and stored-event boundary.

`content.thread.view` is the first core event using this capability and records `content_type=thread` with the thread id.

## Thread view collection

After a successful HTML GET, the analytics request middleware resolves both the forum and thread route context.

For a thread page it records:

- `forum.view` for forum/category reporting;
- `content.thread.view` for thread reporting.

Authenticated actor/session identifiers follow the existing HMAC privacy boundary. Anonymous views may be counted without a user identity.

## Forum/category/thread performance

The content engagement repository builds bounded top tables for the selected UTC window.

Forum rows contain:

- threads created;
- posts created;
- reactions;
- views;
- forum-watch activity.

Category rows use a recursive node tree so nested categories aggregate all descendant forums rather than only direct children.

Thread rows contain:

- thread title and forum;
- total visible reply count;
- period views;
- period reactions;
- period bookmarks;
- period watch activity;
- reaction/view, bookmark/view and watch/view ratios.

These ratios are explicitly engagement proxies, not commerce conversions. A ratio may exceed 100% because multiple actions can occur per recorded view and the analytics view history begins only after the event model was introduced.

## Search-term privacy

`SearchTermPolicy` classifies queries before term-level aggregation.

A term can be stored in the trend aggregate only when it is short, valid UTF-8 and contains a restricted character set. The policy rejects identifier-like or sensitive formats including:

- e-mail addresses;
- HTTP(S)/www URLs;
- IP addresses;
- phone/long-number patterns;
- oversized or malformed strings.

All successful authenticated searches still record a privacy-safe `forum.search` event with only:

- `query_class` (`safe`, `redacted` or `short`);
- `result_bucket` (`zero`, `one_five`, `six_twenty`, `twenty_plus`).

Only safe terms are written to `forwext_search_term_analytics`. Their row key is an HMAC-SHA256 term key using a separate domain-derived installation key (`forwext.analytics.search-term.v1`). The aggregate stores daily search count, zero-result count, returned-result total and last-search timestamp.

Redacted/short query text is never written to that table.

## Search metric semantics

The overall search count and zero-result count come from `forum.search` events, so they include safe, redacted and short query classes.

The visible top-term table includes only safe aggregated terms. `avg_results` is the mean number of results returned on the rendered search page, not an estimate of every possible result in the index.

## Schema and indexes

Migration `20260921232000_content_engagement_analytics`:

- adds optional `content_type` / `content_id` to `forwext_analytics_events`;
- adds the content/day lookup index;
- creates `forwext_search_term_analytics`;
- adds time-window indexes for reactions, bookmarks, watched threads/forums and follows;
- adds reverse post-bookmark lookup needed for thread aggregation.

The migration is additive and idempotent and does not reset user/forum/content data.

## HTTP/security behavior

`/admin/analytics/content` is GET-only and accepts only `days=7`, `days=30` or `days=90`.

Unauthenticated requests return 401; authenticated users without `analytics.view_site` return 403. Successful responses are `private, no-store` and `noindex,nofollow`.

No raw user list, e-mail, IP, user-agent or raw session identifier is returned by the dashboard.

## CI concurrency safety

During 15.03 the GitHub Actions workflows were also hardened to collapse superseded normal `main` runs. Database/build runs from older feature commits are cancelled when a newer normal commit appears, while release commits and manual release runs remain protected.

Automatic release retry from an unrelated later commit was removed. A failed release publication is retried explicitly through `workflow_dispatch`, preventing a later feature commit from accidentally publishing an older VERSION.
