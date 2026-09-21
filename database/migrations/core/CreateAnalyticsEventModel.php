<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAnalyticsEventModel implements Migration
{
    public function id():MigrationId
    {
        return MigrationId::fromString('20260921230000_analytics_event_model');
    }

    public function owner():MigrationOwner
    {
        return MigrationOwner::core();
    }

    public function isIdempotent():bool
    {
        return true;
    }

    public function isTransactional():bool
    {
        return false;
    }

    public function up(MigrationContext $context):void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_analytics_events ('
            . 'event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'event_key VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'category VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'subject_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'subject_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'forum_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'dimensions_json JSON NOT NULL,'
            . 'occurred_at_utc DATETIME(6) NOT NULL,'
            . 'event_day_utc DATE NOT NULL,'
            . 'recorded_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(event_id),'
            . 'KEY idx_forwext_analytics_event_day(event_key,event_day_utc,occurred_at_utc),'
            . 'KEY idx_forwext_analytics_category_day(category,event_day_utc,occurred_at_utc),'
            . 'KEY idx_forwext_analytics_forum_day(forum_id,event_day_utc,event_key),'
            . 'KEY idx_forwext_analytics_actor_day(actor_hash,event_day_utc,event_key),'
            . 'KEY idx_forwext_analytics_subject_day(subject_type,subject_hash,event_day_utc,event_key),'
            . 'KEY idx_forwext_analytics_retention(event_key,occurred_at_utc,event_id)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        ));
    }

    public function verify(MigrationContext $context):MigrationVerification
    {
        $table=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_analytics_events'"
        ));
        $indexes=(int)$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_analytics_events' "
            . "AND INDEX_NAME IN ('idx_forwext_analytics_event_day','idx_forwext_analytics_category_day',"
            . "'idx_forwext_analytics_forum_day','idx_forwext_analytics_actor_day',"
            . "'idx_forwext_analytics_subject_day','idx_forwext_analytics_retention')"
        ));

        return $table===1&&$indexes===6
            ?MigrationVerification::passed()
            :MigrationVerification::failed('Analytics event table or required indexes are incomplete.');
    }
}
