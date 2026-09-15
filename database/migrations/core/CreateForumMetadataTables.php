<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateForumMetadataTables implements Migration
{
    private const PERMISSIONS = [
        'forum.thread.edit_own' => 'Edit metadata and editable properties of own threads.',
        'forum.thread.edit_any' => 'Edit metadata and editable properties of any thread in an authorized forum.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915235957_forum_metadata');
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
            'CREATE TABLE IF NOT EXISTS `forwext_prefix_groups` ('
            . '`group_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`name` VARCHAR(100) NOT NULL, `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`group_id`), KEY `idx_forwext_prefix_groups_sort` (`sort_order`, `name`, `group_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_thread_prefixes` ('
            . '`prefix_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`group_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`name` VARCHAR(100) NOT NULL, `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`prefix_id`), KEY `idx_forwext_thread_prefix_group_sort` (`group_id`, `sort_order`, `name`), '
            . 'CONSTRAINT `fk_forwext_thread_prefix_group` FOREIGN KEY (`group_id`) '
            . 'REFERENCES `forwext_prefix_groups` (`group_id`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_forum_prefix_groups` ('
            . '`forum_node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`group_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'PRIMARY KEY (`forum_node_id`, `group_id`), KEY `idx_forwext_forum_prefix_group` (`group_id`), '
            . 'CONSTRAINT `fk_forwext_forum_prefix_forum` FOREIGN KEY (`forum_node_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_forum_prefix_group` FOREIGN KEY (`group_id`) '
            . 'REFERENCES `forwext_prefix_groups` (`group_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_thread_prefix_assignments` ('
            . '`thread_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`prefix_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'PRIMARY KEY (`thread_id`), KEY `idx_forwext_thread_prefix_assignment_prefix` (`prefix_id`), '
            . 'CONSTRAINT `fk_forwext_thread_prefix_assignment_thread` FOREIGN KEY (`thread_id`) '
            . 'REFERENCES `forwext_threads` (`thread_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_thread_prefix_assignment_prefix` FOREIGN KEY (`prefix_id`) '
            . 'REFERENCES `forwext_thread_prefixes` (`prefix_id`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_tags` ('
            . '`tag_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`name` VARCHAR(64) NOT NULL, `created_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`tag_id`), UNIQUE KEY `uq_forwext_tags_name` (`name`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_thread_tags` ('
            . '`thread_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`tag_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'PRIMARY KEY (`thread_id`, `tag_id`), KEY `idx_forwext_thread_tags_tag` (`tag_id`, `thread_id`), '
            . 'CONSTRAINT `fk_forwext_thread_tags_thread` FOREIGN KEY (`thread_id`) '
            . 'REFERENCES `forwext_threads` (`thread_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_thread_tags_tag` FOREIGN KEY (`tag_id`) '
            . 'REFERENCES `forwext_tags` (`tag_id`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_custom_fields` ('
            . '`field_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`target` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`label` VARCHAR(100) NOT NULL, `value_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`required` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, `minimum_value` BIGINT NULL, `maximum_value` BIGINT NULL, '
            . '`choices_json` MEDIUMTEXT NOT NULL, `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`field_key`), KEY `idx_forwext_custom_fields_target_sort` (`target`, `sort_order`, `field_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_forum_thread_fields` ('
            . '`forum_node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`field_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'PRIMARY KEY (`forum_node_id`, `field_key`), KEY `idx_forwext_forum_thread_fields_field` (`field_key`), '
            . 'CONSTRAINT `fk_forwext_forum_thread_fields_forum` FOREIGN KEY (`forum_node_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_forum_thread_fields_field` FOREIGN KEY (`field_key`) '
            . 'REFERENCES `forwext_custom_fields` (`field_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_thread_custom_field_values` ('
            . '`thread_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`field_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `value_json` MEDIUMTEXT NOT NULL, '
            . 'PRIMARY KEY (`thread_id`, `field_key`), KEY `idx_forwext_thread_field_values_field` (`field_key`), '
            . 'CONSTRAINT `fk_forwext_thread_field_values_thread` FOREIGN KEY (`thread_id`) '
            . 'REFERENCES `forwext_threads` (`thread_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_thread_field_values_field` FOREIGN KEY (`field_key`) '
            . 'REFERENCES `forwext_custom_fields` (`field_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_forum_custom_field_values` ('
            . '`forum_node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`field_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `value_json` MEDIUMTEXT NOT NULL, '
            . 'PRIMARY KEY (`forum_node_id`, `field_key`), KEY `idx_forwext_forum_field_values_field` (`field_key`), '
            . 'CONSTRAINT `fk_forwext_forum_field_values_forum` FOREIGN KEY (`forum_node_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_forum_field_values_field` FOREIGN KEY (`field_key`) '
            . 'REFERENCES `forwext_custom_fields` (`field_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_forum_content_config` ('
            . '`forum_node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`tags_enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`allow_new_tags` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, `max_tags` TINYINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`forum_node_id`), '
            . 'CONSTRAINT `fk_forwext_forum_content_config_forum` FOREIGN KEY (`forum_node_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach (self::PERMISSIONS as $key => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permissions` '
                . '(`permission_key`, `value_type`, `description`, `created_at_utc`, `updated_at_utc`) '
                . "VALUES (:permission_key, 'flag', :description, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE `value_type` = VALUES(`value_type`), '
                . '`description` = VALUES(`description`), `updated_at_utc` = VALUES(`updated_at_utc`)',
                ['permission_key' => $key, 'description' => $description],
            ));
        }
        foreach (['new_user', 'member', 'verified', 'moderator', 'administrator'] as $templateKey) {
            foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
                $effect = $permissionKey === 'forum.thread.edit_own'
                    || in_array($templateKey, ['moderator', 'administrator'], true)
                    ? 'allow'
                    : 'deny';
                $context->execute(new CompiledQuery(
                    'INSERT INTO `forwext_permission_template_rules` '
                    . '(`template_key`, `permission_key`, `effect`, `numeric_limit`) '
                    . 'VALUES (:template_key, :permission_key, :effect, NULL) '
                    . 'ON DUPLICATE KEY UPDATE `effect` = VALUES(`effect`), `numeric_limit` = NULL',
                    [
                        'template_key' => $templateKey,
                        'permission_key' => $permissionKey,
                        'effect' => $effect,
                    ],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_prefix_groups', 'forwext_thread_prefixes', 'forwext_forum_prefix_groups', "
            . "'forwext_thread_prefix_assignments', 'forwext_tags', 'forwext_thread_tags', 'forwext_custom_fields', "
            . "'forwext_forum_thread_fields', 'forwext_thread_custom_field_values', "
            . "'forwext_forum_custom_field_values', 'forwext_forum_content_config')",
        ));
        $tagUnique = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_tags\' '
            . 'AND `INDEX_NAME` = \'uq_forwext_tags_name\' AND `NON_UNIQUE` = 0',
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` '
            . "WHERE `permission_key` IN ('forum.thread.edit_own', 'forum.thread.edit_any')",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user', 'member', 'verified', 'moderator', 'administrator') "
            . "AND `permission_key` IN ('forum.thread.edit_own', 'forum.thread.edit_any')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `CONSTRAINT_NAME` IN ('fk_forwext_thread_prefix_group', 'fk_forwext_forum_prefix_forum', "
            . "'fk_forwext_forum_prefix_group', 'fk_forwext_thread_prefix_assignment_thread', "
            . "'fk_forwext_thread_prefix_assignment_prefix', 'fk_forwext_thread_tags_thread', "
            . "'fk_forwext_thread_tags_tag', 'fk_forwext_forum_thread_fields_forum', "
            . "'fk_forwext_forum_thread_fields_field', 'fk_forwext_thread_field_values_thread', "
            . "'fk_forwext_thread_field_values_field', 'fk_forwext_forum_field_values_forum', "
            . "'fk_forwext_forum_field_values_field', 'fk_forwext_forum_content_config_forum')",
        ));

        return (int) $tables === 11
            && (int) $tagUnique === 1
            && (int) $permissions === 2
            && (int) $templateRules === 10
            && (int) $foreignKeys === 14
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Forum metadata schema, integrity rules or permission seeds are incomplete.');
    }
}
