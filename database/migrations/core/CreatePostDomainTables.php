<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreatePostDomainTables implements Migration
{
    private const PERMISSIONS = [
        'forum.post.edit_own' => 'Edit own forum posts.',
        'forum.post.edit_any' => 'Edit any forum post in an authorized forum.',
        'forum.post.delete_own' => 'Soft-delete own non-first forum posts.',
        'forum.post.delete_any' => 'Soft-delete any forum post, including a first post, in an authorized forum.',
        'forum.post.restore' => 'Restore soft-deleted forum posts.',
        'forum.post.moderate' => 'Approve or reject forum posts awaiting moderation.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915235955_post_domain');
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
            'CREATE TABLE IF NOT EXISTS `forwext_posts` ('
            . '`post_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`thread_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`author_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`position` INT UNSIGNED NOT NULL, '
            . '`body_source` MEDIUMTEXT NOT NULL, '
            . '`moderation_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'visible\', '
            . '`deleted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`deleted_at_utc` DATETIME(6) NULL, '
            . '`version` BIGINT UNSIGNED NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`post_id`), '
            . 'UNIQUE KEY `uq_forwext_posts_thread_position` (`thread_id`, `position`), '
            . 'KEY `idx_forwext_posts_thread_state` (`thread_id`, `deleted`, `moderation_state`, `position`), '
            . 'KEY `idx_forwext_posts_author` (`author_user_id`), '
            . 'CONSTRAINT `fk_forwext_posts_thread` FOREIGN KEY (`thread_id`) '
            . 'REFERENCES `forwext_threads` (`thread_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_posts_author` FOREIGN KEY (`author_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_post_history` ('
            . '`history_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`post_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`snapshot_version` BIGINT UNSIGNED NOT NULL, '
            . '`body_source` MEDIUMTEXT NOT NULL, '
            . '`moderation_state` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`deleted` TINYINT(1) UNSIGNED NOT NULL, '
            . '`action` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`actor_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`changed_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`history_id`), '
            . 'KEY `idx_forwext_post_history_post` (`post_id`, `history_id`), '
            . 'KEY `idx_forwext_post_history_actor` (`actor_user_id`), '
            . 'CONSTRAINT `fk_forwext_post_history_post` FOREIGN KEY (`post_id`) '
            . 'REFERENCES `forwext_posts` (`post_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_post_history_actor` FOREIGN KEY (`actor_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
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
                $ownCapability = in_array($permissionKey, [
                    'forum.post.edit_own',
                    'forum.post.delete_own',
                ], true);
                $effect = $ownCapability || in_array($templateKey, ['moderator', 'administrator'], true)
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
            . "AND `TABLE_NAME` IN ('forwext_posts', 'forwext_post_history')",
        ));
        $positionIndex = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = \'forwext_posts\' '
            . 'AND `INDEX_NAME` = \'uq_forwext_posts_thread_position\' AND `NON_UNIQUE` = 0',
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN '
            . "('forum.post.edit_own', 'forum.post.edit_any', 'forum.post.delete_own', "
            . "'forum.post.delete_any', 'forum.post.restore', 'forum.post.moderate')",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user', 'member', 'verified', 'moderator', 'administrator') "
            . "AND `permission_key` IN ('forum.post.edit_own', 'forum.post.edit_any', 'forum.post.delete_own', "
            . "'forum.post.delete_any', 'forum.post.restore', 'forum.post.moderate')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `CONSTRAINT_NAME` IN ('fk_forwext_posts_thread', 'fk_forwext_posts_author', "
            . "'fk_forwext_post_history_post', 'fk_forwext_post_history_actor')",
        ));

        return (int) $tables === 2
            && (int) $positionIndex === 2
            && (int) $permissions === 6
            && (int) $templateRules === 30
            && (int) $foreignKeys === 4
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Post domain schema, position integrity or permission seeds are incomplete.');
    }
}
