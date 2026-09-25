# System and integration ACP

Roadmap step 17.04 adds the native PHP administration surface for system and integration configuration at `/admin/integrations`.

## Scope

The surface covers the configuration contracts required by the roadmap:

- outgoing mail and SMTP;
- Google/Discord OAuth;
- Cloudflare Turnstile;
- AI moderation provider/model/endpoint credentials;
- local/S3-compatible storage preconfiguration;
- file/database/Redis cache and queue preferences where the existing runtime supports them;
- native/external search preconfiguration;
- polling/SSE/WebSocket realtime policy;
- API/webhook readiness flags and signing-secret reservation.

The versioned REST API and general signed webhook platform are still roadmap step 19 work. Their 17.04 switches are deliberately read-only and disabled so the ACP cannot imply that an unavailable platform is active.

## Permission model

The route requires an authenticated actor and the service requires both:

- `acp.access`;
- `integration.manage`.

`integration.manage` is granted by the built-in administrator permission template and denied by the new-user/member/verified/moderator templates.

Frontend visibility is not authorization. All save/reset/secret operations re-enter the backend service permission checks.

## Configuration precedence

For web requests the configuration order remains:

1. `config/defaults.php`;
2. `config/generated.php`;
3. `FORWEXT_CONFIG__...` environment overrides.

The ACP writes only the generated layer. Environment overrides are shown explicitly and continue to win. Reset removes only the generated override; it does not rewrite defaults or environment state.

The generated file is written by atomic replacement and protected by a cross-request lock so simultaneous ACP mutations cannot silently lose each other's changes.

Normal PHP web requests reload configuration on the next request. Long-lived advanced workers/gateways may require their normal process reload/restart after configuration changes.

## Secret handling

Secrets are never stored in `config/generated.php` and are never returned to the HTML surface.

The ACP only receives a boolean `configured` state. Secret inputs are always blank password controls with `autocomplete="new-password"`.

Secrets are persisted using the existing encrypted file secret store, which uses the installation master key, exclusive file locking, atomic replacement and restrictive file modes.

Administration audit events record only whether a secret was configured before/after the mutation. They never contain the secret value.

Secret deletion requires the administrator to type the exact integration key in addition to the normal CSRF-protected POST.

## Validation and SSRF/open-redirect boundaries

Typed settings apply bounded validation before they reach generated configuration.

Important policies include:

- absolute HTTPS URLs only for external endpoint settings;
- no URL credentials or fragments;
- HTTPS redirect allowlists for OAuth;
- same-origin path validation for WebSocket gateway paths;
- bounded integer ranges and list sizes;
- explicit enum allowlists;
- read-only flags for not-yet-installed API/webhook platform features.

Provider transports keep their own stronger destination/IP pinning rules where outbound network requests actually occur. The ACP does not make outbound requests merely because a URL was saved.

## ACP usability

The native surface provides:

- server-side search;
- section filter and quick section navigation;
- current effective value;
- environment-override warning;
- per-setting safe reset;
- availability notes for advanced/not-yet-composed drivers;
- masked secret status and replacement;
- runtime capability matrix;
- responsive layout without a JavaScript dependency.

This preserves the minimum cPanel deployment profile.

## Runtime composition boundaries

17.04 manages configuration contracts; it does not falsely advertise unavailable runtime compositions.

For example, the native WebApplicationFactory still composes local storage and native search by default, while S3/external-search provider contracts are available for advanced composition. The ACP surfaces those values as preconfiguration with explicit availability notes.

No Composer, npm, Node.js, SSH, Redis, Docker, Supervisor or WebSocket server becomes mandatory for the normal cPanel installation.
