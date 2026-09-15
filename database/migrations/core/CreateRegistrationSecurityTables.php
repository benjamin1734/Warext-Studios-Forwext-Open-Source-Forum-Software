<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateRegistrationSecurityTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915003000_registration_security');
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
            'CREATE TABLE IF NOT EXISTS `forwext_registration_invites` ('
            . '`invite_id` CHAR(32) NOT NULL, `code_hash` CHAR(64) NOT NULL, `uses` INT UNSIGNED NOT NULL DEFAULT 0, '
            . '`max_uses` INT UNSIGNED NOT NULL, `expires_at_utc` DATETIME(6) NULL, `disabled` TINYINT(1) NOT NULL DEFAULT 0, '
            . '`created_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`invite_id`), UNIQUE KEY `uq_registration_invite_hash` (`code_hash`), '
            . 'KEY `idx_registration_invite_expiry` (`expires_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_email_verification_tokens` ('
            . '`token_hash` CHAR(64) NOT NULL, `user_id` CHAR(32) NOT NULL, `target_status` VARCHAR(32) NOT NULL, '
            . '`expires_at_utc` DATETIME(6) NOT NULL, `consumed_at_utc` DATETIME(6) NULL, `created_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`token_hash`), KEY `idx_email_verification_user` (`user_id`), '
            . 'KEY `idx_email_verification_expiry` (`expires_at_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_legal_acceptances` ('
            . '`acceptance_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `user_id` CHAR(32) NOT NULL, '
            . '`document_type` VARCHAR(32) NOT NULL, `document_version` VARCHAR(64) NOT NULL, `content_sha256` CHAR(64) NOT NULL, '
            . '`accepted_at_utc` DATETIME(6) NOT NULL, `client_fingerprint` CHAR(64) NOT NULL, PRIMARY KEY (`acceptance_id`), '
            . 'UNIQUE KEY `uq_user_legal_version` (`user_id`, `document_type`, `document_version`), '
            . 'KEY `idx_user_legal_type` (`document_type`, `document_version`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_registration_rate_limits` ('
            . '`scope_name` VARCHAR(32) NOT NULL, `fingerprint` CHAR(64) NOT NULL, `bucket_start_utc` DATETIME(6) NOT NULL, '
            . '`attempts` INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (`scope_name`, `fingerprint`, `bucket_start_utc`), '
            . 'KEY `idx_registration_rate_bucket` (`bucket_start_utc`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $count = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_registration_invites', 'forwext_email_verification_tokens', "
            . "'forwext_user_legal_acceptances', 'forwext_registration_rate_limits')",
        ));
        if ((int) $count !== 4) {
            return MigrationVerification::failed('Registration security tables are missing.');
        }

        $indexes = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND ((`TABLE_NAME` = 'forwext_registration_invites' AND `INDEX_NAME` = 'uq_registration_invite_hash') "
            . "OR (`TABLE_NAME` = 'forwext_user_legal_acceptances' AND `INDEX_NAME` = 'uq_user_legal_version'))",
        ));
        return (int) $indexes >= 2
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Registration integrity indexes are missing.');
    }
}
