<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateOAuthConnectedAccountTables implements Migration
{
    public function id(): MigrationId { return MigrationId::fromString('20260915123000_oauth_connected_accounts'); }
    public function owner(): MigrationOwner { return MigrationOwner::core(); }
    public function isIdempotent(): bool { return true; }
    public function isTransactional(): bool { return false; }

    public function up(MigrationContext $context): void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_connected_accounts` ('
            . '`provider` VARCHAR(32) NOT NULL,`provider_subject` VARCHAR(191) NOT NULL,`user_id` CHAR(32) NOT NULL,'
            . '`provider_email_normalized` VARCHAR(254) NULL,`display_name` VARCHAR(191) NULL,'
            . '`linked_at_utc` DATETIME(6) NOT NULL,`last_authenticated_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`provider`,`provider_subject`),UNIQUE KEY `uq_connected_user_provider` (`user_id`,`provider`),'
            . 'KEY `idx_connected_user` (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_oauth_transactions` ('
            . '`state_hash` CHAR(64) NOT NULL,`provider` VARCHAR(32) NOT NULL,`pkce_verifier` VARCHAR(128) NOT NULL,'
            . '`redirect_uri` VARCHAR(2048) NOT NULL,`intended_user_id` CHAR(32) NULL,'
            . '`created_at_utc` DATETIME(6) NOT NULL,`expires_at_utc` DATETIME(6) NOT NULL,`consumed_at_utc` DATETIME(6) NULL,'
            . 'PRIMARY KEY (`state_hash`),KEY `idx_oauth_expiry` (`expires_at_utc`),KEY `idx_oauth_user` (`intended_user_id`,`expires_at_utc`)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $count = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME` IN (\'forwext_connected_accounts\',\'forwext_oauth_transactions\')',
        ));
        return (int) $count === 2 ? MigrationVerification::passed() : MigrationVerification::failed('OAuth connected-account tables are missing.');
    }
}
