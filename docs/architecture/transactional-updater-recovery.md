# Transactional updater, migration, backup and rollback

Forwext 20.03 defines the production update execution path used by the native ACP on the cPanel-first runtime. It consumes only differential update ZIPs that satisfy the immutable release-package contract from 20.02.

## Safety sequence

The updater executes in this order:

1. Inspect the ZIP without extracting it to the application tree.
2. Validate the manifest schema, source/target semantic versions, sorted operations, protected preserve paths, payload/checksum coverage, migration metadata and supported rebuild actions.
3. Compare the manifest source version with the installed-version store.
4. Acquire the global `forwext.update` lock and validate the installed source version again under the lock.
5. Create and verify a logical database backup before any application file is changed.
6. Enter update maintenance mode with an owner token, source version, target version and UTC start time.
7. Snapshot every file that will be replaced/deleted, then publish add/replace operations atomically and apply deletes.
8. Run registered idempotent migrations through the normal migration history engine and atomically advance the installed-version state.
9. Run only manifest-declared rebuild actions from the fixed runtime registry.
10. Execute the normal application health checks.
11. Release update maintenance mode only after migration, rebuild and health verification succeed.
12. Release the global update lock in a `finally` path.

An unhealthy health result is a failed update. A degraded result is reported but does not trigger rollback.

## Differential package validation

`UpdatePackageInspector` treats an uploaded ZIP as hostile input. It rejects traversal paths, backslashes, NUL bytes, duplicate entries, symbolic links, unsafe file counts/sizes, missing manifests, unknown checksum algorithms, payload/manifest disagreement and checksum mismatches.

The following runtime-owned mutable paths may not be added, replaced or deleted by an update package:

- `config/generated.php`
- `config/secret.key`
- `public/storage/**`
- `storage/backups/**`
- `storage/files/**`
- `storage/install/installed-version.json`
- `storage/logs/**`
- `storage/secrets/**`

The exact preserve contract is also required in the update manifest.

## File transaction

`UpdateFileTransaction` never extracts an archive directly into the project root. Package payload is read by name after validation and written through temporary files followed by rename.

Before mutations begin it creates a restricted snapshot under `storage/backups/update-files`. Replaced/deleted files are copied with SHA-256 metadata and added files are recorded. Rollback verifies the snapshot manifest and backup hashes before removing added files and restoring original payloads.

Application and snapshot paths reject symbolic-link traversal.

## Database backup and migration

`SystemBackupService` creates a restricted logical JSONL backup of Forwext tables from a repeatable-read, read-only transaction. The backup is verified before maintenance/file mutation begins.

Migrations continue to use the existing migration history/fingerprint engine. The updater does not bypass idempotency, integrity or verification rules. The installed-version store advances only through `InstallUpgradeEngine`.

## Rebuilds

The update manifest cannot invoke arbitrary PHP callables. It may reference only fixed actions registered by `UpdateRuntimeRebuildService`:

- `cache.clear`
- `search.index.rebuild`

Cache clearing rejects symlink roots and recursively removes only the configured cache tree. Search rebuild uses the registered search content sources, bounded batches and a hard processed-document safety limit, and rejects non-progressing cursors.

## Failure recovery

Any failure after files were applied triggers the recovery sequence while maintenance remains active:

1. Restore the verified pre-update database backup.
2. Reset the installed-version state to the source version.
3. Verify and restore the application file snapshot.
4. Release maintenance mode only when all recovery actions succeeded.

If any recovery action fails, the updater deliberately leaves maintenance mode active and reports incomplete automatic recovery. This prevents serving a mixed schema/code state.

A failure before migrations start rolls back the file transaction and releases maintenance mode. The global update lock is always released.

## ACP authorization and upload staging

The update form is available only through the native ACP operations service. The actor must hold the existing backup, maintenance and repair management permissions in addition to ACP access. Permission checks are server-side and are not replaced by UI visibility.

The handler requires typed confirmation, accepts a bounded successful ZIP upload, stages it under `storage/update/incoming` with restrictive permissions, invokes the updater, then removes the staged package in a `finally` path. Success/failure is audit recorded without logging ZIP contents or secrets.

## cPanel-first operation

The updater runs synchronously inside the authenticated ACP request and depends only on normal PHP/runtime facilities already required by the release profile plus the ZIP extension for applying update archives. Composer, npm, Node.js, SSH, Redis, Docker and Supervisor are not required.

The public entry point returns HTTP 503 while an update maintenance lease is active. The current update request can finish because the lease is created after the request has entered the application.
