<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateContentEngagementAnalytics implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260921232000_content_engagement_analytics');
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
        if (!$this->hasColumn($context, 'forwext_analytics_events', 'content_type')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_analytics_events` ADD COLUMN `content_type` '
                . 'VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `forum_id`',
            ));
        }
        if (!$this->hasColumn($context, 'forwext_analytics_events', 'content_id')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_analytics_events` ADD COLUMN `content_id` '
                . 'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `content_type`',
            ));
        }
        if (!$this->hasIndex($context, 'forwext_analytics_events', 'idx_forwext_analytics_content_day')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_analytics_events` ADD INDEX `idx_forwext_analytics_content_day` '
                . '(`content_type`,`content_id`,`event_day_utc`,`event_key`)',
            ));
        }
        if (!$this->hasIndex($context, 'forwext_post_bookmarks', 'idx_forwext_post_bookmarks_post')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_post_bookmarks` ADD INDEX `idx_forwext_post_bookmarks_post` (`post_id`,`user_id`)',
            ));
        }

        if (!$this->hasIndex($context, 'forwext_post_reactions', 'idx_forwext_post_reactions_created')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_post_reactions` ADD INDEX `idx_forwext_post_reactions_created` (`created_at_utc`,`post_id`)',
            ));
        }
        if (!$this->hasIndex($context, 'forwext_post_bookmarks', 'idx_forwext_post_bookmarks_created')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_post_bookmarks` ADD INDEX `idx_forwext_post_bookmarks_created` (`created_at_utc`,`post_id`)',
            ));
        }
        if (!$this->hasIndex($context, 'forwext_watched_threads', 'idx_forwext_watched_threads_updated')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_watched_threads` ADD INDEX `idx_forwext_watched_threads_updated` (`updated_at_utc`,`thread_id`)',
            ));
        }
        if (!$this->hasIndex($context, 'forwext_watched_forums', 'idx_forwext_watched_forums_updated')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_watched_forums` ADD INDEX `idx_forwext_watched_forums_updated` (`updated_at_utc`,`forum_node_id`)',
            ));
        }
        if (!$this->hasIndex($context, 'forwext_user_follows', 'idx_forwext_user_follows_created')) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_user_follows` ADD INDEX `idx_forwext_user_follows_created` (`created_at_utc`,`followed_user_id`)',
            ));
        }

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_search_term_analytics` ('
            . '`term_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`event_day_utc` DATE NOT NULL,'
            . '`display_term` VARCHAR(96) NULL,'
            . '`search_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . '`zero_result_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . '`result_count_total` BIGINT UNSIGNED NOT NULL DEFAULT 0,'
            . '`last_searched_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`term_key`,`event_day_utc`),'
            . 'KEY `idx_forwext_search_term_day_count` (`event_day_utc`,`search_count`,`term_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=DATABASE() "
            . "AND `TABLE_NAME`='forwext_search_term_analytics'",
        ));

        return $table === 1
            && $this->hasColumn($context, 'forwext_analytics_events', 'content_type')
            && $this->hasColumn($context, 'forwext_analytics_events', 'content_id')
            && $this->hasIndex($context, 'forwext_analytics_events', 'idx_forwext_analytics_content_day')
            && $this->hasIndex($context, 'forwext_post_bookmarks', 'idx_forwext_post_bookmarks_post')
            && $this->hasIndex($context, 'forwext_post_reactions', 'idx_forwext_post_reactions_created')
            && $this->hasIndex($context, 'forwext_post_bookmarks', 'idx_forwext_post_bookmarks_created')
            && $this->hasIndex($context, 'forwext_watched_threads', 'idx_forwext_watched_threads_updated')
            && $this->hasIndex($context, 'forwext_watched_forums', 'idx_forwext_watched_forums_updated')
            && $this->hasIndex($context, 'forwext_user_follows', 'idx_forwext_user_follows_created')
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Content engagement analytics schema or indexes are incomplete.');
    }

    private function hasColumn(MigrationContext $context, string $table, string $column): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS` '
            . 'WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME`=:table AND `COLUMN_NAME`=:column',
            ['table'=>$table,'column'=>$column],
        )) > 0;
    }

    private function hasIndex(MigrationContext $context, string $table, string $index): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME`=:table AND `INDEX_NAME`=:index',
            ['table'=>$table,'index'=>$index],
        )) > 0;
    }
}
