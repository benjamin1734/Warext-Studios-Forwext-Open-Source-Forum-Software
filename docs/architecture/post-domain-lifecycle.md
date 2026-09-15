# Post Domain and Message Lifecycle

Roadmap step 06.03 introduces real `Post` entities beneath the 06.02 thread domain. The normative first-post rule is explicit: the first message is not embedded in the thread row; it is a real post at `position = 1`.

## Identity, body and ordering

Posts use opaque 128-bit lowercase hexadecimal ids. `PostBody` stores bounded source text (1–100000 bytes) and rejects unsafe control characters. Rendering/editor markup semantics remain deferred to 06.08.

Each post has an immutable positive thread-local `position`. `DatabasePostRepository::create()` locks the owning thread row before reading the maximum position, then inserts `MAX(position)+1`. The unique `(thread_id, position)` database index provides a second integrity boundary. First-post creation requires the current maximum to be zero; reply creation requires a first post to already exist.

## First-post publication

`PostService::createFirstPost()` requires the authenticated actor to be the thread author and requires both node visibility authorization and `forum.post.create`. A thread awaiting moderation produces a pending first post even if the forum's post-approval switch is otherwise disabled.

`ThreadPublishingService` is the canonical production composition for creating a thread and its first post. It wraps `ThreadCreationService` and `PostService::createFirstPost()` in one outer database transaction. The existing repository transactions become nested/savepoint operations under that boundary, so a failed first-post insert can roll back the thread insert instead of leaving a published empty thread.

A first post cannot be removed with ordinary `delete_own`; deleting position 1 requires staff-style `delete_any` authority. Replies are rejected while the first post is missing, deleted or non-visible.

## Lifecycle and history

A post owns:

- body source;
- moderation state: `visible`, `pending`, `rejected`;
- soft-deleted state and deletion timestamp;
- immutable thread/author/position identity;
- UTC created/updated timestamps;
- optimistic version.

Editing, deleting, restoring and moderation transitions snapshot the previous persisted state into `forwext_post_history`. Restore does not force a moderation state change: restoring a rejected post leaves it rejected until a separate moderation decision occurs.

`DatabasePostRepository::save()` updates the post with compare-and-swap versioning and appends pending history in the same transaction. A stale writer raises `PostConcurrencyException`; history cannot be committed for an update that loses the version race.

## Reply rules

A normal reply requires:

1. an existing thread and forum;
2. resolvable forum hierarchy and node-scoped `forum.view`;
3. node-scoped `forum.post.create`;
4. visible thread moderation state;
5. unlocked thread;
6. forum `allowReplies = true`;
7. registered thread type with `allowsReplies = true`;
8. an available, visible, non-deleted first post.

Forum `requirePostApproval` creates new replies in pending state.

## Edit/delete/restore/moderation permissions

06.03 adds:

- `forum.post.edit_own`;
- `forum.post.edit_any`;
- `forum.post.delete_own`;
- `forum.post.delete_any`;
- `forum.post.restore`;
- `forum.post.moderate`.

Ownership is checked against the actor-bound permission gate; a target user id from request data never supplies the actor. Own-edit/delete can fall back to explicit any-content authority for staff. Restore and moderation always require their dedicated permissions.

All five built-in starter profiles own all six keys, preventing privilege residue during template changes. New-user/member/verified profiles allow own edit/delete but deny any-content/restore/moderate. Moderator/administrator profiles allow all six.

## Counters and pagination

06.03 deliberately avoids denormalized post counters that could drift from lifecycle state. `PostCounters` is derived from authoritative post rows:

- `active` = non-deleted posts;
- `visible` = non-deleted posts in visible moderation state.

This makes edit/delete/restore/approve/reject counter correctness automatic. Later performance qualification may introduce cached/denormalized counters only with rebuild and integrity mechanisms.

`pageByThread()` provides bounded page/per-page input, deterministic ascending thread position order and visibility filters. Ordinary callers exclude deleted and non-visible posts by default; staff callers can explicitly request broader result sets after authorization.

## Persistence

Migration `20260915235955_post_domain` creates:

- `forwext_posts` with thread-local unique position, soft-delete/moderation state and optimistic version;
- `forwext_post_history` with prior body/state/deletion snapshots and actor/time/action metadata;
- six post-management permission definitions;
- 30 built-in starter-template rules.

Thread deletion cascades posts; account deletion sets post/history actors to null while retaining durable community content and history. The migration is registered after the thread-domain migration and is required by installer-registry tests.

## Forward boundary

06.04 adds prefix/tag/custom-field configuration. 06.05 adds polls. 06.06 adds draft/read/watch/unread behavior. 06.08 owns editor/rendering rules. Those systems must reuse `Post`, thread-local positions, history and the common node-scoped permission boundary rather than creating parallel message identities or authorization paths.
