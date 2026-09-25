# API security, rate limiting and audit (19.02)

Roadmap step 19.02 secures the versioned REST API introduced in 19.01 without creating a second user-permission system.

## API principals and credentials

Forwext v1 recognizes three API credential contexts:

- personal access token;
- API key;
- OAuth-type API access token.

Personal and OAuth-type credentials are presented with `Authorization: Bearer ...`. API keys use `X-API-Key`. A request that attempts to present more than one credential mechanism is rejected.

Credential secrets are generated with high entropy and are returned only at issuance time. The database stores a SHA-256 digest rather than the raw secret. Stored metadata includes the owner user, credential type, display name, scopes, created time, optional expiry, revocation time and last-use time.

The OAuth-type context is an API principal representation. 19.02 does not implement a new OAuth authorization server and does not replace the existing Google/Discord connected-account OAuth integrations.

## Scope and account authorization

Protected endpoints require an authenticated `ApiV1Principal` and their declared `ApiV1Scope`.

Scope authorization is not a substitute for the common role/permission engine. For account-private resources the API layer also checks the existing account permissions:

- `notifications.read` requires `notification.alert.view`;
- `support.read` requires `support.ticket.view_own`;
- `conversations.read` requires both `support.ticket.view_own` and `bug.report.view_own`.

This means an old token cannot restore access after an administrator removes the corresponding permission from the account.

## Private read isolation

Authenticated notification queries are constrained by `recipient_user_id`.

Support ticket queries are constrained by `requester_user_id`.

The current first-party conversation API aggregates only the authenticated account's own support-ticket and bug-report conversation summaries. It does not expose staff/internal message fields or another user's records.

## Rate limiting

`ApiV1RateLimitMiddleware` reuses the existing `RateLimitStore` abstraction.

The cPanel-first composition uses the file-backed implementation under protected `storage/`. Authenticated credentials are bucketed by credential id and anonymous calls by canonical client IP. Advanced deployments can replace the injected store later without changing API handlers.

Rate-limit failures are JSON responses and include `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`.

## Errors and routing

API security errors use one JSON envelope. Invalid credentials, missing authentication, insufficient scopes, account permission denials, invalid requests, missing resources and rate-limit failures use stable error codes.

Router-level 404 and 405 responses under `/api/v1` are also converted to the API JSON envelope. The generic Router received a pluggable `RoutingErrorResponder`; its default behavior remains unchanged for the native non-API web application.

## Audit

Authenticated requests are recorded through the existing core audit stream using `AuditScope::Api` and action `api.request`.

Audit snapshots contain:

- route;
- resource;
- requested scope;
- HTTP response status;
- credential id;
- credential type.

Raw access tokens, API keys, Authorization headers and private credential hashes are not written into audit snapshots.

19.02 currently exposes read endpoints. The middleware treats inability to persist the authenticated read audit as a service failure. Future mutation endpoints must keep mutation and audit transaction semantics at their owning service boundary.

## Migration and deployment

Migration `20260925185000_api_v1_credential_security` creates the API credential table with owner foreign key, unique secret digest and owner/expiry indexes.

The migration is additive. It does not reset user/forum data.

No mandatory Node.js, npm, Redis, Docker, Supervisor, SSH or long-running worker is added to normal cPanel hosting.
