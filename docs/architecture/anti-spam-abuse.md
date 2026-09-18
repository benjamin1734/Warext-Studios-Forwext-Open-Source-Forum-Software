# Anti-Spam / Abuse Architecture

Sub-step **09.05** implements Forwext's first-party flood, spam-cleanup and abuse-rule layer without introducing a second permission or moderation stack.

## Core model

The shared engine evaluates typed events:

- `registration`
- `thread`
- `post`

Rules are keyed by one privacy-safe signal:

- authenticated user
- canonical identity fingerprint
- IP fingerprint
- device/user-agent fingerprint
- normalized content fingerprint

A rule contains a stable key, event type, signal, allowed hit count, fixed-window size, action (`review` or `reject`), active state and priority.

Every event increments its matching rule counters. The most severe exceeded rule wins:

`allow < review < reject`

Normal allow decisions are not written into the event queue. This keeps the persistent moderation queue focused on meaningful abuse decisions instead of becoming a full activity log.

## Privacy boundary

Raw IP addresses, e-mail addresses and user-agent strings are not persisted by the anti-abuse tables.

Registration reuses the existing HMAC registration fingerprints. Authenticated posting can use `AbuseRequestContextFactory`, which reuses `AuthenticationFingerprint` to HMAC the current client IP and user agent before they reach `AbuseContext`.

Content similarity uses a one-way SHA-256 fingerprint of normalized title/body text. The original content continues to live only in its normal content table and is never duplicated in the abuse tables.

The database stores only the resulting 64-character fingerprints needed for rate windows, review correlation and moderator diagnostics.

## Fixed-window flood counters

`forwext_abuse_counters` is keyed by:

- rule key
- privacy-safe fingerprint
- deterministic UTC bucket start

Counter increments use a database transaction and an atomic insert-or-increment path. The subsequent read occurs in the same transaction. No Redis, daemon or worker is required for the minimum cPanel deployment.

Default rules provide conservative starting points for:

- registration IP review/reject;
- registration device review;
- thread user flood review/reject;
- thread device review;
- repeated thread-title review;
- post user flood review/reject;
- post device review;
- repeated post-content review.

Rules are editable and may be disabled. Idempotent migration re-runs do not overwrite administrator-edited rule values.

## Registration hook

`RegistrationService` keeps its existing registration-specific rate limit, CAPTCHA/Turnstile and disposable-email checks. The common abuse engine is an additional hook, not a replacement.

Before CAPTCHA/provider work, registration evaluates:

- canonical e-mail identity fingerprint;
- IP fingerprint;
- optional device/user-agent fingerprint.

A `reject` decision stops account creation before the transaction. A `review` decision forces the post-verification account state to `pending_approval`, even when public registration mode would otherwise activate the account.

A review event is attached to the newly created `user.account` target inside the account-creation transaction. A rejected pre-account attempt may have no target id and can still be reviewed/dismissed in the abuse surface.

## Thread and post hooks

`ThreadCreationService` and `PostService` accept an optional typed request context for IP/device signals. The services themselves always own actor and content fingerprints so callers cannot substitute another user's identity or a fake content digest.

When a rule returns `review`:

- a thread is persisted with the existing thread `pending` moderation state;
- a post is persisted with the existing post `pending` moderation state.

The item then appears in the shared 09.03 approval queue. No parallel spam moderation lifecycle exists.

When a rule returns `reject`, the operation is rejected before thread/post persistence.

## Moderation workspace and cleanup

`/moderation/abuse` provides the native PHP abuse surface and the main Moderation Workspace has a dedicated **Anti-spam** section.

Permissions:

- `moderation.abuse.view`
- `moderation.abuse.manage_rules`
- `moderation.abuse.cleanup`

All surfaces also inherit the existing `moderation.access` boundary.

Spam cleanup never issues direct delete SQL. Selected `forum.thread` and `forum.post` targets are passed to `ContentModerationService` using the existing bulk soft-delete operations. Therefore the acting moderator must still possess the normal forum view, bulk and delete permissions for every affected forum.

Cleanup runs inside one outer database transaction. Existing content-moderation transactions become savepoints, then abuse-event resolution and audit records are committed with the same outer transaction. A failure in any selected group rolls the complete cleanup back.

Events that are false positives or non-content signals can be dismissed without deleting content.

## Audit and security

Anti-abuse rule changes and event resolutions use the shared moderation audit stream and request-id correlation. Mutation routes reuse the same-origin moderation guard used by the existing moderation tools.

Native HTML escapes rule labels, target identifiers and rule names before rendering. Client-side controls are convenience only; every read/mutation permission is rechecked on the backend.

## Migration and deployment

Migration `20260918004000_abuse_prevention` creates:

- `forwext_abuse_rules`
- `forwext_abuse_counters`
- `forwext_abuse_events`

It also installs the default rules and three moderation permissions.

The migration is additive/idempotent, does not reset existing forum data and adds no mandatory Node.js, Redis, Docker, Supervisor or worker requirement.

## Retention maintenance

`AbuseMaintenanceTasks` registers a daily maintenance-queue job. It removes old fixed-window counter buckets and old **resolved** abuse events in bounded batches. Unresolved review events are never removed by retention cleanup.

The first-party defaults retain counter buckets for 8 days and resolved abuse events for 180 days. Operators can adjust the job payload within bounded validation ranges. The database queue/scheduler remains compatible with normal cPanel cron execution; a permanent daemon is not required.
