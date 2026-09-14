<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateUserDomainTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260914233000_user_domain');
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
            'CREATE TABLE IF NOT EXISTS `forwext_users` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`username` VARCHAR(128) NOT NULL, '
            . '`username_key` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL, '
            . '`email` VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`email_key` VARCHAR(254) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`locale` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`timezone` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`version` INT UNSIGNED NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`), '
            . 'UNIQUE KEY `uniq_forwext_user_username_key` (`username_key`), '
            . 'UNIQUE KEY `uniq_forwext_user_email_key` (`email_key`), '
            . 'KEY `idx_forwext_user_status` (`status`), '
            . 'KEY `idx_forwext_user_updated` (`updated_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_custom_field_values` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`field_key` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`value_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`value_json` LONGTEXT NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `field_key`), '
            . 'CONSTRAINT `fk_forwext_user_custom_field_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_history` ('
            . '`history_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`event_type` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`changed_fields_json` TEXT NOT NULL, '
            . '`occurred_at_utc` DATETIME(6) NOT NULL, '
            . '`actor_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`from_status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`to_status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`reason_code` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'PRIMARY KEY (`history_id`), '
            . 'KEY `idx_forwext_user_history_user` (`user_id`, `history_id`), '
            . 'KEY `idx_forwext_user_history_actor` (`actor_user_id`), '
            . 'CONSTRAINT `fk_forwext_user_history_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_users', 'forwext_user_custom_field_values', 'forwext_user_history')",
        ));
        $uniqueIndexes = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(DISTINCT `INDEX_NAME`) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_users\' '
            . "AND `INDEX_NAME` IN ('uniq_forwext_user_username_key', 'uniq_forwext_user_email_key') AND `NON_UNIQUE` = 0",
        ));

        return (int) $tables === 3 && (int) $uniqueIndexes === 2
            ? MigrationVerification::passed()
            : MigrationVerification::failed('User domain tables or identity uniqueness indexes are missing.');
    }
}
