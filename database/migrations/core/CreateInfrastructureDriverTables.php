<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateInfrastructureDriverTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260914203000_infrastructure_drivers');
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
            'CREATE TABLE IF NOT EXISTS `forwext_cache` ('
            . '`key_hash` CHAR(64) NOT NULL, `cache_key` VARCHAR(191) NOT NULL, `cache_value` LONGTEXT NOT NULL, '
            . '`expires_at_utc` DATETIME(6) NULL, PRIMARY KEY (`key_hash`), '
            . 'KEY `idx_forwext_cache_expiry` (`expires_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_cache_tags` ('
            . '`tag_hash` CHAR(64) NOT NULL, `tag_name` VARCHAR(128) NOT NULL, `key_hash` CHAR(64) NOT NULL, '
            . 'PRIMARY KEY (`tag_hash`, `key_hash`), KEY `idx_forwext_cache_tag_key` (`key_hash`), '
            . 'CONSTRAINT `fk_forwext_cache_tag_key` FOREIGN KEY (`key_hash`) REFERENCES `forwext_cache` (`key_hash`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_sessions` ('
            . '`session_hash` CHAR(64) NOT NULL, `session_id` VARCHAR(191) NOT NULL, `payload` LONGTEXT NOT NULL, '
            . '`expires_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`session_hash`), '
            . 'KEY `idx_forwext_session_expiry` (`expires_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_locks` ('
            . '`lock_hash` CHAR(64) NOT NULL, `lock_name` VARCHAR(191) NOT NULL, `token` CHAR(32) NOT NULL, '
            . '`expires_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`lock_hash`), '
            . 'KEY `idx_forwext_lock_expiry` (`expires_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $count = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN '
            . "('forwext_cache', 'forwext_cache_tags', 'forwext_sessions', 'forwext_locks')",
        ));

        return (int) $count === 4
            ? MigrationVerification::passed()
            : MigrationVerification::failed('One or more infrastructure driver tables are missing.');
    }
}
