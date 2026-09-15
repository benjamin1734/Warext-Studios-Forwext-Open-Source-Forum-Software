<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateProfileMusicTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915140000_profile_music');
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
            'CREATE TABLE IF NOT EXISTS `forwext_user_profile_music` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`enabled` TINYINT(1) NOT NULL DEFAULT 0,'
            . '`source_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`upload_path` VARCHAR(1024) NULL,'
            . '`external_url` VARCHAR(2048) NULL,'
            . '`title` VARCHAR(191) NOT NULL DEFAULT \'\','
            . '`visibility` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'public\','
            . '`volume` TINYINT UNSIGNED NOT NULL DEFAULT 70,'
            . '`muted` TINYINT(1) NOT NULL DEFAULT 0,'
            . '`autoplay` TINYINT(1) NOT NULL DEFAULT 0,'
            . '`loop` TINYINT(1) NOT NULL DEFAULT 1,'
            . '`moderation_status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'active\','
            . '`moderation_reason_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`moderated_by_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`moderated_at_utc` DATETIME(6) NULL,'
            . '`updated_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`user_id`),'
            . 'KEY `idx_forwext_profile_music_visibility` (`enabled`, `visibility`, `moderation_status`),'
            . 'KEY `idx_forwext_profile_music_moderator` (`moderated_by_user_id`),'
            . 'CONSTRAINT `fk_forwext_profile_music_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE,'
            . 'CONSTRAINT `fk_forwext_profile_music_moderator` FOREIGN KEY (`moderated_by_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_user_profile_music_moderation` ('
            . '`moderation_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`actor_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`action` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`reason_code` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`occurred_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`moderation_id`),'
            . 'KEY `idx_forwext_profile_music_audit` (`user_id`, `moderation_id`),'
            . 'CONSTRAINT `fk_forwext_profile_music_audit_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE,'
            . 'CONSTRAINT `fk_forwext_profile_music_audit_actor` FOREIGN KEY (`actor_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tableCount = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_user_profile_music', 'forwext_user_profile_music_moderation')",
        ));
        $foreignKeyCount = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `CONSTRAINT_NAME` IN ('fk_forwext_profile_music_user', 'fk_forwext_profile_music_moderator', "
            . "'fk_forwext_profile_music_audit_user', 'fk_forwext_profile_music_audit_actor')",
        ));

        return (int) $tableCount === 2 && (int) $foreignKeyCount === 4
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Profile music tables or foreign keys are missing.');
    }
}
