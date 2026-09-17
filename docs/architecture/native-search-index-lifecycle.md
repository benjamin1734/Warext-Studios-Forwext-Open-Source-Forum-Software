# Native Search Index Lifecycle

Status: **Normative implementation baseline**  
Roadmap step: **08.01 — Native search/index lifecycle**

## Purpose

Step 03.07 established the provider-neutral `SearchDriver`, native InnoDB FULLTEXT storage and opaque access-scope contract. Step 08.01 connects real first-party domain data to that contract and makes index maintenance durable, retryable and permission-aware.

The minimum cPanel profile remains daemon-free. Content changes are recorded in MySQL/MariaDB and a bounded maintenance job drains them. Advanced deployments may call the same lifecycle service from dedicated workers and may replace the search driver without replacing the lifecycle/source contracts.

## Indexed core content types

The core registry currently provides real sources for content that already exists in the schema:

- `user` — active account username only;
- `forum` — forum-node title/description/page content with a node permission scope;
- `thread` — visible, non-deleted, non-merged threads;
- `post` — visible, non-deleted posts whose parent thread is visible, non-deleted and non-merged.

FAQ, portfolio, marketplace and later first-party/add-on content types plug into `SearchContentSourceRegistry` when their own real domain tables/services exist. 08.01 does not create fake placeholder content tables merely to claim coverage.

## Data-minimization rules

User documents intentionally contain only the public username plus locale metadata. Email addresses, normalized email keys, timezone, authentication state, custom profile fields and other private account data are not selected by the user search source and therefore cannot be sent to either the native or an external search provider through this lifecycle.

Pending/rejected/deleted forum content is not emitted as a searchable document. If a previously indexed object becomes non-searchable, its source returns `null` and the lifecycle deletes the stale search document.

## Permission-aware discovery

`search.use` is a first-party flag permission. The 08.01 migration grants it to the five existing starter profiles; site administrators can override the normal permission engine rules later without a parallel search-specific authorization system.

Forum/thread/post documents carry a stable `forum.node:<node-id>` scope. Query scopes are built from the same `forum.view` permission engine used by the rest of Forwext.

Search is a discovery surface, so node hierarchy semantics are stricter than direct URL resolution: `ForumSearchAccessScopeProvider` uses `ForumNodeHierarchy::isDiscoverable()`. An `unlisted` node, or a descendant of an unlisted node, is therefore omitted from search even when the actor could resolve a direct URL. Disabled nodes likewise receive no search scope.

Public documents use the `public` scope. `PermissionAwareSearchService` always checks `search.use`, collects validated scopes, splits large scope sets into bounded chunks and merges duplicate provider hits by stable document identity.

A search hit is still only a candidate `(document_type, document_id, score)`. The final page/controller must reload the domain object and perform its normal backend authorization before rendering protected title/body/snippet data, as required by the 03.07 search contract.

## Durable change outbox

Migration `20260917003000_search_index_lifecycle` creates `forwext_search_index_changes`.

Each row is unique by document type/id and contains:

- a monotonic `revision`;
- bounded retry `attempts`;
- `available_at_utc` for backoff;
- `locked_until_utc` for a bounded worker lease;
- a machine-safe `last_error_code`;
- an update timestamp.

Domain-table triggers record a change in the same database transaction as the source mutation. Repeated changes increment the revision and clear retry/lease state. The worker acknowledges a row only when the revision it processed still matches, so a newer mutation cannot be accidentally deleted by an older in-flight indexing attempt.

`DatabaseSearchIndexChangeStore::claimDue()` takes a transaction, locks due rows with `FOR UPDATE`, writes a time-bounded lease and then returns the claimed revisions. A crashed worker therefore cannot hold work forever; after lease expiry another worker can reclaim it. Revision compare-and-delete/update remains the final protection against races with newer writes.

## Dependency invalidation

A post search document inherits both visibility and scope from its parent thread. The lifecycle therefore does more than listen to direct post updates.

The thread update trigger always queues the thread itself and queues all child posts only when an index-relevant parent field changes: forum node, title, moderation state, deleted state or merge target. Routine thread timestamp changes do not fan out across every post.

MySQL does not invoke child-table triggers for rows deleted by a foreign-key cascade. A `BEFORE DELETE` thread trigger therefore captures all child post IDs into the search outbox before the post rows disappear. The later worker sees the missing source rows and deletes their stale search documents.

Permission assignment changes do not require reindexing because node scopes are stable tokens and viewer scopes are resolved from current permissions at query time.

## Retry and failure behavior

Index/provider failure must not roll back successful forum content persistence. The worker catches indexing failures, clears its lease and schedules exponential retry starting at 15 seconds and capped at one hour. After 20 attempts the row remains persisted with its last error code but is no longer selected automatically, leaving an inspectable failure state for future ACP/maintenance tooling.

A new source mutation resets attempts and makes the new revision immediately eligible again.

## Rebuild lifecycle

`SearchIndexLifecycleService::rebuild()` scans a registered source in stable ID order with an explicit cursor and a maximum batch size of 500. Each ID is synchronized through the same visibility/privacy logic as incremental changes. Rebuild therefore cannot bypass normal data-minimization rules.

This bounded API is the basis for later ACP/rebuild tools and external-provider reindex operations without requiring an unbounded request or a database reset.

## cPanel maintenance path

`SearchIndexMaintenanceTasks` registers `search.index.drain` every minute on the shared `maintenance` queue with a bounded default batch of 100. `SearchIndexDrainJobHandler` validates its JSON payload and invokes the same lifecycle service.

No Redis, Node.js, Supervisor, long-lived worker or WebSocket service is required for correctness. A cPanel cron invocation of the existing scheduler/queue runner is sufficient. Advanced deployments may execute the maintenance queue continuously.

## Security invariants

- No indexed document is returned without at least one validated access scope.
- `search.use` and `forum.view` are resolved through the shared permission engine.
- Unlisted hierarchy content is not search-discoverable.
- Pending, rejected, deleted or merged forum content is excluded by the source layer.
- User email and private account/profile fields are never selected for indexing.
- Query text remains parameterized by the search driver.
- Source mutations and index invalidation records share the same database transaction through triggers.
- Worker acknowledgement is revision-bound; worker ownership is time-bound by a lease.
- Search candidates never replace final domain authorization.
