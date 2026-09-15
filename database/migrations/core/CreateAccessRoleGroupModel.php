<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAccessRoleGroupModel implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915170000_access_role_group_model');
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
            'CREATE TABLE IF NOT EXISTS `forwext_groups` ('
            . '`group_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`name` VARCHAR(191) NOT NULL,`description` TEXT NOT NULL,'
            . '`is_system` TINYINT(1) NOT NULL DEFAULT 0,`is_active` TINYINT(1) NOT NULL DEFAULT 1,'
            . '`sort_order` INT NOT NULL DEFAULT 100,`created_at_utc` DATETIME(6) NOT NULL,'
            . '`updated_at_utc` DATETIME(6) NOT NULL,PRIMARY KEY (`group_key`),'
            . 'KEY `idx_groups_order` (`is_active`,`sort_order`,`group_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'INSERT INTO `forwext_groups` '
            . '(`group_key`,`name`,`description`,`is_system`,`is_active`,`sort_order`,`created_at_utc`,`updated_at_utc`) '
            . "VALUES ('registered','Registered','Default primary group for registered Forwext users.',1,1,100,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE `group_key`=VALUES(`group_key`)',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_roles` ('
            . '`role_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`name` VARCHAR(191) NOT NULL,`description` TEXT NOT NULL,'
            . '`role_kind` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`is_active` TINYINT(1) NOT NULL DEFAULT 1,`sort_order` INT NOT NULL DEFAULT 100,'
            . '`created_at_utc` DATETIME(6) NOT NULL,`updated_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`role_key`),KEY `idx_roles_kind_order` (`role_kind`,`is_active`,`sort_order`,`role_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        if (!$this->columnExists($context, 'forwext_users', 'primary_group_key')) {
            $context->execute(new CompiledQuery(
                "ALTER TABLE `forwext_users` ADD COLUMN `primary_group_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'registered' AFTER `status`",
            ));
        }
        if (!$this->indexExists($context, 'forwext_users', 'idx_users_primary_group')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_users` ADD KEY `idx_users_primary_group` (`primary_group_key`)',
            ));
        }
        if (!$this->constraintExists($context, 'forwext_users', 'fk_users_primary_group')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_users` ADD CONSTRAINT `fk_users_primary_group` '
                . 'FOREIGN KEY (`primary_group_key`) REFERENCES `forwext_groups` (`group_key`) '
                . 'ON DELETE RESTRICT ON UPDATE RESTRICT',
            ));
        }

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_secondary_groups` ('
            . '`user_id` CHAR(32) NOT NULL,'
            . '`group_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`assigned_at_utc` DATETIME(6) NOT NULL,`assigned_by_user_id` CHAR(32) NULL,'
            . 'PRIMARY KEY (`user_id`,`group_key`),KEY `idx_secondary_group_users` (`group_key`,`user_id`),'
            . 'CONSTRAINT `fk_secondary_group_user` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE,'
            . 'CONSTRAINT `fk_secondary_group_group` FOREIGN KEY (`group_key`) REFERENCES `forwext_groups` (`group_key`) ON DELETE RESTRICT,'
            . 'CONSTRAINT `fk_secondary_group_actor` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_roles` ('
            . '`user_id` CHAR(32) NOT NULL,'
            . '`role_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`assignment_source` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`assigned_at_utc` DATETIME(6) NOT NULL,`assigned_by_user_id` CHAR(32) NULL,'
            . 'PRIMARY KEY (`user_id`,`role_key`),KEY `idx_user_roles_role` (`role_key`,`user_id`),'
            . 'CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE,'
            . 'CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_key`) REFERENCES `forwext_roles` (`role_key`) ON DELETE RESTRICT,'
            . 'CONSTRAINT `fk_user_roles_actor` FOREIGN KEY (`assigned_by_user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_access_history` ('
            . '`event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`event_type` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`target_user_id` CHAR(32) NULL,`subject_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`subject_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`previous_subject_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`assignment_source` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`actor_user_id` CHAR(32) NULL,`reason_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`occurred_at_utc` DATETIME(6) NOT NULL,PRIMARY KEY (`event_id`),'
            . 'KEY `idx_access_history_target` (`target_user_id`,`occurred_at_utc`),'
            . 'KEY `idx_access_history_subject` (`subject_type`,`subject_key`,`occurred_at_utc`),'
            . 'KEY `idx_access_history_actor` (`actor_user_id`,`occurred_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_groups','forwext_roles','forwext_user_secondary_groups','forwext_user_roles','forwext_access_history')",
        ));
        if ((int) $tables !== 5) {
            return MigrationVerification::failed('Role/group access tables are missing.');
        }
        if (!$this->columnExists($context, 'forwext_users', 'primary_group_key')) {
            return MigrationVerification::failed('User primary group column is missing.');
        }
        if (!$this->constraintExists($context, 'forwext_users', 'fk_users_primary_group')) {
            return MigrationVerification::failed('User primary group foreign key is missing.');
        }
        $registered = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_groups` WHERE `group_key`='registered' AND `is_system`=1",
        ));
        if ((int) $registered !== 1) {
            return MigrationVerification::failed('Registered system group is missing.');
        }
        $invalidUsers = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_users` u LEFT JOIN `forwext_groups` g '
            . 'ON g.`group_key`=u.`primary_group_key` WHERE g.`group_key` IS NULL',
        ));
        return (int) $invalidUsers === 0
            ? MigrationVerification::passed()
            : MigrationVerification::failed('One or more users do not have a valid primary group.');
    }

    private function columnExists(MigrationContext $context, string $table, string $column): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA`=DATABASE() '
            . 'AND `TABLE_NAME`=:table_name AND `COLUMN_NAME`=:column_name',
            ['table_name' => $table, 'column_name' => $column],
        )) > 0;
    }

    private function indexExists(MigrationContext $context, string $table, string $index): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` WHERE `TABLE_SCHEMA`=DATABASE() '
            . 'AND `TABLE_NAME`=:table_name AND `INDEX_NAME`=:index_name',
            ['table_name' => $table, 'index_name' => $index],
        )) > 0;
    }

    private function constraintExists(MigrationContext $context, string $table, string $constraint): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLE_CONSTRAINTS` WHERE `CONSTRAINT_SCHEMA`=DATABASE() '
            . 'AND `TABLE_NAME`=:table_name AND `CONSTRAINT_NAME`=:constraint_name AND `CONSTRAINT_TYPE`=\'FOREIGN KEY\'',
            ['table_name' => $table, 'constraint_name' => $constraint],
        )) > 0;
    }
}
