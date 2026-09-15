<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateRoleGroupTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915194000_role_group_model');
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
            'CREATE TABLE IF NOT EXISTS `forwext_user_groups` ('
            . '`group_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`group_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`name` VARCHAR(100) NOT NULL, '
            . '`is_system` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`group_id`), '
            . 'UNIQUE KEY `uniq_forwext_group_key` (`group_key`), '
            . 'KEY `idx_forwext_group_sort` (`sort_order`, `group_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_roles` ('
            . '`role_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`role_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`name` VARCHAR(100) NOT NULL, '
            . '`kind` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`is_protected` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`priority` SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`role_id`), '
            . 'UNIQUE KEY `uniq_forwext_role_key` (`role_key`), '
            . 'KEY `idx_forwext_role_kind_priority` (`kind`, `priority`, `role_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_primary_groups` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`group_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`assigned_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`), '
            . 'KEY `idx_forwext_primary_group` (`group_id`, `user_id`), '
            . 'CONSTRAINT `fk_forwext_primary_group_user` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_primary_group_group` FOREIGN KEY (`group_id`) REFERENCES `forwext_user_groups` (`group_id`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_secondary_groups` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`group_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`assigned_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `group_id`), '
            . 'KEY `idx_forwext_secondary_group` (`group_id`, `user_id`), '
            . 'CONSTRAINT `fk_forwext_secondary_group_user` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_secondary_group_group` FOREIGN KEY (`group_id`) REFERENCES `forwext_user_groups` (`group_id`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_role_assignments` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`role_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`assigned_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `role_id`), '
            . 'KEY `idx_forwext_role_assignment` (`role_id`, `user_id`), '
            . 'CONSTRAINT `fk_forwext_role_assignment_user` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_role_assignment_role` FOREIGN KEY (`role_id`) REFERENCES `forwext_roles` (`role_id`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_user_groups', 'forwext_roles', 'forwext_user_primary_groups', 'forwext_user_secondary_groups', 'forwext_user_role_assignments')",
        ));
        $primaryMembershipKey = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_user_primary_groups\' '
            . 'AND `INDEX_NAME` = \'PRIMARY\' AND `COLUMN_NAME` = \'user_id\'',
        ));

        return (int) $tables === 5 && (int) $primaryMembershipKey === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Role/group model tables or primary-group uniqueness are missing.');
    }
}
