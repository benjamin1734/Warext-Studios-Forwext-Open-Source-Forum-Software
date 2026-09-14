<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAuthenticationRuntimeTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260914253000_authentication_runtime');
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
        $sessionIdColumn = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` = 'forwext_sessions' AND `COLUMN_NAME` = 'session_id'",
        ));
        if ((int) $sessionIdColumn > 0) {
            $context->execute(new CompiledQuery('ALTER TABLE `forwext_sessions` DROP COLUMN `session_id`'));
        }

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_credentials` ('
            . '`user_id` CHAR(32) NOT NULL, `password_hash` VARCHAR(255) NOT NULL, '
            . '`credential_version` INT UNSIGNED NOT NULL DEFAULT 1, `password_changed_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`user_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_devices` ('
            . '`device_id` CHAR(32) NOT NULL, `user_id` CHAR(32) NOT NULL, `user_agent_fingerprint` CHAR(64) NOT NULL, '
            . '`last_ip_fingerprint` CHAR(64) NOT NULL, `first_seen_at_utc` DATETIME(6) NOT NULL, '
            . '`last_seen_at_utc` DATETIME(6) NOT NULL, `revoked_at_utc` DATETIME(6) NULL, '
            . 'PRIMARY KEY (`device_id`), KEY `idx_auth_device_user` (`user_id`, `last_seen_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_login_history` ('
            . '`login_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` CHAR(32) NULL, '
            . '`identity_fingerprint` CHAR(64) NOT NULL, `ip_fingerprint` CHAR(64) NOT NULL, '
            . '`device_fingerprint` CHAR(64) NOT NULL, `outcome` VARCHAR(32) NOT NULL, `occurred_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`login_id`), KEY `idx_login_history_user` (`user_id`, `occurred_at_utc`), '
            . 'KEY `idx_login_history_identity` (`identity_fingerprint`, `occurred_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_auth_rate_limits` ('
            . '`fingerprint` CHAR(64) NOT NULL, `bucket_start_utc` DATETIME(6) NOT NULL, `attempts` INT UNSIGNED NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (`fingerprint`, `bucket_start_utc`), KEY `idx_auth_rate_bucket` (`bucket_start_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_remember_tokens` ('
            . '`selector_hash` CHAR(64) NOT NULL, `validator_hash` CHAR(64) NOT NULL, `family_id` CHAR(32) NOT NULL, '
            . '`user_id` CHAR(32) NOT NULL, `device_id` CHAR(32) NOT NULL, `credential_version` INT UNSIGNED NOT NULL, '
            . '`expires_at_utc` DATETIME(6) NOT NULL, `created_at_utc` DATETIME(6) NOT NULL, `consumed_at_utc` DATETIME(6) NULL, '
            . '`replacement_selector_hash` CHAR(64) NULL, `revoked_at_utc` DATETIME(6) NULL, PRIMARY KEY (`selector_hash`), '
            . 'KEY `idx_remember_user` (`user_id`, `expires_at_utc`), KEY `idx_remember_family` (`family_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_auth_challenge_tokens` ('
            . '`token_hash` CHAR(64) NOT NULL, `user_id` CHAR(32) NOT NULL, `purpose` VARCHAR(32) NOT NULL, '
            . '`expires_at_utc` DATETIME(6) NOT NULL, `created_at_utc` DATETIME(6) NOT NULL, `consumed_at_utc` DATETIME(6) NULL, '
            . 'PRIMARY KEY (`token_hash`), KEY `idx_auth_challenge_user` (`user_id`, `purpose`, `expires_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_user_credentials', 'forwext_user_devices', 'forwext_login_history', "
            . "'forwext_auth_rate_limits', 'forwext_remember_tokens', 'forwext_auth_challenge_tokens')",
        ));
        if ((int) $tables !== 6) {
            return MigrationVerification::failed('Authentication runtime tables are missing.');
        }
        $legacySessionId = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` = 'forwext_sessions' AND `COLUMN_NAME` = 'session_id'",
        ));
        return (int) $legacySessionId === 0
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Legacy raw session id column still exists.');
    }
}
