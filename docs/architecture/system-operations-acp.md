# System Operations ACP

Roadmap step 17.05 adds the native PHP system operations center at /admin/system/operations.

## Permission model

The surface requires the existing acp.access permission and then evaluates each subsystem independently:

- system.health.view: health, capabilities and migration/database integrity.
- system.logs.view: bounded structured-log tail.
- system.jobs.manage: queue status, failed-job retry/delete and manual enqueue of registered scheduled tasks.
- system.backup.manage: create, verify and delete logical backups.
- system.maintenance.manage: change site maintenance mode.
- system.repair.manage: bounded cache and scheduler-claim repair tools.

Built-in administrator receives allow. New user, member, verified and moderator templates receive explicit deny. Rendering a button is never treated as authorization.

## Health, capabilities and integrity

The dashboard reuses HealthService, RuntimeEnvironmentHealthCheck, WritableDirectoryHealthCheck and CapabilityResolver. Database connectivity is checked through a dedicated read-only health check.

Integrity compares the current CoreMigrationRegistry with core migration history and reports missing, failed, running and unknown core migrations. It also reports non-InnoDB Forwext base tables. Integrity checks are diagnostic only and never automatically rewrite schema.

## Logs

Structured logs are read only from the configured logging path. The reader:

- rejects symbolic-link log files;
- reads at most the last 2 MiB;
- returns at most 250 records;
- ignores malformed JSON lines;
- applies a second defensive redaction pass to password, secret, token, authorization, cookie and private-key style context keys.

Log file paths are never accepted from request parameters.

## Jobs and cron

The ACP exposes queue counts without payloads. Failed-job lists contain job id, queue, job type, attempts, failure code and timestamp only.

Retry obtains the failed row under a database lock, moves the original payload back into the queue inside the audited database transaction and deletes the failed record. The payload itself never enters HTML or audit snapshots.

Manual cron execution is restricted to the typed first-party scheduler registry. It enqueues the registered task through QueueDriver rather than invoking job handlers directly.

The registry currently includes attachment cleanup, search drain, thread freshness, giveaway lifecycle, referral qualification, abuse retention, analytics retention, reward retry, promotion evaluation and trophy evaluation.

## Logical backups

Backups live under the configured operations.backup_path, outside the public web root by default. The directory and files reject symbolic-link use and use restrictive filesystem modes.

A backup is an append-only JSONL logical snapshot containing:

- a format/version header;
- SHOW CREATE TABLE schema for each Forwext base table;
- every row encoded in byte-safe typed form, with strings/blobs stored as base64;
- a manifest containing table and row counts.

Creation uses a read-only repeatable-read transaction. Verification parses the complete file, validates the manifest and calculates SHA-256. The ACP deliberately provides no backup download or restore endpoint; server-side backup custody avoids accidental database disclosure and destructive restore operations.

## Maintenance and repair

Maintenance mode is stored through the existing atomic GeneratedConfigStore at app.maintenance. When FORWEXT_CONFIG__APP__MAINTENANCE is present, ACP changes fail closed because the environment remains authoritative.

Repair tools are intentionally bounded:

- stale scheduler claim pruning uses a bounded retention window and delete limit;
- cache cleanup removes contents only, keeps the configured cache root and never follows symbolic links.

All mutating system operations use the Administration audit stream and the dedicated system-operations CSRF context.

## Deployment

No Composer, npm, Node.js, Redis, worker, WebSocket, Docker, SSH or Supervisor runtime becomes mandatory. Database queue/scheduler and native PHP remain the minimum cPanel path. Redis and other advanced drivers remain optional.
