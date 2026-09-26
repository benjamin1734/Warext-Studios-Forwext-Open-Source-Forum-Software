# Forwext Next.js frontend

This directory contains the official optional modern Forwext frontend.

It is a Next.js App Router application that consumes the versioned Forwext REST API through `@forwext/sdk` and the shared accessible UI/design-token package through `@forwext/react-ui`.

## Deployment model

The native PHP frontend remains the mandatory cPanel-compatible baseline. Node.js is **not** required for a standard Forwext installation.

The Next.js application is an optional frontend tier for deployments that provide a Node.js runtime. It uses `output: "standalone"` for self-hosting.

When this frontend is placed in front of the native PHP application, unmatched routes use the configured backend as a fallback rewrite. This keeps existing PHP-only account, moderation, administration and first-party module surfaces reachable while routes implemented in App Router use the modern frontend. Existing URLs therefore do not need to disappear while React coverage grows.

The backend URL is deployment configuration, not user input. Do not point it at an untrusted host.

## Required environment

- `FORWEXT_BACKEND_URL`: internal or public root URL of the PHP Forwext installation. Default for local development: `http://127.0.0.1:8080/`.
- `FORWEXT_PUBLIC_URL`: canonical public URL of the Next.js frontend. Default for local development: `http://127.0.0.1:3000/`.
- `FORWEXT_NEXT_AUTH_SECRET`: 32-512 character secret used to encrypt the optional server-side API credential bridge cookie.
- `FORWEXT_NEXT_REVALIDATION_SECRET`: 32-512 character secret required by the bounded cache revalidation endpoint.

Production public URLs must use HTTPS except localhost development.

## Authentication bridge

The current bridge accepts an existing Forwext PAT/OAuth bearer token or API key, validates it server-side against API v1, and seals it with AES-256-GCM in an HttpOnly, SameSite=Lax cookie. The raw credential is never placed in browser JavaScript or a public Next.js environment variable.

Protected Server Components use `cache: "no-store"`. API scope and account permission checks remain authoritative in PHP.

The native PHP compatibility fallback continues to use the native PHP session/authentication and CSRF model for routes that are not implemented by the modern frontend.

## Data and caching

Public REST reads are tagged and revalidated with a short 30 second freshness window. Authenticated reads are never shared-cacheable.

`POST /api/revalidate` accepts only a fixed event allow-list and requires `X-Forwext-Revalidation-Secret`. Callers cannot request arbitrary paths or arbitrary cache tags.

`GET /api/health` performs a bounded, no-store backend API health check.

## SEO

The frontend uses the App Router Metadata API, dynamic metadata for forum/thread/member/Marketplace detail pages, robots directives and a canonical top-level sitemap.

Private account routes are marked noindex/nofollow.

## Development

From the repository root:

```bash
npm install --ignore-scripts
npm run sdk:build
npm run react-ui:build
npm run next-frontend:typecheck
npm run next-frontend:build
```

CI performs the same typecheck/build gate before producing native cPanel release packages. The native package intentionally excludes `frontend/next`; deploying the Node frontend is an explicit advanced deployment choice.
