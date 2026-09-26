# Forwext threat model

Roadmap step 20.06 defines the release security-qualification boundary for the Forwext 1.0 product line. This document describes security assumptions and the controls that must remain covered by automated tests.

## Assets

- User credentials, session identifiers, recovery factors and OAuth identities.
- Private conversations, notifications, support tickets, bug reports and moderation records.
- API credentials, webhook signing secrets, SMTP/payment/integration credentials and the application master key.
- Uploaded files and generated private/public storage objects.
- Marketplace/order/payment state, permissions, roles and administrator configuration.
- Release/update packages, migrations, backups and immutable release metadata.

## Trust boundaries

- Browser or API client to the native PHP/REST application.
- Optional Next.js frontend to the PHP REST backend.
- Application to MySQL/MariaDB.
- Application to filesystem, object storage, Redis, Meilisearch and queue workers.
- Application to OAuth providers, payment providers, webhook destinations and link-preview targets.
- Administrator/add-on input to privileged configuration and extension points.
- Release artifacts entering the updater and migration boundary.

## SQL injection

Dynamic values must stay in prepared-statement parameters. Dynamic identifiers must pass the strict SqlIdentifier allowlist and quoting boundary. Qualification includes hostile identifier payloads and the database abstraction regression suites.

## Cross-site scripting

Untrusted user-visible strings must be escaped at HTML output boundaries. Rich-content rendering must use the existing sanitization pipeline rather than raw string interpolation. Qualification executes malicious script/event-handler payloads against representative native web rendering.

## CSRF

Unsafe browser requests require context-bound, scoped, expiring CSRF tokens. Qualification verifies valid and tampered request behavior and the secure host-only CSRF cookie contract.

## SSRF

Outbound link previews, AI/provider transports and webhooks must reject local/private/reserved destinations, restrict schemes/ports, pin resolved public addresses where applicable, verify TLS/SNI and avoid redirect-based policy bypass. Qualification exercises link-preview and webhook destination controls.

## IDOR/BOLA

Object access is authorized on the server using the effective account permission model and ownership boundaries. API scopes never replace account authorization. Qualification covers API account-permission denial, forum-node authorization and the permission security matrix.

## Upload and path traversal

Upload metadata, filenames and storage paths must not permit traversal, executable-path escape or unsafe content acceptance. Qualification covers upload normalization and attachment inspection boundaries; update package path traversal remains covered by updater/release tests.

## OAuth account linking

OAuth linking uses provider state/PKCE transaction data, authenticated account context and collision-safe connected-account rules. Qualification executes the connected-account service regression suite.

## Session security

Authentication sessions are server-side, rotation/revocation aware and do not allow password/session state to be restored by stale credentials. Qualification executes password/session security contracts; advanced Redis sessions preserve the same semantic boundary.

## Webhook security

Outbound webhooks use HTTPS-only approved destinations, per-delivery signing, versioned secret rotation, bounded retry/backoff and SSRF-safe pinned transports. Raw signing secrets must not appear in delivery logs.

## Secret leakage

Secret material belongs in the encrypted SecretStore or approved environment/key providers. Logs and structured contexts use masking; generated installation state and release packages must not expose plaintext credentials. Qualification runs encryption, tamper and masking regression tests.

## Dependency supply chain

Security CI resolves the PHP and JavaScript dependency graphs and runs Composer and npm advisory scans. Add-on package signature/capability controls and immutable release artifacts remain separate defense layers.

## Residual risk

Automated qualification reduces known regression classes but is not a proof of absence of vulnerabilities. Production operators must protect the host, database, master key, backups and provider credentials, apply supported releases, restrict administrator access and review third-party add-ons. A critical/high security issue blocks final 1.0.0 acceptance until fixed or explicitly removed from the affected release surface.
