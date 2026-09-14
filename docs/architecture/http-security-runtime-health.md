# Forwext HTTP Security & Runtime Health Baseline

Status: **Normative implementation baseline**  
Roadmap step: **02.07 — HTTP güvenliği ve runtime health**

This step establishes shared HTTP-security primitives and a cPanel-compatible runtime-health baseline. These mechanisms are infrastructure boundaries; matching a route or passing middleware never grants domain permission.

## Recommended global middleware order

The baseline ordering contract is:

```text
RequestIdMiddleware
  -> SecurityHeadersMiddleware
    -> ErrorHandlerMiddleware
      -> CanonicalUrlMiddleware
        -> TrustedHostMiddleware
          -> RequestLoggingMiddleware
            -> CorsMiddleware (where cross-origin API access is enabled)
              -> RateLimitMiddleware
                -> Router
                  -> route/group middleware such as CsrfMiddleware
```

The exact registered set may vary by frontend/route group, but the security dependency is deliberate:

- request ID exists before errors are handled;
- security headers can wrap normal and handled-error responses;
- error handling can catch canonical/proxy/router/application exceptions;
- trusted proxy resolution occurs before trusted-host/rate-limit/access-log context is consumed;
- CORS preflight can terminate before application work;
- CSRF is attached to browser/session state-changing route groups, not blindly to stateless machine APIs.

## CSRF

`CsrfTokenManager` issues authenticated HMAC-SHA256 tokens bound to:

- a cryptographically random browser context identifier;
- a logical scope such as member/admin/browser flow;
- issuance timestamp and bounded lifetime;
- a fresh random nonce.

`CsrfMiddleware` uses a Secure, HttpOnly, SameSite=Lax `__Host-` context cookie by default. Safe requests can receive a current token through request attributes. Unsafe requests require the matching context cookie plus a token supplied through `X-CSRF-Token` or `_csrf` form input.

Invalid, expired, wrong-scope, wrong-context or tampered tokens fail with 403 and `Cache-Control: no-store`.

CSRF is not an authentication substitute. Authentication/session rotation and permission checks remain separate requirements.

## CORS

CORS is deny-by-default. `CorsPolicy` accepts explicit http(s) origins, methods and request headers. Credentialed CORS cannot use wildcard origin.

Preflight requests are validated before a 204 response is returned. Invalid origins, methods or requested headers return 403. Allowed responses include `Vary: Origin` so shared caches do not mix origin-specific policy.

CORS is not a CSRF or permission mechanism; an origin being allowed does not make a user/action authorized.

## Rate limiting

Rate limiting uses a driver contract (`RateLimitStore`) and fixed-window policy baseline.

Two drivers exist at this step:

- in-memory driver for deterministic tests/process-local usage;
- locked file driver for standard cPanel installations without Redis/persistent workers.

The file driver hashes policy+identity into non-user-controlled filenames, uses restrictive directories/files, rejects symbolic-link state files, locks updates and fails closed on corrupt state.

`RateLimitMiddleware` uses the trusted effective client IP by default and emits 429, `Retry-After` and rate-limit metadata when blocked.

This is the baseline transport limiter. More specialized member/action/resource keys can supply an explicit key resolver later. Distributed/cache-backed drivers can implement the same contract without weakening cPanel support.

## Trusted host

`TrustedHostPolicy` accepts exact hosts and deliberate `*.example.com`-style subdomain patterns. `TrustedHostMiddleware` evaluates the effective host produced after trusted-proxy resolution and returns 421 for an untrusted host.

Production installation/configuration must populate trusted hosts from the canonical site configuration. An arbitrary inbound `Host` or untrusted forwarded host never becomes trusted merely because routing succeeded.

## Security headers

The default response baseline includes:

- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- restrictive `Permissions-Policy` defaults;
- central Content Security Policy with `default-src 'self'`, `base-uri 'self'`, `object-src 'none'` and `frame-ancestors 'self'`;
- `X-Frame-Options: SAMEORIGIN` as legacy defense-in-depth;
- `X-Permitted-Cross-Domain-Policies: none`;
- HSTS only when the trusted effective request scheme is HTTPS.

Themes/modules/integrations must extend the central CSP intentionally. They may not disable security headers ad hoc because a third-party asset fails to load.

## Structured logs and request correlation

`StructuredLogger` is the shared logging contract. `JsonFileLogger` provides a standard-host JSON-lines driver with exclusive append locking and restrictive permissions. Secret masking occurs before persistence.

`RequestLoggingMiddleware` records request ID, method, path **without query string**, route when available, trusted effective client IP, status and duration. Query strings are deliberately excluded from access-log context because they may contain reset tokens, OAuth codes or other sensitive values.

`ErrorHandlerMiddleware` catches unhandled throwables, emits a correlated structured error and returns a generic production 500 response. Production output never contains exception messages/traces. Debug output is explicit and still applies registered secret masking.

If structured logging itself fails while handling an exception, the fallback system log message is generic and does not embed the original exception/secret.

## Debug behavior

`app.debug` is false by default. Production deployments must keep debug disabled. Debug is a diagnostics mode, not a permission bypass, and it does not disable masking, CSRF, host checks or other security controls.

Detailed debug pages/tooling added later must follow the same rule.

## Health baseline

Health checks implement the `HealthCheck` contract and aggregate into `healthy`, `degraded` or `unhealthy` status.

Baseline reusable checks include:

- PHP version/required extension health;
- required writable runtime-directory health;
- future database/cache/queue/search/provider checks through the same interface.

The public `HealthHandler` is minimal by default and returns only the aggregate status. `unhealthy` maps to HTTP 503; healthy/degraded maps to HTTP 200. Detailed check names/messages/details are opt-in and should be exposed only to a protected/internal/admin diagnostics route.

Thrown health checks become a generic unhealthy result; exception text is not leaked to the public health payload.

## cPanel and advanced deployments

The baseline does not require Redis, Node.js or a persistent worker. Standard cPanel deployments can use the file-backed log/rate-limit drivers.

Advanced deployments may replace drivers through DI when later infrastructure drivers are implemented. Driver changes must preserve semantic behavior, atomicity/security expectations and the simple-by-default hosting contract.

## Acceptance status

02.07 is complete when CSRF, CORS, rate limiting, trusted-host validation, security headers, structured logging/error handling, request correlation and health primitives exist as real code with edge-case tests and documented composition rules.

No database migration is required for this step.
