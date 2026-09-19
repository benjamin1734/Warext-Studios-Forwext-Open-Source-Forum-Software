# User Content Manager Architecture

Roadmap step 12.05 adds a bounded, permission-gated user content manager for forum threads and posts. The manager is intended for authorized staff workflows rather than ordinary self-service deletion.

## Inventory and filters

`DatabaseContentManagerRepository` exposes a single inventory across a target user's threads and posts. Filters are explicit and parameterized:

- content type (`thread` / `post`);
- forum node;
- moderation state (`visible`, `pending`, `rejected`);
- soft-deleted / active state;
- bounded text search.

Search never interpolates user text into SQL. Bulk operations resolve the same filter into concrete content ids and freeze that target set before execution, so later content creation or filter changes cannot silently expand an already-created operation.

## Dry-run and safety boundary

Every mutation can be previewed through a dry-run. The preview returns total targets and thread/post counts without writing state. A single operation is bounded to 5,000 frozen targets; larger result sets must be narrowed before enqueueing. This keeps cPanel memory/transaction pressure predictable and makes operator intent reviewable.

`move` is thread-only and requires an existing forum node. Target-forum input is rejected for all other actions.

## Bulk actions

Supported actions are:

- `delete` — soft-delete through the existing moderation repository;
- `restore` — restore eligible soft-deleted content;
- `move` — move threads to another forum;
- `approve` — approve pending/rejected thread or post content;
- `reindex` — enqueue native search-index lifecycle changes;
- `reprocess` — run the canonical pre-persist content stages without persistence side effects, then enqueue search reindexing.

Thread state changes also enqueue every related post for search synchronization because post visibility and forum scopes depend on the owning thread.

## Reprocess contract

`ContentPipeline::preprocess()` exposes the canonical validation → spam → spellcheck → AI moderation → moderation-policy stages without opening the persist/notify/index transaction. Existing normal posting continues to call `execute()`, which now reuses the same method before persistence. The content manager uses `preprocess()` for reprocess jobs and performs search lifecycle scheduling separately.

This avoids duplicate persistence, notifications or content creation while still letting the currently configured advisory/moderation stages reassess stored content.

## Durable operation queue and progress

`forwext_content_manager_operations` stores the immutable operator/target/action/filter snapshot plus aggregate progress. `forwext_content_manager_operation_items` stores the frozen target ids and per-item status.

Items transition `pending → processing → succeeded|skipped|failed`. Processing claims use `FOR UPDATE SKIP LOCKED`; processing leases older than ten minutes are returned to pending so an interrupted bounded worker can recover. Aggregate progress records total, processed, succeeded, skipped and failed counts and resolves the operation to `completed`, `partial` or `failed`.

The operation is also pushed to the existing `QueueDriver` under the `content-manager` queue with job type `content.manager.execute`. `ContentManagerJobHandler` processes bounded 50-item chunks and enqueues the next chunk until terminal. The native progress page additionally offers a bounded manual-processing action, giving cPanel installations a recovery path even when a long-running worker is not used.

## Authorization

Two existing first-party permission keys become active defaults:

- `content_manager.access` — view filtered inventories and own operation progress;
- `content_manager.execute` — create dry-runs/bulk jobs and execute queued chunks.

Both are denied to new/member/verified templates and allowed to moderator/administrator templates. The queued processor checks `content_manager.execute` again immediately before processing. Revoking the operator's permission therefore prevents remaining queued targets from being processed.

Operation detail reads are actor-bound: knowing another operation id does not grant access to its progress or frozen target list.

## Moderation and audit behavior

Delete/restore/move/approve use the existing `DatabaseContentModerationRepository`, preserving soft-delete/domain invariants and existing moderation audit events. Content-manager-specific cross-system audit normalization is intentionally completed by roadmap step 12.07 rather than inventing a parallel central audit contract in 12.05.

## Search consistency

All mutations that can affect discoverability enqueue search lifecycle changes. Reindex and reprocess are explicit bulk actions as well. The search driver itself is not called synchronously from the bulk manager; normal search lifecycle retry/backoff remains authoritative.
