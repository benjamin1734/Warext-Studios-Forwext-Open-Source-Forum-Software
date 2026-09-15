# Draft, Read, Watch and Subscription State

Roadmap step 06.06 adds per-user discussion state without introducing parallel thread or post identities. Drafts, read watermarks, watched forums/threads and subscription preferences all reuse the existing forum hierarchy, thread/post persistence and actor-bound permission gate.

## Autosave drafts

Drafts are keyed by authenticated user plus target type/id:

- `new_thread` targets a forum node and may contain title + body source;
- `reply` targets a thread and contains body source only.

Draft content is source text only. Rendering/editor semantics remain owned by 06.07. Title and body are bounded to 200 and 100000 UTF-8 bytes respectively and reject unsafe control characters. Empty content is valid because an autosave may occur before the user has typed anything.

Each draft has a monotonically increasing revision. `DatabaseDiscussionStateRepository::saveDraft()` locks the current draft row with `FOR UPDATE` and requires the caller's expected revision to match. A stale browser tab therefore raises `DraftConflictException` instead of silently overwriting a newer autosave.

New-thread draft writes require normal forum visibility, `forum.thread.create` and a forum that currently permits new threads. Reply draft writes require normal forum visibility, `forum.post.create`, a visible/unlocked thread and forum reply policy.

## Read and unread tracking

Thread read state stores the greatest visible post position observed by a user. A mark-read request cannot point beyond the current latest non-deleted visible post. Upserts use `GREATEST`, so delayed/out-of-order client requests cannot move a user's read position or timestamp backwards.

Forum-level “mark read” stores a UTC watermark. Thread unread evaluation combines:

1. latest visible post position;
2. the user's thread read position;
3. the forum-level mark-read timestamp;
4. the thread update timestamp.

A thread is not unread when no visible post exists, the thread watermark has reached the latest visible position, or the forum was marked read at/after the thread's current update timestamp.

The public state service evaluates forum visibility before read/write operations and treats non-visible threads as unavailable for public unread state.

## Watched threads and forums

Watch records are per authenticated user and reference existing thread/forum identities. Supported notification preferences are:

- `none` — represented by deleting the watch row;
- `in_app`;
- `email`;
- `in_app_email`.

`none` is intentionally not persisted as a positive subscription. Repeated watch updates are idempotent upserts and unwatch operations are safe when the row is already absent.

Watching a thread or forum requires normal forum visibility. Non-visible threads cannot be watched through the public discussion-state service.

Actual notification delivery belongs to the shared notification system in the later roadmap; 06.06 persists the authoritative subscription intent that delivery workers will consume.

## Subscription preferences

Each user may persist defaults for:

- automatically watching threads they create;
- automatically watching threads they reply to;
- default thread notification mode;
- default forum notification mode.

Missing preference rows use safe application defaults: created-thread auto-watch enabled, reply auto-watch disabled, and in-app notification mode for both thread and forum watches. The preference API never accepts a target user id: it always reads/writes state for the actor bound to `PermissionGate`, preventing IDOR-style preference mutation.

## Authorization and privacy

06.06 reuses existing permissions rather than creating a duplicate state-specific permission namespace:

- forum visibility uses `forum.view`;
- new-thread drafts use `forum.thread.create`;
- reply drafts use `forum.post.create`.

Read/watch/subscription records are private per-user state. Every application service call derives user identity from the authenticated actor; request data cannot nominate another user's state.

## Persistence

Migration `20260915235959_discussion_state` creates:

- `forwext_content_drafts`;
- `forwext_thread_read_state`;
- `forwext_forum_read_state`;
- `forwext_watched_threads`;
- `forwext_watched_forums`;
- `forwext_subscription_preferences`.

User deletion cascades all private state. Thread/forum deletion cascades their concrete read/watch rows. Draft targets are polymorphic, so target identity is application-validated; user deletion remains database-enforced. A later maintenance sweep may remove drafts whose target content was deleted, without changing draft ownership semantics.

## Deployment profile

The system uses existing PHP/MySQL transactions and requires no Redis, queue worker, WebSocket server, Node runtime or additional PHP extension. Database state is the cPanel-safe baseline; later realtime/notification surfaces consume the same persisted watch intent.

## Forward boundary

06.07 owns the rich editor, safe rendering and live character/word counters. Its autosave UI must call this draft boundary and pass the latest revision returned by the previous save rather than introducing a separate draft store. Later notification work must consume watched-thread/forum state and subscription preferences rather than inventing a second subscription model.
