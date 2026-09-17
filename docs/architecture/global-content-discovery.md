# Global Content Discovery UX

Forwext 08.06 unifies search/discovery into a single permission-aware experience without creating a parallel authorization path.

## Core category registry

`Forwext\Core\Search\DiscoveryUx\GlobalDiscoveryRegistry` owns the mapping between visible UX categories and backend `SearchDocument` types. The core contract is:

- `forum`: `forum`, `thread`, `post`
- `support`: `support.ticket`, `support.message`
- `faq`: `faq.article`
- `portfolio`: `portfolio.item`
- `marketplace`: `marketplace.listing`
- `members`: `user`

The registry rejects duplicate category keys, duplicate document-type ownership and invalid identifiers. Module contributors may register additional categories through `GlobalDiscoveryContributor`, but each backend document type has exactly one discovery-category owner.

## Authorization boundary

Discovery tabs and explicit type filters are narrowing controls only. They never grant access.

`SearchHandler` converts the selected tab into an exact backend document-type allowlist and delegates the request to `PermissionAwareSearchService`. The existing search service remains responsible for `search.use`, server-derived access scopes and the search driver's scope intersection. Client-provided forum IDs, user IDs, tab names or document types cannot create permission scopes.

An explicit `type` filter must be registered and must belong to the selected tab. Cross-category requests and unknown types fail closed. Saved searches cannot be combined with a non-`all` tab or an explicit type filter, avoiding accidental widening or misleading pagination semantics.

Future Support, FAQ, Portfolio and Marketplace modules must index only real `SearchDocument` records and assign permission scopes that represent their actual privacy model. Private support tickets or private marketplace records must use owner/staff/module scopes; they must not be assigned `public` merely to make them discoverable.

## Progressive-disclosure UX

`SearchHtml` provides simple top-level tabs for All, Forum, Support, FAQ, Portfolio, Marketplace and Members. Existing advanced search fields remain available under a native `<details>` control so common search remains simple while power-user filters remain available.

The `all` tab groups returned hits by registry category. A selected category renders one focused result section. User results may link to the already implemented public member route. For modules that do not yet expose a canonical public/detail route, the discovery UI deliberately does not invent a URL.

## Current implementation coverage

At 08.06, Forum and Members already have real search-document producers from earlier steps. Support, FAQ, Portfolio and Marketplace are later main-plan modules. Their document-type contracts are reserved now so those first-party modules can integrate with the same UX when implemented, but 08.06 does not fabricate records, routes or placeholder data for them.

A category with no indexed, authorized documents simply returns no results.

## Security and privacy rules

- Every search still flows through `PermissionAwareSearchService`.
- The discovery registry controls document-type narrowing, not access scopes.
- Unknown, duplicate and cross-category type filters fail closed.
- Result titles and identifiers are HTML escaped before rendering.
- The UI does not generate links for future modules whose canonical route does not yet exist.
- Private or permission-restricted content remains non-discoverable unless its server-derived search scope authorizes the current actor.

## Runtime compatibility

The feature is native PHP and requires no Composer/npm/Node/Redis/Docker/Supervisor process at cPanel runtime. It introduces no database migration and no new background service.
