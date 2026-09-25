# Versioned REST API v1 (19.01)

Forwext exposes the first-party REST surface below `/api/v1`. The endpoint catalog is typed with `ApiV1Operation`, `ApiV1Resource`, `ApiV1Scope` and `ApiV1EndpointDefinition`, then registered through the existing native router.

## Public reads

19.01 implements public read models for:

- users;
- forums;
- forum thread lists;
- threads;
- thread post lists;
- posts;
- enabled first-party modules;
- public Marketplace listings;
- active support categories.

Forum/thread/post queries enforce the existing public visibility, moderation, deletion, archive and merge-state boundaries directly in the read query. User responses expose public identity fields only. Marketplace responses expose only active/sold listing data.

## Protected resource contracts

The v1 catalog also declares read scopes and routes for:

- conversations;
- notifications;
- support tickets.

These datasets are account-private. 19.01 intentionally does not invent a temporary authentication scheme: until the dedicated 19.02 API security layer resolves an authenticated API principal and verifies scopes, the native handler returns `401 authentication_required` before any private repository read can occur.

## Pagination and errors

Collection reads accept bounded positive `page` and `per_page` values; `per_page` is capped at 100. Responses carry deterministic pagination metadata and a `has_more` flag.

Errors use JSON envelopes and `Cache-Control: no-store`; public successful reads use a short public cache. API responses include `X-Content-Type-Options: nosniff`.

## Roadmap boundary

19.01 owns API versioning, typed endpoint/resource/scope contracts and public read surfaces. Token/API-key/OAuth API principals, scope authorization, API-specific rate limiting and API audit events belong to 19.02 and are not bypassed by making a route public in frontend navigation.

No database migration or additional cPanel runtime service is required for 19.01.
