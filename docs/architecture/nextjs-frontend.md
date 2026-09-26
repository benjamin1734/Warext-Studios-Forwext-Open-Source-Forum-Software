# Official Next.js frontend architecture

Roadmap sub-step: **19.06 — Resmî Next.js frontend**

## Scope

Forwext now ships an official optional Next.js App Router frontend source workspace at `frontend/next`.

The native PHP frontend remains the minimum, first-class cPanel runtime. The Next.js frontend is an advanced deployment tier and does not make Node.js/npm mandatory for a standard Forwext installation.

## App Router and RSC

- Next.js App Router with React Server Components by default.
- Official `@forwext/sdk` is the only application-level REST client used by the frontend.
- Official `@forwext/react-ui` provides shared accessible primitives and design-token styling.
- React context/hook surfaces carry explicit `"use client"` boundaries and package smoke tests preserve those directives.
- Detail loaders use React request memoization where metadata and page rendering need the same public resource.
- Route parameters/search parameters and cookie access use the asynchronous App Router contracts.

## Core pages

Modern RSC pages cover:

- home/community discovery,
- forum index and forum thread lists,
- thread/post reading,
- public API user summaries,
- Marketplace listing index/detail,
- first-party module state,
- public support categories,
- authenticated notification/conversation/support-ticket lists.

Pagination is bounded by the existing API v1 server contract.

## PHP feature-parity bridge

The modern frontend uses a fallback external rewrite to `FORWEXT_BACKEND_URL` for routes that are not implemented by App Router.

This preserves the existing PHP account, moderation, administration, bug-report, FAQ, giveaway, referral, portfolio, content-manager, editor, Marketplace transaction and first-party module surfaces without duplicating or weakening their existing permission/CSRF logic.

Routes implemented by Next.js are served by App Router first; unmatched routes retain the native PHP behavior. The backend destination is deployment configuration and is never supplied by a request.

## Authentication bridge

The modern protected RSC pages support existing Forwext PAT/OAuth bearer credentials and API keys.

- Credentials are validated server-side by API v1.
- The bridge secret is supplied only through `FORWEXT_NEXT_AUTH_SECRET`.
- The credential is sealed with AES-256-GCM in an HttpOnly, SameSite=Lax cookie.
- The raw credential is never emitted to client JavaScript or a `NEXT_PUBLIC_*` variable.
- Protected data fetches use `cache: "no-store"`.
- PHP API scope + account permission checks remain authoritative.
- Native fallback routes continue to use native PHP session/CSRF behavior.

## Public caching and revalidation

Public SDK reads use a short freshness window and bounded cache tags:

- `forwext:public`
- `forwext:forums`
- `forwext:threads`
- `forwext:marketplace`
- `forwext:modules`
- `forwext:support`
- `forwext:users`

`POST /api/revalidate` requires a server secret and accepts only a fixed event allow-list. The caller cannot submit arbitrary cache tags or arbitrary paths. Revalidation secrets are compared through constant-time SHA-256 digests.

## SEO and health

- App Router Metadata API supplies global and detail metadata.
- Private account pages are explicitly noindex/nofollow.
- `robots.txt` blocks private account/auth surfaces.
- `sitemap.xml` advertises canonical top-level public routes.
- `GET /api/health` performs a bounded no-store backend API probe.

## Response security

The modern tier adds:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`
- restrictive browser permissions policy for camera, microphone, geolocation and payment.

The application error boundary never renders raw exception messages in production.

## Deployment and packaging

The Next.js application uses `output: "standalone"` for self-hosting.

Release CI installs the Node development toolchain, builds the SDK/UI packages, typechecks the Next application and performs an optimized production build. The native cPanel ZIP intentionally excludes `frontend/next`, so Node remains optional.

## Validation

Final implementation/fix/test commits:

- `05415cc6253218496c197faa22591a4764e4aaba`
- `b67475b80c08c0882404fd7a9b3678f11dea1923`
- `cdf995a3f15569b531eabea41da68271d89d53a7`
- `bd5fc5b071826e814c522d072f6ae27283ab51b6`

Validated by:

- build/package workflow `36234157715`,
- database smoke workflow `36234157826` on MySQL 8.4 and MariaDB 10.11.

No database migration was required for 19.06.
