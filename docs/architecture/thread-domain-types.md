# Thread Domain and Thread Types

Roadmap step 06.02 introduces the thread aggregate on top of the 06.01 forum-node hierarchy. A `Thread` is a top-level discussion/content container that belongs to one `forum` node. It does not embed the first post: 06.03 keeps the first post as a real `Post`, preserving the normative glossary contract.

## Thread identity and type

Threads use opaque 128-bit lowercase hexadecimal ids. Each thread stores one immutable `ThreadTypeKey`; the type changes behavior without creating a parallel thread identity/domain.

`ThreadTypeRegistry` ships with the protected core `discussion` type and accepts additional validated type definitions. Duplicate keys are rejected rather than silently replacing an existing type. This is the extension seam later first-party/add-on type integrations must use.

A thread type currently declares a stable key, display label, whether replies are supported and whether the definition is a protected system type. Additional type behavior belongs in later feature integrations; 06.02 deliberately avoids embedding post/editor/poll/custom-field behavior before their roadmap steps.

## Lifecycle state

The thread aggregate owns title, moderation state (`visible`, `pending`, `rejected`), locked/sticky/featured flags, immutable forum/author/type identity, UTC timestamps and optimistic aggregate version. Mutations are idempotent when state is already equal and record domain events when state actually changes. Mutation timestamps cannot precede creation time.

The moderation model allows visible content to be rejected, rejected content to return to pending review and pending content to be approved. These are domain state changes; authorization is enforced by application services rather than hidden inside the aggregate.

## Creation boundary

`ThreadCreationService` requires all of the following before persistence:

1. the target node exists and is a `forum`;
2. the node chain is resolvable;
3. the actor has node-scoped `forum.view`;
4. the actor has node-scoped `forum.thread.create`;
5. forum settings allow new threads;
6. the requested thread type is registered.

The author id comes only from the actor-bound `PermissionGate`; route/body target ids cannot select another author. Forums requiring thread approval create the thread in `pending`; other forums create it as `visible`.

## Moderation and presentation flags

`ThreadStateService` uses granular node-scoped permissions:

- `forum.thread.lock` — lock/unlock;
- `forum.thread.sticky` — sticky/unsticky;
- `forum.thread.feature` — feature/unfeature;
- `forum.thread.moderate` — approve/reject.

Every operation also requires `forum.view` for the owning forum. Possessing one state permission never implies another. The state service reloads the current forum hierarchy so disabled/missing forums fail closed before mutation.

The new keys are owned by every built-in permission template to avoid privilege residue during template changes: `new_user`, `member` and `verified` explicitly deny them, while `moderator` and `administrator` explicitly allow them. Applying a lower-privilege starter template therefore cannot leave a stale thread-moderation grant merely because the key was absent from that template.

## Persistence and concurrency

`DatabaseThreadRepository` stores the aggregate in `forwext_threads` and uses compare-and-swap optimistic versioning for updates. An update affects exactly the expected version or throws `ThreadConcurrencyException`; concurrent/stale writers therefore cannot silently overwrite newer state.

Forum listings are bounded and ordered by sticky, featured, most recently updated and id. Dynamic identifiers and values use bound parameters; only validated integer pagination limits/offsets are concatenated into SQL.

Author deletion uses `ON DELETE SET NULL` so durable community content can remain without retaining a broken user FK. Forum and thread-type deletion use `RESTRICT` while referenced.

## Migration and immutable upgrade behavior

Migration `20260915235945_thread_domain` creates `forwext_thread_types`, the protected `discussion` seed, `forwext_threads`, the four granular thread-state permission definitions and 20 corresponding built-in starter-template rules.

The existing 05.06 permission-namespace migration is intentionally not edited. New permissions are introduced by the new 06.02 migration so already-applied migration source/behavior stays immutable and upgrades remain deterministic.

`CreateThreadDomainTables` is explicitly registered after `CreateForumNodeTables` in `CoreMigrationRegistry` and registry tests require it, preventing clean installs from silently omitting the thread schema.

## Forward boundary

06.03 introduces real post entities, first-post linkage, edit/history/delete/restore/approve behavior, counters and pagination. The thread table intentionally contains no message body or fake first-post payload in 06.02. Prefixes, tags, polls, read/watch state and editor features remain in their dedicated roadmap steps.
