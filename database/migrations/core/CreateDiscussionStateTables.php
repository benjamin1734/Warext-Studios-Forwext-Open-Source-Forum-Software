<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateDiscussionStateTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915235959_discussion_state');
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
            'CREATE TABLE IF NOT EXISTS `forwext_content_drafts` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`target_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`target_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`title_source` VARCHAR(200) NULL, `body_source` MEDIUMTEXT NOT NULL, '
            . '`revision` INT UNSIGNED NOT NULL, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `target_type`, `target_id`), '
            . 'KEY `idx_forwext_content_drafts_updated` (`updated_at_utc`), '
            . 'CONSTRAINT `fk_forwext_content_drafts_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_thread_read_state` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`thread_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`last_read_post_position` INT UNSIGNED NOT NULL, `last_read_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `thread_id`), KEY `idx_forwext_thread_read_thread` (`thread_id`, `user_id`), '
            . 'CONSTRAINT `fk_forwext_thread_read_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_thread_read_thread` FOREIGN KEY (`thread_id`) '
            . 'REFERENCES `forwext_threads` (`thread_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_forum_read_state` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`forum_node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`marked_read_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `forum_node_id`), KEY `idx_forwext_forum_read_forum` (`forum_node_id`, `user_id`), '
            . 'CONSTRAINT `fk_forwext_forum_read_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_forum_read_forum` FOREIGN KEY (`forum_node_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_watched_threads` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`thread_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`notification_mode` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `thread_id`), KEY `idx_forwext_watched_threads_thread` (`thread_id`, `user_id`), '
            . 'CONSTRAINT `fk_forwext_watched_threads_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_watched_threads_thread` FOREIGN KEY (`thread_id`) '
            . 'REFERENCES `forwext_threads` (`thread_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_watched_forums` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`forum_node_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`notification_mode` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`, `forum_node_id`), KEY `idx_forwext_watched_forums_forum` (`forum_node_id`, `user_id`), '
            . 'CONSTRAINT `fk_forwext_watched_forums_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_watched_forums_forum` FOREIGN KEY (`forum_node_id`) '
            . 'REFERENCES `forwext_nodes` (`node_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_subscription_preferences` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`auto_watch_created_threads` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1, '
            . '`auto_watch_replied_threads` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . "`default_thread_mode` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'in_app', "
            . "`default_forum_mode` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'in_app', "
            . '`updated_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`user_id`), '
            . 'CONSTRAINT `fk_forwext_subscription_preferences_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_content_drafts', 'forwext_thread_read_state', "
            . "'forwext_forum_read_state', 'forwext_watched_threads', 'forwext_watched_forums', "
            . "'forwext_subscription_preferences')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `CONSTRAINT_NAME` IN ('fk_forwext_content_drafts_user', 'fk_forwext_thread_read_user', "
            . "'fk_forwext_thread_read_thread', 'fk_forwext_forum_read_user', 'fk_forwext_forum_read_forum', "
            . "'fk_forwext_watched_threads_user', 'fk_forwext_watched_threads_thread', "
            . "'fk_forwext_watched_forums_user', 'fk_forwext_watched_forums_forum', "
            . "'fk_forwext_subscription_preferences_user')",
        ));
        $draftPrimary = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() '
            . 'AND `TABLE_NAME` = \'forwext_content_drafts\' AND `INDEX_NAME` = \'PRIMARY\'',
        ));

        return (int) $tables === 6
            && (int) $foreignKeys === 10
            && (int) $draftPrimary === 3
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Discussion draft/read/watch schema or integrity constraints are incomplete.');
    }
}
