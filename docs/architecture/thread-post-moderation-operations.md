# Thread/Post Moderation Operations (06.08)

## Scope

06.08 completes the forum content operation layer required by the roadmap: thread move/copy/merge/split, lock/sticky/approve/delete/restore, post approve/delete/restore and bounded bulk actions. Every operation is backend-authorized through the shared permission engine and every successful mutation writes an append-only moderation audit event in the same database transaction.

This is the forum-content audit boundary for 06.08. It intentionally does not claim completion of roadmap 09.06, which later generalizes the central moderator/admin audit stream across all protected systems, or 09.07, which adds the separate verifiable/hash-chained independent audit system.

## Authorization

The authenticated actor comes only from `PermissionGate`; public operation methods do not accept a target actor/user id.

Existing granular permissions remain authoritative where they already exist:

- `forum.thread.lock`
- `forum.thread.sticky`
- `forum.thread.moderate`
- `forum.post.moderate`
- `forum.post.delete_any`
- `forum.post.restore`

06.08 adds structural permissions:

- `forum.thread.move`
- `forum.thread.copy`
- `forum.thread.merge`
- `forum.thread.split`
- `forum.thread.delete`
- `forum.thread.restore`
- `forum.moderation.bulk`

Move/copy/split require the structural permission in both source and target forums. Merge requires it in every participating source/destination forum. Bulk actions require `forum.moderation.bulk` **and** the underlying action permission in every affected forum; bulk is never an escalation shortcut.

All forum-scoped checks also require `forum.view` through `ForumNodeAuthorization`.

Starter templates explicitly own all seven new structural keys: `new_user`, `member` and `verified` deny them; `moderator` and `administrator` allow them. This prevents privilege residue after applying a lower-privilege starter profile.

## Thread lifecycle and visibility

Migration `20260916000000_content_moderation` adds:

- `forwext_threads.deleted`
- `forwext_threads.deleted_at_utc`
- `forwext_threads.merged_into_thread_id`
- an active-forum index
- a restrictive self-reference for merge tombstones

Normal `DatabaseThreadRepository` lookups/listings only return `deleted = 0` and `merged_into_thread_id IS NULL`. Normal optimistic saves also include that active predicate, so a stale ordinary application object cannot silently resurrect a thread after moderation deletes/merges it.

Moderation uses a separate repository that can inspect tombstones for restore/audit decisions.

## Structural operation semantics

### Move

Move changes only the forum identity under a row lock. Existing thread metadata is preserved rather than silently deleted. Any later metadata edit must satisfy the destination forum's 06.04 configuration rules.

### Copy

Copy creates a new thread identity and new post identities. It copies core thread/post content and moderation/deleted state but deliberately does **not** clone forum-scoped metadata, poll, watch/read state or other relationships. This avoids bypassing destination-forum policy through a moderation copy.

### Merge

Merge locks all participating threads in deterministic id order, appends source posts to the destination using new monotonically increasing positions, and leaves each source as a soft-deleted `merged_into_thread_id` tombstone. Source thread identities and their historical relationships therefore remain addressable for moderation/audit rather than being hard-deleted.

### Split

Split moves selected replies to a newly generated thread and re-numbers both source and destination post positions. The source first post (`position = 1`) cannot be selected. Deleted posts must be restored before split. Source read-watermarks are remapped to the compacted remaining post positions so re-numbering cannot incorrectly mark newer content as already read.

## Delete/restore

Thread delete/restore is soft-delete based. A merged source cannot be restored as an independent thread because its posts have already moved to the merge destination.

Post delete remains soft-delete based and records the pre-change snapshot in `forwext_post_history`. A first post cannot be individually deleted; it must be handled through its owning thread so the first-post invariant is not broken.

## Bulk operations

Thread bulk actions: lock, unlock, sticky, unsticky, approve, delete and restore.

Post bulk actions: approve, delete and restore.

Input is de-duplicated and bounded to 100 targets. Rows are locked in deterministic id order before mutation to reduce deadlock risk. Each changed target gets its own audit event plus a bounded bulk summary event tied to the request id.

## Audit safety

`forwext_moderation_audit_events` is append-only at the application boundary. `DatabaseModerationAuditStore::append()` refuses to write outside an active transaction. If audit persistence fails, the moderation mutation fails with the same transaction.

Each event records:

- generated audit id,
- authenticated actor id,
- action,
- target type/id,
- relevant forum id,
- bounded machine reason code,
- bounded request/correlation id,
- curated before/after snapshots,
- UTC timestamp.

The 06.08 repository only sends structural/state snapshots (ids, booleans, moderation states, counts). It does not put post bodies, email addresses, credentials or arbitrary moderator text into this audit stream.

The audit table intentionally does not foreign-key historical actor/forum identifiers to mutable/deletable account/content rows; historical identity values remain preserved when operational records later change.

## Deployment

The migration is registered immediately after `20260915235959_discussion_state` in the browser installer/upgrade registry. No Node, Redis, worker daemon, Supervisor or new PHP extension is required; the feature remains compatible with the minimum vendor-inclusive cPanel package profile.
