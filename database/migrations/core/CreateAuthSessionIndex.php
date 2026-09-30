<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAuthSessionIndex implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260930121500_auth_session_index');
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
            'CREATE TABLE IF NOT EXISTS `forwext_auth_session_index` ('
            . '`session_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`device_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`credential_version` INT UNSIGNED NOT NULL, '
            . '`issued_at_utc` DATETIME(6) NOT NULL, `expires_at_utc` DATETIME(6) NOT NULL, '
            . '`last_seen_at_utc` DATETIME(6) NOT NULL, `revoked_at_utc` DATETIME(6) NULL, '
            . 'PRIMARY KEY (`session_hash`), '
            . 'KEY `idx_auth_session_user_active` (`user_id`, `revoked_at_utc`, `expires_at_utc`), '
            . 'KEY `idx_auth_session_expiry` (`expires_at_utc`), '
            . 'CONSTRAINT `fk_auth_session_index_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` = 'forwext_auth_session_index'",
        ));
        $foreignKey = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `CONSTRAINT_NAME` = 'fk_auth_session_index_user'",
        ));
        $rawIdColumn = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` = 'forwext_auth_session_index' AND `COLUMN_NAME` = 'session_id'",
        ));

        return (int) $table === 1 && (int) $foreignKey === 1 && (int) $rawIdColumn === 0
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Authentication session index schema is incomplete or stores raw session ids.');
    }
}
