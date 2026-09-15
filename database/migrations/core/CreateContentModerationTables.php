<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateContentModerationTables implements Migration
{
    private const PERMISSIONS = [
        'forum.thread.move' => 'Move threads between authorized forums.',
        'forum.thread.copy' => 'Copy thread core content into an authorized forum.',
        'forum.thread.merge' => 'Merge authorized source threads into an authorized destination thread.',
        'forum.thread.split' => 'Split selected replies into a new thread in an authorized forum.',
        'forum.thread.delete' => 'Soft-delete threads while preserving moderation history.',
        'forum.thread.restore' => 'Restore eligible soft-deleted threads.',
        'forum.moderation.bulk' => 'Use bounded bulk thread/post moderation actions when the underlying action is also allowed.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260916000000_content_moderation');
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
        $this->addThreadColumnIfMissing(
            $context,
            'deleted',
            'ALTER TABLE `forwext_threads` ADD COLUMN `deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `featured`',
        );
        $this->addThreadColumnIfMissing(
            $context,
            'deleted_at_utc',
            'ALTER TABLE `forwext_threads` ADD COLUMN `deleted_at_utc` DATETIME(6) NULL AFTER `deleted`',
        );
        $this->addThreadColumnIfMissing(
            $context,
            'merged_into_thread_id',
            'ALTER TABLE `forwext_threads` ADD COLUMN `merged_into_thread_id` '
            . 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `deleted_at_utc`',
        );

        if ((int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_threads\' '
            . 'AND `INDEX_NAME` = \'idx_forwext_threads_active_forum\'',
        )) === 0) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_threads` ADD INDEX `idx_forwext_threads_active_forum` '
                . '(`forum_node_id`, `deleted`, `merged_into_thread_id`, `sticky`, `featured`, `updated_at_utc`)',
            ));
        }

        if ((int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . 'AND `CONSTRAINT_NAME` = \'fk_forwext_threads_merged_into\'',
        )) === 0) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_threads` ADD CONSTRAINT `fk_forwext_threads_merged_into` '
                . 'FOREIGN KEY (`merged_into_thread_id`) REFERENCES `forwext_threads` (`thread_id`) ON DELETE RESTRICT',
            ));
        }

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_moderation_audit_events` ('
            . '`audit_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`actor_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`action` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`target_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`target_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`forum_node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`reason_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`request_id` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`before_json` LONGTEXT NOT NULL, `after_json` LONGTEXT NOT NULL, '
            . '`occurred_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`audit_id`), '
            . 'KEY `idx_forwext_moderation_audit_target` (`target_type`, `target_id`, `occurred_at_utc`), '
            . 'KEY `idx_forwext_moderation_audit_actor` (`actor_user_id`, `occurred_at_utc`), '
            . 'KEY `idx_forwext_moderation_audit_request` (`request_id`, `occurred_at_utc`)'
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
        $columns = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_threads\' '
            . "AND `COLUMN_NAME` IN ('deleted', 'deleted_at_utc', 'merged_into_thread_id')",
        ));
        $auditTable = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_moderation_audit_events\'',
        ));
        $activeIndex = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_threads\' '
            . 'AND `INDEX_NAME` = \'idx_forwext_threads_active_forum\'',
        ));
        $mergeForeignKey = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . 'AND `CONSTRAINT_NAME` = \'fk_forwext_threads_merged_into\'',
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN '
            . "('forum.thread.move', 'forum.thread.copy', 'forum.thread.merge', 'forum.thread.split', "
            . "'forum.thread.delete', 'forum.thread.restore', 'forum.moderation.bulk')",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user', 'member', 'verified', 'moderator', 'administrator') "
            . "AND `permission_key` IN ('forum.thread.move', 'forum.thread.copy', 'forum.thread.merge', "
            . "'forum.thread.split', 'forum.thread.delete', 'forum.thread.restore', 'forum.moderation.bulk')",
        ));

        return (int) $columns === 3
            && (int) $auditTable === 1
            && (int) $activeIndex >= 1
            && (int) $mergeForeignKey === 1
            && (int) $permissions === 7
            && (int) $templateRules === 35
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Content moderation lifecycle, audit schema or permission seeds are incomplete.');
    }

    private function addThreadColumnIfMissing(MigrationContext $context, string $column, string $sql): void
    {
        $exists = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_threads\' '
            . 'AND `COLUMN_NAME` = :column_name',
            ['column_name' => $column],
        ));
        if ((int) $exists === 0) {
            $context->execute(new CompiledQuery($sql));
        }
    }
}
