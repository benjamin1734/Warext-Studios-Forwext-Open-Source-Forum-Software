<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateGiveawayParticipation implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919150000_giveaway_participation');
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
            'CREATE TABLE IF NOT EXISTS forwext_giveaway_eligibility ('
            . 'giveaway_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'min_account_age_days INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'min_post_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'require_verified_account TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . "referral_requirement VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'none',"
            . 'min_qualified_referrals INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'duplicate_network_limit SMALLINT UNSIGNED NOT NULL DEFAULT 3,'
            . 'duplicate_device_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(giveaway_id),'
            . 'CONSTRAINT fk_forwext_giveaway_eligibility_giveaway FOREIGN KEY(giveaway_id) '
            . 'REFERENCES forwext_giveaways(giveaway_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_giveaway_eligible_roles ('
            . 'giveaway_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'role_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'PRIMARY KEY(giveaway_id,role_id),KEY idx_forwext_giveaway_role(role_id,giveaway_id),'
            . 'CONSTRAINT fk_forwext_giveaway_role_giveaway FOREIGN KEY(giveaway_id) '
            . 'REFERENCES forwext_giveaways(giveaway_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_giveaway_role_role FOREIGN KEY(role_id) '
            . 'REFERENCES forwext_roles(role_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_giveaway_entries ('
            . 'entry_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'giveaway_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'entry_count SMALLINT UNSIGNED NOT NULL,'
            . 'network_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'device_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'entered_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(entry_id),UNIQUE KEY uq_forwext_giveaway_entry_user(giveaway_id,user_id),'
            . 'KEY idx_forwext_giveaway_entry_time(giveaway_id,entered_at_utc,entry_id),'
            . 'KEY idx_forwext_giveaway_entry_network(giveaway_id,network_fingerprint),'
            . 'KEY idx_forwext_giveaway_entry_device(giveaway_id,device_fingerprint),'
            . 'CONSTRAINT fk_forwext_giveaway_entry_giveaway FOREIGN KEY(giveaway_id) '
            . 'REFERENCES forwext_giveaways(giveaway_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_giveaway_entry_user FOREIGN KEY(user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME IN ('forwext_giveaway_eligibility','forwext_giveaway_eligible_roles','forwext_giveaway_entries')",
        ));
        $indexes = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_giveaway_entries' "
            . "AND INDEX_NAME IN ('uq_forwext_giveaway_entry_user','idx_forwext_giveaway_entry_time',"
            . "'idx_forwext_giveaway_entry_network','idx_forwext_giveaway_entry_device')",
        ));

        return $tables === 3 && $indexes === 4
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Giveaway participation schema is incomplete.');
    }
}
