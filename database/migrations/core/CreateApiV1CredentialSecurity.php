<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateApiV1CredentialSecurity implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260925185000_api_v1_credential_security');
    }

    public function owner(): MigrationOwner
    {
        return MigrationOwner::core();
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(MigrationContext $context): void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_api_v1_credentials ('
            . 'credential_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'credential_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'display_name VARCHAR(100) NOT NULL,'
            . 'secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'scopes_json TEXT NOT NULL,'
            . 'expires_at_utc DATETIME(6) NULL,'
            . 'revoked_at_utc DATETIME(6) NULL,'
            . 'last_used_at_utc DATETIME(6) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (credential_id),'
            . 'UNIQUE KEY uq_forwext_api_v1_secret_hash (secret_hash),'
            . 'KEY idx_forwext_api_v1_owner (owner_user_id,revoked_at_utc,created_at_utc),'
            . 'KEY idx_forwext_api_v1_expiry (expires_at_utc,revoked_at_utc),'
            . 'CONSTRAINT fk_forwext_api_v1_owner FOREIGN KEY (owner_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_api_v1_credentials'",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_api_v1_credentials' "
            . "AND INDEX_NAME IN ('uq_forwext_api_v1_secret_hash','idx_forwext_api_v1_owner','idx_forwext_api_v1_expiry')",
        ));
        $foreignKey = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_forwext_api_v1_owner'",
        ));

        return (int) $table === 1 && (int) $indexes === 3 && (int) $foreignKey === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('API v1 credential security schema is incomplete.');
    }
}
