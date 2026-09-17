# Advanced search and filters (08.02)

Forwext advanced search builds on the permission-aware index lifecycle delivered in 08.01. Search authorization remains server-derived: HTTP clients can provide search text and filter values, but never access-scope tokens.

## Filter model

`AdvancedSearchFilters` supports forum, author/user, prefix, tag, indexed state, thread type and updated-date bounds. Content type remains the existing `SearchQuery::documentTypes` filter. Values are bounded, validated identifiers; OR semantics apply inside one filter category and AND semantics apply between categories.

Native search stores filter-only metadata in `forwext_search_document_attributes`. The table contains document keys plus safe identifier metadata only. It does not contain e-mail addresses, private profile/custom-field data, notification data, drafts or moderation-only text. Date bounds use the canonical search document `updated_at_utc` value.

Core lifecycle sources emit:

- forum: forum id and public node visibility state;
- thread: forum id, author id when present, prefix id, tag ids, visible state and thread type;
- post: forum id, post author id when present, parent-thread prefix/tag metadata, visible state and thread type;
- user: user id and active state.

Pending/rejected/deleted forum content is not made searchable by a `state` filter: 08.01 source visibility rules still decide whether a document exists in the index at all.

## Metadata invalidation

Migration `20260917004000_search_advanced_filters` creates the attribute table and queues a rebuild of current searchable source types. It also adds independent dependency triggers for prefix/tag assignments, thread-type changes and forum-visibility changes. A prefix/tag mutation requeues the affected thread and all posts in that thread; a thread-type change requeues inherited post metadata; and a node-visibility change requeues child threads/posts so disabled forums cannot leave stale searchable documents behind.

The migration is intentionally separate from 08.01. Previously shipped migration source files are not edited, preserving migration fingerprint integrity for upgraded installations.

## Saved-query extension point

`SavedSearchQueryExtension` and `SavedSearchQueryRegistry` provide a stable module/add-on registration point. An extension receives the authenticated user id and returns a `SavedSearchQuery` containing document types, locale and advanced filters. Duplicate or malformed extension keys are rejected. Saved queries call the same `PermissionAwareSearchService`, so extensions cannot bypass `search.use` or forum visibility scopes.

## Native web surface

`GET /search` renders the native PHP advanced-search form and results. The route accepts bounded query/filter parameters and requires an authenticated actor before executing a search. Search access scopes are never accepted from request data. Result titles come from already-authorized search hits; user hits can link to the existing member profile route.

The minimum cPanel profile needs no external search daemon. Advanced deployments may implement `ExternalSearchClient`; the `SearchDocument` and `SearchQuery` contracts now carry the same attribute/filter model for provider parity.
