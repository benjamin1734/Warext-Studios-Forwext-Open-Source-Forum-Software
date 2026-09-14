# Forwext migrations

Core migration classes live under a versioned migration namespace/directory defined by the feature that owns them. First-party module and third-party add-on migrations are discovered through their lifecycle manifests and supplied to the shared migration engine.

Migration IDs use `YYYYMMDDHHMMSS_slug` and are unique within their owner scope.

Every executable migration must be idempotent, define whether its operations are safe inside a database transaction, and implement post-run verification. Migrations that can compensate partially applied non-transactional work should implement `RecoverableMigration`.

Do not edit an already-applied migration in place. Its source fingerprint is recorded; a required correction is a new migration.
