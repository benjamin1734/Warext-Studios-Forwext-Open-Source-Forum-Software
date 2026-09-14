# Forwext Migration / Install / Upgrade Engine

Status: **Normative implementation baseline**  
Roadmap step: **03.03 — Migration/install/upgrade motoru**

## Migration identity and ownership

Every migration has:

- owner scope: `core`, `module` or `addon`;
- validated owner name;
- sortable versioned id in `YYYYMMDDHHMMSS_slug` format;
- source fingerprint/checksum;
- explicit idempotency declaration;
- explicit transactional declaration;
- post-run verification.

Core/module/add-on migrations use the same engine/history semantics. The module/add-on lifecycle layer supplies the migrations owned by an installed/enabled package; it does not implement a second migration engine.

## Idempotency and history

Forwext executes only migrations declaring themselves idempotent. An applied migration with the same fingerprint is skipped. If an already-recorded migration's source fingerprint changes, migration execution stops with an integrity error instead of silently treating modified history as valid.

`forwext_migration_history` stores owner/id/checksum/status/batch/attempt/timestamps/duration and a stable non-secret failure code. States are `running`, `applied` and `failed`.

A process crash can leave a `running` record. Because migrations are required to be idempotent, the next run may retry it as a new attempt after fingerprint validation; migration authors must design DDL/data transformations accordingly. Where partial non-transactional work needs explicit compensation, implement `RecoverableMigration`.

## Transaction boundary

A migration explicitly declares whether it is transactional. Transactional migrations run through `TransactionalQueryExecutor::transaction()`.

This declaration is deliberately explicit because MySQL/MariaDB DDL may cause implicit commits and cannot be honestly advertised as fully rollback-safe. Non-transactional schema migrations therefore rely on idempotent operations, verification and, where necessary, recovery logic.

## Verification

Every migration runs `verify()` after `up()`. Verification is a machine decision about the target invariant—table/column/index/data state, not merely “SQL returned without exception”. A failed verification marks the attempt failed and prevents installed-version advancement.

Verification failure strings are diagnostic developer data; they must not embed secrets or user content.

## Failure recovery

When `up()` or verification throws/fails:

1. a `RecoverableMigration` gets one best-effort `recover()` invocation;
2. migration history is marked `failed` with a stable generic failure code;
3. the installed version is not advanced;
4. a generic `MigrationExecutionException` is rethrown with the original failure chained for masked/logged diagnostics.

Recovery failure never converts the migration to success.

## Install / upgrade version state

`InstallUpgradeEngine` coordinates migration completion with `InstalledVersionStore`.

- Install requires no installed-version state.
- Upgrade requires the exact declared source version.
- Upgrade target must be newer than source.
- Installed version is written **only after** all supplied migrations complete and verify.
- Version state is written atomically to protected runtime storage by the file driver.

This matches the release rule that update ZIPs refuse an unexpected source version before destructive changes and move persistent installations forward rather than resetting the database.

## Concurrency

The history model records a running attempt but this step does not pretend that row history alone is a cross-process migration lock. The installer/updater entry point must execute under the update/maintenance lock defined by the deployment/update workflow. A later distributed-lock driver may provide additional coordination. Running two migration engines concurrently against one site is unsupported and must be prevented by the caller.

## Security and data rules

- migration source is trusted application/module/add-on code already admitted by package policy;
- user input is never concatenated into migration SQL;
- history contains no database passwords/secrets;
- failure codes are generic and safe to persist;
- source fingerprint drift blocks execution;
- installed version is update-protected mutable site state;
- normal upgrade never resets existing data;
- destructive migrations require explicit application-specific safeguards/backups in addition to this engine.

## First real installation milestone

Completion of 03.03 makes the migration/install/upgrade infrastructure available for the first persistent server installation **once concrete core install migrations exist for the application schema being tested**. From that persistent installation onward, normal development advances schema/data with migrations rather than database resets.

## Acceptance status

03.03 is complete when versioned/idempotent migrations, persisted history, fingerprint verification, post-run verification, failure/recovery handling, core/module/add-on ownership and source→target installed-version coordination exist as real code with tests.
