<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateCustomProfileUrlTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915143000_custom_profile_urls');
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
            'CREATE TABLE IF NOT EXISTS `forwext_profile_url_claims` ('
            . '`slug_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`claimed_at_utc` DATETIME(6) NOT NULL,`retired_at_utc` DATETIME(6) NULL,'
            . 'PRIMARY KEY (`slug_key`),KEY `idx_profile_url_claim_user` (`user_id`,`retired_at_utc`),'
            . 'CONSTRAINT `fk_profile_url_claim_user` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_profile_urls` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`slug_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`changed_at_utc` DATETIME(6) NOT NULL,`window_started_at_utc` DATETIME(6) NOT NULL,'
            . '`changes_in_window` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (`user_id`),UNIQUE KEY `uq_profile_url_current_slug` (`slug_key`),'
            . 'CONSTRAINT `fk_profile_url_current_user` FOREIGN KEY (`user_id`) REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE,'
            . 'CONSTRAINT `fk_profile_url_current_claim` FOREIGN KEY (`slug_key`) REFERENCES `forwext_profile_url_claims` (`slug_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $count = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=DATABASE() '
            . 'AND `TABLE_NAME` IN (\'forwext_profile_url_claims\',\'forwext_user_profile_urls\')',
        ));
        return (int) $count === 2
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Custom profile URL tables are missing.');
    }
}
