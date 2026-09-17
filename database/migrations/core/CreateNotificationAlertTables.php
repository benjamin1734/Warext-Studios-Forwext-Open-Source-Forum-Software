<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateNotificationAlertTables implements Migration
{
    private const PERMISSIONS = [
        'notification.alert.view' => 'View and mark the signed-in account notification inbox.',
        'notification.preference.manage' => 'Manage notification channel preferences for the signed-in account.',
    ];

    public function id(): MigrationId { return MigrationId::fromString('20260917001000_notification_alerts'); }
    public function owner(): MigrationOwner { return MigrationOwner::core(); }
    public function isIdempotent(): bool { return true; }
    public function isTransactional(): bool { return false; }

    public function up(MigrationContext $context): void
    {
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_notifications` ('
            . '`notification_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`recipient_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`type_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`category_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`group_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`title` VARCHAR(255) NOT NULL, `body` VARCHAR(2000) NOT NULL, `action_path` VARCHAR(1000) NULL, '
            . '`payload_json` LONGTEXT NOT NULL, `in_app_visible` TINYINT(1) NOT NULL DEFAULT 1, '
            . '`occurrences` INT UNSIGNED NOT NULL DEFAULT 1, `created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, `read_at_utc` DATETIME(6) NULL, '
            . 'PRIMARY KEY (`notification_id`), '
            . 'KEY `idx_forwext_notifications_inbox` (`recipient_user_id`,`in_app_visible`,`read_at_utc`,`updated_at_utc`), '
            . 'KEY `idx_forwext_notifications_group` (`recipient_user_id`,`type_key`,`group_key`,`read_at_utc`), '
            . 'CONSTRAINT `fk_forwext_notifications_recipient` FOREIGN KEY (`recipient_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_notification_dedupes` ('
            . '`recipient_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`dedupe_key` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`notification_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `created_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`recipient_user_id`,`dedupe_key`), KEY `idx_forwext_notification_dedupes_notification` (`notification_id`), '
            . 'CONSTRAINT `fk_forwext_notification_dedupes_user` FOREIGN KEY (`recipient_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_notification_dedupes_notification` FOREIGN KEY (`notification_id`) '
            . 'REFERENCES `forwext_notifications` (`notification_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_notification_preferences` ('
            . '`user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`category_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`channel` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `enabled` TINYINT(1) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, PRIMARY KEY (`user_id`,`category_key`,`channel`), '
            . 'CONSTRAINT `fk_forwext_notification_preferences_user` FOREIGN KEY (`user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_notification_deliveries` ('
            . '`notification_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`channel` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`available_at_utc` DATETIME(6) NULL, `sent_at_utc` DATETIME(6) NULL, '
            . '`last_error_code` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL, `updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`notification_id`,`channel`), KEY `idx_forwext_notification_deliveries_due` (`status`,`available_at_utc`), '
            . 'CONSTRAINT `fk_forwext_notification_deliveries_notification` FOREIGN KEY (`notification_id`) '
            . 'REFERENCES `forwext_notifications` (`notification_id`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach (self::PERMISSIONS as $key => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permissions` (`permission_key`,`value_type`,`description`,`created_at_utc`,`updated_at_utc`) '
                . "VALUES (:permission_key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE `value_type`=VALUES(`value_type`), `description`=VALUES(`description`), '
                . '`updated_at_utc`=VALUES(`updated_at_utc`)',
                ['permission_key' => $key, 'description' => $description],
            ));
        }
        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
                $context->execute(new CompiledQuery(
                    'INSERT INTO `forwext_permission_template_rules` (`template_key`,`permission_key`,`effect`,`numeric_limit`) '
                    . "VALUES (:template_key,:permission_key,'allow',NULL) "
                    . 'ON DUPLICATE KEY UPDATE `effect`=VALUES(`effect`), `numeric_limit`=NULL',
                    ['template_key' => $templateKey, 'permission_key' => $permissionKey],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_notifications','forwext_notification_dedupes','forwext_notification_preferences','forwext_notification_deliveries')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_notifications','forwext_notification_dedupes','forwext_notification_preferences','forwext_notification_deliveries')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN '
            . "('notification.alert.view','notification.preference.manage')",
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user','member','verified','moderator','administrator') "
            . "AND `permission_key` IN ('notification.alert.view','notification.preference.manage')",
        ));
        return (int) $tables === 4 && (int) $foreignKeys === 5 && (int) $permissions === 2 && (int) $rules === 10
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Notification schema, permissions or starter rules are incomplete.');
    }
}
