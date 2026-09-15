# Forum Node Hierarchy

Roadmap step 06.01 introduces the structural forum tree used by categories, forums, subforums, pages and links. The hierarchy is deliberately independent from thread/post lifecycle, which starts in 06.02 and 06.03.

## Node types

`ForumNodeType` defines four first-party node kinds:

- `category` — structural/navigation container; may contain child nodes.
- `forum` — discussion container; may contain subforums and carries `ForumSettings`.
- `page` — leaf node with bounded source content; rendering/editor semantics are completed in later editor/content steps.
- `link` — leaf node pointing to either a same-site absolute path or a credential-free HTTPS URL.

Page and link nodes cannot become parents. A forum may be a top-level node or a child of a category/forum, which provides real subforum support without introducing a second hierarchy implementation.

## Identity, slugs and ordering

Node ids are 128-bit lowercase hexadecimal identifiers. Slugs are global, lowercase ASCII identifiers with single-hyphen separators and a database unique constraint. Global slug uniqueness keeps routing/canonicalization deterministic before later friendly-URL work expands around the domain.

Sibling ordering uses `sort_order` followed by title, slug and id as deterministic tie-breakers. Breadcrumbs are built from the validated parent chain and returned root-to-current-node.

The in-memory `ForumNodeHierarchy` rejects duplicate ids/slugs, orphan parents, page/link parents, self-parenting, parent cycles and chains deeper than 64 nodes. The database repository revalidates a row-locked hierarchy snapshot inside a transaction before writes, so ACP/UI validation is never the only hierarchy-integrity boundary.

## Visibility is not authorization

`ForumNodeVisibility` has three states: `listed` is eligible for normal discovery/navigation; `unlisted` remains directly resolvable but is omitted from normal discovery/navigation; `disabled` is not resolvable. Ancestor state propagates downward: a descendant of an unlisted node is not discoverable, while a descendant of a disabled node is not resolvable.

These states do **not** grant access. `ForumNodeAuthorization` separately delegates to the shared permission engine through an actor-bound `PermissionGate` using the node-scoped `forum.view` permission. This preserves the 05.x invariant that UI/navigation state never substitutes for backend authorization. Disabled nodes fail closed before permission lookup.

## Forum settings

Only `forum` nodes may carry `ForumSettings`: allow new threads, allow replies, require thread approval, require post approval, default thread sort (`last_post`, `created`, `title`) and threads per page (5–100). These values are policy/configuration inputs for 06.02/06.03. Step 06.01 does not create thread or post tables prematurely.

## Link and page safety

Link targets accept only same-site absolute paths beginning with one `/` or credential-free `https://` URLs with a host. Protocol-relative URLs, backslash paths, credentials, control characters and non-HTTPS external schemes are rejected. Page content is stored as bounded source text (100,000 bytes maximum); this step does not grant raw-HTML rendering behavior.

## Persistence

Migration `20260915235930_forum_nodes` creates `forwext_nodes` for hierarchy/type/slug/order/visibility/page-link payloads and `forwext_forum_settings` for one-to-one forum settings. It enforces a global unique slug index, a self-referencing parent FK with `ON DELETE RESTRICT`, a forum-settings FK with `ON DELETE CASCADE`, parameterized repository reads/writes, and deletion refusal for nodes that still have children.

The migration is registered in `CoreMigrationRegistry`, so browser clean installs and upgrades execute it in chronological order after the completed 05.x permission migrations.

## Forward boundaries

06.02 adds thread lifecycle/types on top of forum nodes. 06.03 adds post lifecycle. Prefix/tag/custom fields, polls, watch/read state and the editor follow in their own roadmap steps. Those systems must reuse the node identity and node-scoped permission boundary defined here rather than inventing parallel forum identifiers or access checks.
