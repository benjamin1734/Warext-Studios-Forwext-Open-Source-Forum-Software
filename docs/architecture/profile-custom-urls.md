# Custom Profile URL System

Roadmap step: **04.08 — Custom profile URL system**.

## Purpose

Forwext provides a stable shareable profile path at `/u/{slug}` without replacing the existing username route. The username route remains a first-class compatibility path; the custom URL is an additional user-selected public identifier governed by permission, privacy and abuse policy.

## Permission boundary

Custom URL use is checked through `ProfileUrlPermissionResolver` and the `profile.url.use` permission value. The baseline resolver is intentionally small so the shared role/group permission engine introduced in 05.x can replace it without rewriting the profile URL domain or HTTP handlers.

A baseline user may modify only their own custom URL because `ProfileUrlService` also requires the profile access policy to allow editing. UI visibility is never treated as authorization; the service enforces the permission and ownership checks again on every write.

## Slug format and reserved names

`ProfileSlug` normalizes input to lowercase and accepts only 3–32 ASCII characters using letters, digits and single hyphens. Leading/trailing hyphens and repeated `--` are rejected.

Reserved names are configuration-driven through `profile_url.reserved_names`. The default set protects application/system namespaces and high-trust names such as `admin`, `api`, `account`, `members`, `staff`, `official`, `system`, `security`, `forwext` and Warext brand identifiers. Reserved-name matching is case-insensitive because all accepted slugs are canonicalized before policy evaluation.

## Persistence model and non-reuse guarantee

Two tables are deliberately used:

- `forwext_user_profile_urls` stores the one current slug and rate-limit state for each user.
- `forwext_profile_url_claims` is the permanent slug claim/history ledger.

`slug_key` uses an ASCII binary collation and is the primary key in the claim ledger. When a user changes their slug, the old claim is retired but is **not deleted**. Another account can therefore never take a previously used profile URL.

If the owning user is deleted, the claim row uses `ON DELETE SET NULL`. The historical slug remains reserved while resolution fails closed because it no longer has an active owner/current URL. This prevents old external links from later being captured by another account.

The current URL row uses `ON DELETE CASCADE`; deleting an account removes live routing state while preserving historical reservations.

## Concurrency and uniqueness

Claims are made inside one database transaction. The owner row in `forwext_users` is locked first, including the first-ever assignment, so concurrent requests by the same account serialize before current/history state is inspected.

The requested claim row is also checked under row lock and the claim table has a database primary-key uniqueness constraint. A final duplicate-key race from concurrent accounts is translated to the normal `Custom profile slug is unavailable` domain error rather than leaking a database failure/500 response.

The current table additionally has a unique constraint on `slug_key` as a defense-in-depth invariant.

## Change limits and abuse protection

The stock policy is configurable and defaults to:

- minimum interval between actual slug changes: 86,400 seconds (24 hours),
- rolling/reset window: 2,592,000 seconds (30 days),
- maximum actual changes inside one window: 3.

The first assignment starts the window but is not counted as a change. Re-submitting the exact current slug is idempotent and does not consume cooldown/change budget. After the configured window expires, the next real change starts a new window with a change count of one.

All timestamps are stored as UTC `DATETIME(6)` values.

## Routing and historical redirects

The native route is `GET /u/{slug}`.

Resolution order is intentionally privacy-aware:

1. normalize and resolve the claim,
2. require an active user,
3. resolve the authenticated viewer from the existing validated auth session,
4. evaluate profile visibility,
5. only then render or redirect.

A current canonical slug renders the existing `ProfileViewHandler`, keeping profile behavior in one implementation.

A retired slug returns `308` to the current custom URL only after the viewer is allowed to see the profile. Hidden/private profiles return `404` first, so historical URLs do not disclose the current private URL.

Non-canonical case variants such as `/u/My-Profile` are also redirected to the lowercase canonical URL after the same visibility check. Historical/canonical redirects use `Cache-Control: private, no-store` because visibility can depend on the authenticated viewer.

## User settings route and CSRF

The native settings surface is `/account/profile-url`:

- `GET` renders the current URL and change form.
- `POST` performs the owner-only assignment.

The route requires an authenticated viewer. Both methods pass through the shared `CsrfMiddleware` with a dedicated `profile-url` scope. The CSRF signing key is domain-separated from the master secret using HMAC before it is passed to `CsrfTokenManager`; the raw encryption key is not reused directly as the CSRF key.

The form uses the existing HTTP-only SameSite CSRF context cookie plus a hidden token. Missing/invalid context or token is rejected with `403` before the profile URL handler can mutate state.

## Failure and disclosure policy

Unknown, invalid, deleted-owner and visibility-denied custom URLs return `404` rather than exposing account/profile state. User-facing assignment failures redirect back to settings with a generic error notice instead of revealing whether a protected/historical slug belongs to another account.

Database constraint details, user IDs and historical ownership are never rendered to the caller.

## cPanel/runtime impact

The feature uses the existing PHP/MySQL runtime, router, auth-session resolver, CSRF middleware and migration engine. It does not require Composer at runtime, Node, Redis, workers or additional PHP extensions beyond the existing Forwext minimum deployment profile.

Migration: `20260915143000_custom_profile_urls`.

## Acceptance coverage

The 04.08 tests cover canonical normalization, reserved names, permission/ownership enforcement, permanent historical reservations, historical resolution, cooldown, rolling-window limits/reset, idempotent re-submission, concurrent duplicate-key mapping, migration invariants, privacy-preserving redirects and a real CSRF cookie/token GET→POST round trip.
