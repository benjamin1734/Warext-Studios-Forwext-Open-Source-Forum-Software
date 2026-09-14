# Forwext Database Connection & Typed Query Layer

Status: **Normative implementation baseline**  
Roadmap step: **03.01 — Database connection/query builder**

## Runtime database target

The Forwext 1.x database baseline is MySQL through PDO (`pdo_mysql`) with PHP 8.4+. Production configuration uses `utf8mb4` by default and native prepared statements (`PDO::ATTR_EMULATE_PREPARES = false`). Persistent PDO connections are disabled by default so standard cPanel/FPM request lifecycles remain predictable.

Database credentials are split from ordinary configuration. Host/port/database/user/charset may live in generated site configuration, while the password is referenced by a secret-store key such as `database.password` and must be retrieved from the encrypted secret system before constructing `DatabaseConfig`.

PDO connection/query exceptions are wrapped in generic Forwext database exceptions so user-facing error handling does not include SQL text, credentials or bound values.

## Prepared-statement rule

Application/module/add-on database code must not concatenate user/domain values into SQL.

`CompiledQuery` separates SQL from a typed parameter map. `DatabaseConnection` prepares the statement and binds null/bool/int/string-compatible values with explicit PDO parameter types. Float values are transported as bound string values for portable PDO/MySQL numeric conversion rather than interpolated into SQL.

Raw SQL may still be used internally for fixed framework operations/migrations where required, but dynamic values must remain parameters and identifiers must not come from request input.

## Typed query builder

The baseline builder provides typed SELECT, INSERT, UPDATE and DELETE construction.

Identifiers pass `SqlIdentifier` validation and are quoted separately from values. Unsafe identifiers such as SQL fragments, whitespace, delimiters or comments are rejected rather than escaped as arbitrary SQL.

The WHERE builder currently supports:

- typed comparison operators (`=`, `<>`, `>`, `>=`, `<`, `<=`);
- null-aware equality/inequality;
- `IN` / `NOT IN` with bound values;
- `IS NULL` / `IS NOT NULL`;
- deterministic empty-IN semantics.

There is intentionally no public `whereRaw(userInput)` escape hatch in the baseline.

UPDATE and DELETE reject full-table operations unless the caller explicitly opts in with `allowAllRows()`. This protects administrative/background code from accidental mass mutation.

## SELECT, ordering and row locks

SELECT supports validated columns/table aliases, ordering, bounded LIMIT/OFFSET and row-lock modes.

- `forUpdate()` emits `FOR UPDATE`;
- `forShare()` emits MySQL/MariaDB-compatible `LOCK IN SHARE MODE`;
- lock-marked compiled queries set `requiresTransaction=true`;
- `DatabaseConnection` refuses to prepare such a query unless a Forwext transaction is active.

This prevents code from believing a row is protected while autocommit would immediately release the lock.

## Transactions

`DatabaseConnection::transaction()` owns transaction lifecycle. Nested calls use deterministic savepoints so inner failures can roll back their work without necessarily aborting an outer transaction when the caller handles the exception.

The transaction depth is internal and cannot be changed by query code. PDO errors are wrapped; non-database application exceptions are rolled back and rethrown unchanged.

Long-running network calls or user interaction must not occur inside database transactions.

## Pagination

`Paginator` accepts a typed SELECT plus validated `PageRequest`.

It performs a separate `COUNT(*)` query using the same table/WHERE criteria, then runs a cloned query with LIMIT/OFFSET. Page size is bounded and offset overflow fails closed.

The baseline paginator intentionally targets simple single-table SELECTs. Future aggregate/join/search queries may provide specialized count strategies rather than pretending a naïve COUNT is correct for every SQL shape.

## Optimistic locking

`OptimisticLockingUpdater` implements compare-and-swap style updates:

```text
UPDATE table
SET changed_fields..., version = expected + 1
WHERE id = requested_id AND version = expected
```

Exactly one affected row is required. Zero rows means stale/missing state and more than one row means a data invariant problem; either case raises `OptimisticLockException`.

The identity/version columns cannot be supplied in the caller's change set, and version overflow is rejected.

## Testing and portability

Builder/pagination/optimistic-lock tests do not need a database server. Connection/transaction tests use in-memory `pdo_sqlite` when that optional test driver is available and skip only those isolated integration tests otherwise.

Production behavior remains `pdo_mysql`; SQLite is not a supported Forwext production database and is used only to exercise generic PDO prepared/transaction behavior without requiring a network database in unit CI.

## Security and permission impact

Prepared statements and identifier validation reduce SQL-injection risk but do not replace authorization. Repository/application services must still enforce whether the actor may read/update/delete the selected domain object.

Pagination and row locks must not be used as an IDOR/BOLA boundary. A successfully located row is not automatically authorized.

No schema migration is required for 03.01 because this step creates database infrastructure; persistent Forwext schema begins with the migration/install engine work that follows.
