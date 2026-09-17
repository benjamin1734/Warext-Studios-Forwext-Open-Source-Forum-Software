<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateNotificationSoundTables implements Migration
{
    public function id(): MigrationId { return MigrationId::fromString('20260917002000_notification_sound'); }
    public function owner(): MigrationOwner { return MigrationOwner::core(); }
    public function isIdempotent(): bool { return true; }
    public function isTransactional(): bool { return false; }

    public function up(MigrationContext $context): void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_notification_sound_settings` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`muted` TINYINT(1) NOT NULL DEFAULT 0, `volume` TINYINT UNSIGNED NOT NULL DEFAULT 65, '
            . '`default_sound_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'soft\', '
            . '`updated_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`user_id`), '
            . 'CONSTRAINT `fk_forwext_notification_sound_settings_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_notification_sound_categories` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`category_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`enabled` TINYINT(1) NOT NULL DEFAULT 1, '
            . '`sound_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`user_id`,`category_key`), '
            . 'CONSTRAINT `fk_forwext_notification_sound_categories_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_notification_sound_settings','forwext_notification_sound_categories')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_notification_sound_settings','forwext_notification_sound_categories')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN '
            . "('notification.alert.view','notification.preference.manage')",
        ));
        return (int) $tables === 2 && (int) $foreignKeys === 2 && (int) $permissions === 2
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Notification sound schema or permission dependency is incomplete.');
    }
}
