# Forwext Deployment Profiles

## Shared / cPanel minimum profile

Goal: allow a typical shared-hosting user to upload a production ZIP, open `/install`, provide database/admin/site details and complete installation without shell access.

Required assumptions:

- PHP 8.4+ is selectable by the host.
- MySQL/MariaDB credentials are available.
- Required PHP extensions are available.
- The application can write to its configured runtime/storage paths.
- Web cron or cPanel cron can be configured for scheduled work.

Not required on this profile:

- Node.js
- npm
- Composer at runtime
- SSH
- Redis
- systemd/Supervisor
- persistent PHP workers
- external search engine
- S3/object storage
- WebSocket daemon

Default/fallback infrastructure:

- local/private filesystem storage
- database or supported file cache
- database-backed queue
- database/file/appropriate local session driver
- scheduled jobs triggered by cron/web-safe runner
- native MySQL/MariaDB search implementation
- polling/SSE-style realtime fallback where suitable

The installer and System Health page must detect extensions, disabled functions, file permissions, `proc_open`, sockets, CLI PHP, image libraries, ZIP, curl, mbstring, openssl, fileinfo and other declared capabilities. Optional missing capabilities disable or downgrade the dependent feature rather than causing unrelated forum boot failures.

## Advanced VDS / dedicated profile

May enable:

- PHP-FPM behind Nginx/Apache
- Redis cache
- Redis sessions
- Redis-backed queue
- persistent workers
- Supervisor/systemd
- dedicated realtime/WebSocket service
- S3-compatible object storage
- Meilisearch/OpenSearch or another approved search driver
- centralized logs/metrics

Domain modules cannot directly assume Redis, S3, process spawning or a specific search server. They depend on interfaces/capabilities.

## Container / cloud profile

The application remains stateless where practical, with shared mutable state moved to configured database/cache/session/object-storage drivers. Health/readiness endpoints, environment configuration and secrets must support container orchestration without embedding credentials into images.

## Web-server support contract

The project explicitly tests:

- Apache
- LiteSpeed
- Nginx
- root installation
- subdirectory installation
- friendly URLs enabled/disabled
- direct HTTPS
- reverse-proxy HTTPS termination
- Cloudflare-like forwarded proxy scenarios

Canonical URL generation and redirect behavior belong to the HTTP/router layer. Domain code must not manually infer web-server rewriting rules.

## Secure document root

Preferred deployment exposes only `/public` as the web document root. Application source, configuration, private storage, cache, logs and backups remain outside the public root.

Where shared hosting cannot change the document root, a documented compatibility mode may be provided using explicit deny rules and bootstrap forwarding. Compatibility mode may not silently expose private application files.

## Frontend deployment

Native PHP frontend is available on every supported profile.

React/Next.js support is optional. Development builds may require Node tooling; production PHP releases contain built assets. Sites choosing the official Next.js frontend follow its separate Node-capable deployment profile.
