<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateQueueSchedulerRealtimeTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260914213000_queue_scheduler_realtime');
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
            'CREATE TABLE IF NOT EXISTS `forwext_jobs` ('
            . '`job_id` CHAR(32) NOT NULL, '
            . '`queue_name` VARCHAR(128) NOT NULL, '
            . '`job_type` VARCHAR(191) NOT NULL, '
            . '`payload` LONGBLOB NOT NULL, '
            . '`attempts` INT UNSIGNED NOT NULL DEFAULT 0, '
            . '`max_attempts` INT UNSIGNED NOT NULL, '
            . '`available_at_utc` DATETIME(6) NOT NULL, '
            . '`reserved_until_utc` DATETIME(6) NULL, '
            . '`reservation_token` CHAR(32) NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`job_id`), '
            . 'KEY `idx_forwext_jobs_ready` (`queue_name`, `available_at_utc`, `reserved_until_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_failed_jobs` ('
            . '`job_id` CHAR(32) NOT NULL, '
            . '`queue_name` VARCHAR(128) NOT NULL, '
            . '`job_type` VARCHAR(191) NOT NULL, '
            . '`payload` LONGBLOB NOT NULL, '
            . '`attempts` INT UNSIGNED NOT NULL, '
            . '`max_attempts` INT UNSIGNED NOT NULL, '
            . '`failure_code` VARCHAR(64) NOT NULL, '
            . '`failed_at_utc` DATETIME(6) NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`job_id`), '
            . 'KEY `idx_forwext_failed_jobs_queue` (`queue_name`, `failed_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_scheduler_claims` ('
            . '`task_name` VARCHAR(191) NOT NULL, '
            . '`minute_bucket_utc` DATETIME(6) NOT NULL, '
            . '`claim_token` CHAR(32) NOT NULL, '
            . '`claimed_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`task_name`, `minute_bucket_utc`), '
            . 'KEY `idx_forwext_scheduler_claim_time` (`minute_bucket_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_realtime_messages` ('
            . '`sequence_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, '
            . '`channel_name` VARCHAR(191) NOT NULL, '
            . '`event_name` VARCHAR(191) NOT NULL, '
            . '`payload` LONGBLOB NOT NULL, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`sequence_id`), '
            . 'KEY `idx_forwext_realtime_channel_sequence` (`channel_name`, `sequence_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $count = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN '
            . "('forwext_jobs', 'forwext_failed_jobs', 'forwext_scheduler_claims', 'forwext_realtime_messages')",
        ));

        return (int) $count === 4
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Queue/scheduler/realtime runtime tables are missing.');
    }
}
