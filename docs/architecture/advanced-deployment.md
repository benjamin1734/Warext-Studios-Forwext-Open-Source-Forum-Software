# Advanced VDS / Docker deployment

Forwext keeps the shared-hosting/cPanel profile as the default. This document defines the optional advanced profile used by VDS, dedicated and container deployments.

## Runtime composition

The advanced runtime can select shared infrastructure without changing domain modules:

- Redis: cache, sessions, queue, distributed locks, scheduler claims and HTTP/API rate-limit buckets.
- S3-compatible object storage: shared public/private media using AWS Signature V4.
- Meilisearch: optional primary search with the native database index kept as the resilient fallback.
- Realtime: database-backed replay plus an optional Redis pub/sub WebSocket broadcast bus.

All credentials remain in the encrypted Forwext secret store. FORWEXT_MASTER_KEY may be supplied by the orchestrator; credentials must not be embedded in container images or committed environment files.

## Horizontal scaling contract

A multi-replica web deployment MUST NOT keep user/session/security state on node-local files. Use Redis for sessions, cache, queue, locks, scheduler claims and rate limiting. Use S3-compatible storage for mutable media. Every web replica uses the same database, Redis namespace, object store and search index.

The application image is immutable. config/generated.php, the encrypted secret store and installation-version state must be supplied consistently to all replicas. In a multi-host deployment use an external secret/config distribution mechanism rather than independent local Docker volumes.

The ACP transactional updater is intended for mutable single-host deployments. Immutable multi-replica deployments should build a new image from a verified full/update release, run the migration phase once under a deployment lock, then roll replicas to the new image.

## Health and traffic

- GET /health/live in the supplied Nginx profile verifies the front proxy/process path without touching the database.
- GET /health is the Forwext readiness endpoint. It checks the PHP runtime, database and required writable application paths. It returns HTTP 503 when unhealthy.
- Detailed health output remains disabled by default.

Load balancers should route traffic only to replicas whose readiness check passes.

## Workers and scheduler

bin/webhook-worker.php is a bounded worker. The advanced profile keeps it alive with the supplied loop under Docker, Supervisor or systemd. Multiple worker instances are safe because database/Redis queue reservations are exclusive.

Scheduled tasks may be invoked from system cron. Scheduler claims are stored in Redis in the advanced profile, so several scheduler launchers may race without executing the same minute-bucket task twice.

## Realtime / WebSocket

When realtime.mode=websocket, Forwext persists the event first and publishes a small replay pointer/envelope to the configured Redis broadcast channel. A dedicated authenticated WebSocket gateway may subscribe to this channel. The gateway must authorize each user/channel subscription before forwarding any event. Do not expose Redis or blindly broadcast private user channels to clients.

Polling/SSE remain the safe fallback and are the default in the reference Compose profile. The Nginx /ws upstream is intentionally inert until an authenticated gateway is configured.

## Object storage

Set storage.driver=s3 and configure bucket, endpoint, region and optional public base URL. Store storage.s3.access_key and storage.s3.secret_key through Forwext's secret-management UI/store. Public objects still require an object-store/CDN bucket policy that permits reads for the public prefix.

## Reference deployment

deploy/advanced/docker-compose.yml is a single-host reference topology: Nginx, PHP-FPM, worker, MySQL, Redis and Meilisearch. It is not a secrets manager and is not a substitute for managed backups, TLS termination, firewalling or multi-host orchestration.

For a classic VDS installation, use the Nginx template with PHP-FPM and install either the Supervisor or systemd worker unit. Point the web root strictly at public/.
