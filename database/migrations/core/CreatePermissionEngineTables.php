<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreatePermissionEngineTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915210000_permission_engine');
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
            'CREATE TABLE IF NOT EXISTS `forwext_permissions` ('
            . '`permission_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`value_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`description` VARCHAR(255) NOT NULL DEFAULT \'\', '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`permission_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_permission_global_rules` ('
            . '`subject_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`subject_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`permission_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`effect` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`numeric_limit` BIGINT UNSIGNED NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`subject_type`, `subject_id`, `permission_key`), '
            . 'KEY `idx_forwext_permission_global_key` (`permission_key`, `subject_type`), '
            . 'CONSTRAINT `fk_forwext_permission_global_definition` FOREIGN KEY (`permission_key`) REFERENCES `forwext_permissions` (`permission_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_permission_node_rules` ('
            . '`node_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`subject_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`subject_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`permission_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`effect` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`numeric_limit` BIGINT UNSIGNED NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`node_id`, `subject_type`, `subject_id`, `permission_key`), '
            . 'KEY `idx_forwext_permission_node_key` (`permission_key`, `node_id`, `subject_type`), '
            . 'CONSTRAINT `fk_forwext_permission_node_definition` FOREIGN KEY (`permission_key`) REFERENCES `forwext_permissions` (`permission_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_permissions', 'forwext_permission_global_rules', 'forwext_permission_node_rules')",
        ));
        $nodePrimaryKeyColumns = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_permission_node_rules\' '
            . 'AND `INDEX_NAME` = \'PRIMARY\'',
        ));

        return (int) $tables === 3 && (int) $nodePrimaryKeyColumns === 4
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Permission definition/global/node tables or deterministic node uniqueness are missing.');
    }
}
