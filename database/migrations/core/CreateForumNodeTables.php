<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateForumNodeTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915235930_forum_nodes');
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
            'CREATE TABLE IF NOT EXISTS `forwext_nodes` ('
            . '`node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`parent_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`node_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`title` VARCHAR(150) NOT NULL, '
            . '`slug` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`description` VARCHAR(500) NOT NULL DEFAULT \'\', '
            . '`visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`sort_order` INT UNSIGNED NOT NULL DEFAULT 0, '
            . '`page_content` MEDIUMTEXT NULL, '
            . '`link_target` VARCHAR(2048) NULL, '
            . '`link_new_window` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`node_id`), '
            . 'UNIQUE KEY `uq_forwext_nodes_slug` (`slug`), '
            . 'KEY `idx_forwext_nodes_parent_sort` (`parent_id`, `sort_order`, `title`), '
            . 'CONSTRAINT `fk_forwext_nodes_parent` FOREIGN KEY (`parent_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_forum_settings` ('
            . '`node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`allow_new_threads` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, '
            . '`allow_replies` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, '
            . '`require_thread_approval` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`require_post_approval` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`default_thread_sort` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'last_post\', '
            . '`threads_per_page` SMALLINT UNSIGNED NOT NULL DEFAULT 20, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`node_id`), '
            . 'CONSTRAINT `fk_forwext_forum_settings_node` FOREIGN KEY (`node_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_nodes', 'forwext_forum_settings')",
        ));
        $parentForeignKey = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` = 'forwext_nodes' AND `CONSTRAINT_NAME` = 'fk_forwext_nodes_parent'",
        ));
        $slugUnique = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_nodes\' '
            . 'AND `INDEX_NAME` = \'uq_forwext_nodes_slug\' AND `NON_UNIQUE` = 0',
        ));
        $settingsForeignKey = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` = 'forwext_forum_settings' "
            . "AND `CONSTRAINT_NAME` = 'fk_forwext_forum_settings_node' "
            . "AND `DELETE_RULE` = 'CASCADE'",
        ));

        return (int) $tables === 2
            && (int) $parentForeignKey === 1
            && (int) $slugUnique === 1
            && (int) $settingsForeignKey === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Forum node hierarchy/settings schema or integrity constraints are incomplete.');
    }
}
