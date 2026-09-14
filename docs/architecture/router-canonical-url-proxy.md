# Forwext Router, Canonical URL & Trusted Proxy Contract

Status: **Normative implementation baseline**  
Roadmap step: **02.06 — Router, canonical URL ve proxy**

## Router and named routes

Forwext routes are registered with a stable unique name, one or more typed HTTP methods, a path template, a request handler and optional route middleware.

Path parameters occupy a complete segment (`/konu/{slug}`), are percent-decoded only after segment boundaries are established, and may have explicit regex requirements. Encoded `/`, encoded `\\`, dot-segments, malformed percent encoding, invalid UTF-8 and control characters are rejected so one URL cannot be interpreted as two different route structures by different layers.

Static routes outrank dynamic routes. If two equally specific routes both match the same method/path, dispatch fails as an ambiguous developer configuration instead of silently depending on registration order.

GET routes also satisfy HEAD matching. A known path with the wrong method returns 405 plus `Allow`; an unknown path returns 404.

## Friendly URL generation

`UrlGenerator` generates URLs by route name rather than controllers concatenating strings. Every parameter is requirement-checked and percent-encoded as one segment. Missing/extra/unsafe parameters fail loudly.

Query strings are encoded with RFC 3986 semantics. Absolute URLs require the configured canonical URL.

## Subfolder installation

`BasePath` is the common subfolder contract for matching and generation. For an installation at `/community`:

- request `/community/konu/test` is matched internally as `/konu/test`;
- generated route `/konu/test` becomes `/community/konu/test`;
- `/community-other/...` does not accidentally match the installation;
- the canonical URL and URL generator must use the same base path.

The empty/root base path remains first-class.

## Canonical URL

`CanonicalUrl` is an explicitly configured origin plus optional installation base path, for example `https://forum.example.com/community`.

It rejects credentials, query/fragment components and unsupported schemes. URL generation never derives its canonical host from an arbitrary request `Host` or forwarding header.

`CanonicalUrlMiddleware` compares the resolved request origin with this fixed canonical origin. A mismatch uses a fixed-target 308 redirect and preserves the request target path/query. Because the destination origin comes from configuration, request headers cannot create an open redirect.

## HTTPS and redirect-loop protection

Direct HTTP traffic can be redirected to the configured HTTPS canonical origin.

When TLS terminates at a reverse proxy, HTTPS state is taken from forwarding headers **only if the direct peer IP is configured as trusted**. This prevents the classic endless HTTPS redirect caused by treating every backend connection as plain HTTP while also preventing clients from spoofing proxy headers.

If an untrusted direct peer supplies forwarding headers while the direct origin differs from canonical, Forwext fails with a non-cacheable 400 instead of trusting the header or entering a redirect loop. Later HTTP error handling may replace this minimal response without changing the security rule.

## Trusted proxy and client IP

Trusted proxies are explicit IPv4/IPv6 CIDR sets. `X-Forwarded-For` and RFC `Forwarded` are consumed only from a trusted direct peer. Address chains are evaluated right-to-left, skipping configured trusted proxy addresses until the first untrusted address is found; that address is the effective client IP.

`X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port` and the equivalent `proto`/`host` fields from RFC `Forwarded` are likewise ignored unless the direct peer is trusted. Malformed values from a trusted proxy fail closed rather than being guessed.

When both formats are supplied, the explicit `X-Forwarded-*` values take precedence for compatibility with common reverse-proxy/CDN deployments.

## Cloudflare

Cloudflare support is explicit rather than header-based trust:

- a request is considered Cloudflare-proxied only when `REMOTE_ADDR` belongs to the configured Cloudflare CIDR set;
- only then may `CF-Connecting-IP` identify the client;
- only then may `CF-Visitor` provide scheme fallback;
- ordinary clients sending `CF-*` headers gain no trust.

Forwext does not hard-code a forever-static Cloudflare network list in core. Deployment/installer tooling must populate reviewed current Cloudflare ranges into configuration and future maintenance tooling may refresh/verify them. This avoids silently trusting stale ownership ranges.

## Permission and security impact

Routing never grants permission. A matched route still reaches application/domain services that enforce authorization.

Effective client IP/scheme/host values are transport context for logging, rate limiting, cookie/security policy and canonicalization; they are not identity or authentication claims.

Host allow-list enforcement, CSRF, CORS, rate limiting, security headers, structured error/log handling and health endpoints are deliberately completed at 02.07, matching the binding roadmap rather than duplicating partial security systems here.

No database migration is required for 02.06.
