<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateThreadDomainTables implements Migration
{
    private const PERMISSIONS = [
        'forum.thread.lock' => 'Lock or unlock threads in an authorized forum.',
        'forum.thread.sticky' => 'Stick or unstick threads in an authorized forum.',
        'forum.thread.feature' => 'Feature or unfeature threads in an authorized forum.',
        'forum.thread.moderate' => 'Approve or reject moderated threads in an authorized forum.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915235945_thread_domain');
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
            'CREATE TABLE IF NOT EXISTS `forwext_thread_types` ('
            . '`type_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`label` VARCHAR(100) NOT NULL, '
            . '`allows_replies` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, '
            . '`system` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`type_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO `forwext_thread_types` '
            . '(`type_key`, `label`, `allows_replies`, `system`, `created_at_utc`, `updated_at_utc`) '
            . "VALUES ('discussion', 'Discussion', 1, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE `label` = VALUES(`label`), '
            . '`allows_replies` = VALUES(`allows_replies`), `system` = VALUES(`system`), '
            . '`updated_at_utc` = VALUES(`updated_at_utc`)',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_threads` ('
            . '`thread_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`forum_node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`author_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`type_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`title` VARCHAR(200) NOT NULL, '
            . '`moderation_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'visible\', '
            . '`locked` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`sticky` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`featured` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`version` BIGINT UNSIGNED NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`thread_id`), '
            . 'KEY `idx_forwext_threads_forum_listing` '
            . '(`forum_node_id`, `sticky`, `featured`, `updated_at_utc`, `thread_id`), '
            . 'KEY `idx_forwext_threads_author` (`author_user_id`), '
            . 'KEY `idx_forwext_threads_type` (`type_key`), '
            . 'CONSTRAINT `fk_forwext_threads_forum` FOREIGN KEY (`forum_node_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE RESTRICT, '
            . 'CONSTRAINT `fk_forwext_threads_author` FOREIGN KEY (`author_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL, '
            . 'CONSTRAINT `fk_forwext_threads_type` FOREIGN KEY (`type_key`) '
            . 'REFERENCES `forwext_thread_types` (`type_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach (self::PERMISSIONS as $key => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permissions` '
                . '(`permission_key`, `value_type`, `description`, `created_at_utc`, `updated_at_utc`) '
                . "VALUES (:permission_key, 'flag', :description, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE `value_type` = VALUES(`value_type`), '
                . '`description` = VALUES(`description`), `updated_at_utc` = VALUES(`updated_at_utc`)',
                [
                    'permission_key' => $key,
                    'description' => $description,
                ],
            ));
        }

        foreach (['new_user', 'member', 'verified', 'moderator', 'administrator'] as $templateKey) {
            $effect = in_array($templateKey, ['moderator', 'administrator'], true) ? 'allow' : 'deny';
            foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
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
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_thread_types', 'forwext_threads')",
        ));
        $discussion = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_thread_types` WHERE `type_key` = 'discussion' "
            . 'AND `system` = 1 AND `allows_replies` = 1',
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN '
            . "('forum.thread.lock', 'forum.thread.sticky', 'forum.thread.feature', 'forum.thread.moderate')",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user', 'member', 'verified', 'moderator', 'administrator') "
            . "AND `permission_key` IN ('forum.thread.lock', 'forum.thread.sticky', 'forum.thread.feature', 'forum.thread.moderate')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_threads\' '
            . "AND `CONSTRAINT_NAME` IN ('fk_forwext_threads_forum', 'fk_forwext_threads_author', 'fk_forwext_threads_type')",
        ));
        $authorDeleteRule = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_threads\' '
            . "AND `CONSTRAINT_NAME` = 'fk_forwext_threads_author' AND `DELETE_RULE` = 'SET NULL'",
        ));

        return (int) $tables === 2
            && (int) $discussion === 1
            && (int) $permissions === count(self::PERMISSIONS)
            && (int) $templateRules === 20
            && (int) $foreignKeys === 3
            && (int) $authorDeleteRule === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Thread domain schema, core type, permissions or starter rules are incomplete.');
    }
}
