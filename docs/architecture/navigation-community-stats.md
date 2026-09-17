# Navigation, member discovery, presence, stats and breadcrumbs — 08.05

## Navigation registry

The shared `NavigationRegistry` owns stable navigation keys, ordering, same-origin paths and optional module ownership. First-party modules contribute through `NavigationContributor` / `registerModule()` rather than editing the base layout with hard-coded includes.

Navigation visibility is UX only. A visible/hidden link never replaces backend permission enforcement.

## Member directory

The native `/members` directory supports bounded username search, newest/name sorting and pagination. It selects only active accounts whose effective profile visibility is public. LIKE wildcard input is escaped and values remain parameterized.

## Presence and online users

Presence is distinct from authentication-session expiry. A browser heartbeat marks recent activity and online status expires after five minutes; writes are throttled to at most once per minute per user for the normal cPanel profile.

Presence visibility values are:

- `hidden`
- `members` (default)
- `public`

Anonymous visitors only receive `public` presence. Authenticated members receive `public` + `members`. Hidden presence is never returned. Online discovery additionally requires an active account and a public profile, so presence cannot bypass profile privacy.

Heartbeat/preference mutations are actor-bound to the authenticated session. Browser writes require the same-origin custom `X-Forwext-Presence: 1` header, reject conflicting `Origin`, and reject cross-site Fetch Metadata. This is a CSRF boundary; CORS must never allow arbitrary origins to supply credentialed presence requests.

## Forum stats

Forum/thread/post counts are actor-specific. The service reuses `ForumSearchAccessScopeProvider`, which derives discoverable forum scopes from `forum.view`. Counts are then restricted to those server-derived ids; client ids are never accepted as authority.

Categories/pages/links are not counted as forums. Deleted, merged or non-visible threads/posts are excluded. Active-member count is only exposed on the authenticated stats page.

## Breadcrumbs

The shared breadcrumb model renders escaped same-origin paths. Existing `ForumNodeHierarchy::breadcrumb()` remains authoritative for forum ancestry; `ForumBreadcrumbBuilder` adds per-node backend `forum.view` rechecks before a forum trail can be rendered.

## Deployment

The feature uses MySQL plus small first-party JS assets only. No Redis, Node.js, WebSocket service, Supervisor or daemon is required on cPanel. Advanced deployments can replace/augment presence transport later without changing visibility semantics.
