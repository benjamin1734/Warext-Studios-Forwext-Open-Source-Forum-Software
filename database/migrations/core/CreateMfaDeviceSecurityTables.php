<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateMfaDeviceSecurityTables implements Migration
{
    public function id(): MigrationId { return MigrationId::fromString('20260915120000_mfa_device_security'); }
    public function owner(): MigrationOwner { return MigrationOwner::core(); }
    public function isIdempotent(): bool { return true; }
    public function isTransactional(): bool { return false; }

    public function up(MigrationContext $context): void
    {
        $queries = [
            'CREATE TABLE IF NOT EXISTS `forwext_totp_factors` (`user_id` CHAR(32) NOT NULL,`encrypted_secret` LONGTEXT NOT NULL,`last_counter` BIGINT NOT NULL DEFAULT -1,`created_at_utc` DATETIME(6) NOT NULL,`enabled_at_utc` DATETIME(6) NULL,PRIMARY KEY (`user_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `forwext_recovery_codes` (`user_id` CHAR(32) NOT NULL,`code_hash` CHAR(64) NOT NULL,`created_at_utc` DATETIME(6) NOT NULL,`used_at_utc` DATETIME(6) NULL,PRIMARY KEY (`user_id`,`code_hash`),KEY `idx_recovery_unused` (`user_id`,`used_at_utc`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `forwext_mfa_group_policies` (`group_key` VARCHAR(191) NOT NULL,`login_required` TINYINT(1) NOT NULL DEFAULT 0,`sensitive_action_required` TINYINT(1) NOT NULL DEFAULT 1,`trusted_device_bypass` TINYINT(1) NOT NULL DEFAULT 1,PRIMARY KEY (`group_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `forwext_trusted_devices` (`token_hash` CHAR(64) NOT NULL,`user_id` CHAR(32) NOT NULL,`device_id` CHAR(32) NOT NULL,`credential_version` INT UNSIGNED NOT NULL,`expires_at_utc` DATETIME(6) NOT NULL,`created_at_utc` DATETIME(6) NOT NULL,`last_used_at_utc` DATETIME(6) NULL,`revoked_at_utc` DATETIME(6) NULL,PRIMARY KEY (`token_hash`),KEY `idx_trusted_device_user` (`user_id`,`device_id`,`expires_at_utc`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `forwext_mfa_challenges` (`challenge_hash` CHAR(64) NOT NULL,`user_id` CHAR(32) NOT NULL,`device_id` CHAR(32) NOT NULL,`credential_version` INT UNSIGNED NOT NULL,`purpose` VARCHAR(32) NOT NULL,`action_key` VARCHAR(191) NULL,`remember_requested` TINYINT(1) NOT NULL DEFAULT 0,`identity_fingerprint` CHAR(64) NOT NULL,`ip_fingerprint` CHAR(64) NOT NULL,`device_fingerprint` CHAR(64) NOT NULL,`expires_at_utc` DATETIME(6) NOT NULL,`created_at_utc` DATETIME(6) NOT NULL,`consumed_at_utc` DATETIME(6) NULL,PRIMARY KEY (`challenge_hash`),KEY `idx_mfa_challenge_user` (`user_id`,`purpose`,`expires_at_utc`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `forwext_passkey_credentials` (`credential_hash` CHAR(64) NOT NULL,`credential_id_b64url` VARCHAR(1024) NOT NULL,`user_id` CHAR(32) NOT NULL,`credential_record_json` LONGTEXT NOT NULL,`label` VARCHAR(191) NOT NULL,`created_at_utc` DATETIME(6) NOT NULL,`last_used_at_utc` DATETIME(6) NULL,`revoked_at_utc` DATETIME(6) NULL,PRIMARY KEY (`credential_hash`),KEY `idx_passkey_user` (`user_id`,`revoked_at_utc`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE IF NOT EXISTS `forwext_webauthn_ceremonies` (`ceremony_hash` CHAR(64) NOT NULL,`user_id` CHAR(32) NOT NULL,`purpose` VARCHAR(32) NOT NULL,`options_json` LONGTEXT NOT NULL,`expires_at_utc` DATETIME(6) NOT NULL,`created_at_utc` DATETIME(6) NOT NULL,`consumed_at_utc` DATETIME(6) NULL,PRIMARY KEY (`ceremony_hash`),KEY `idx_webauthn_ceremony_user` (`user_id`,`purpose`,`expires_at_utc`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
        foreach ($queries as $sql) {
            $context->execute(new CompiledQuery($sql));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $count = $context->fetchValue(new CompiledQuery('SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME` IN (\'forwext_totp_factors\',\'forwext_recovery_codes\',\'forwext_mfa_group_policies\',\'forwext_trusted_devices\',\'forwext_mfa_challenges\',\'forwext_passkey_credentials\',\'forwext_webauthn_ceremonies\')'));
        return (int) $count === 7 ? MigrationVerification::passed() : MigrationVerification::failed('One or more MFA/device-security tables are missing.');
    }
}
