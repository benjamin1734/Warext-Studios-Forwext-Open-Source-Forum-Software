<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateReportSystemTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918002000_report_system');
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
            'CREATE TABLE IF NOT EXISTS `forwext_report_reasons` ('
            . '`reason_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`label` VARCHAR(100) NOT NULL,'
            . '`description` VARCHAR(255) NOT NULL DEFAULT \'\','
            . '`sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . '`active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . '`created_at_utc` DATETIME(6) NOT NULL,'
            . '`updated_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`reason_key`),'
            . 'KEY `idx_forwext_report_reasons_active` (`active`,`sort_order`,`reason_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach ([
            ['spam', 'Spam', 'Spam, istenmeyen reklam veya tekrar eden içerik.', 10],
            ['harassment', 'Taciz / Hakaret', 'Taciz, tehdit, hakaret veya hedef gösterme.', 20],
            ['privacy', 'Gizlilik', 'İzinsiz kişisel veri veya mahremiyet ihlali.', 30],
            ['illegal', 'Yasa dışı içerik', 'Yasa dışı olabileceği düşünülen içerik.', 40],
            ['other', 'Diğer', 'Diğer kural veya güvenlik ihlalleri.', 100],
        ] as [$key, $label, $description, $sortOrder]) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_report_reasons` '
                . '(`reason_key`,`label`,`description`,`sort_order`,`active`,`created_at_utc`,`updated_at_utc`) '
                . 'VALUES (:reason_key,:label,:description,:sort_order,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `label`=VALUES(`label`),`description`=VALUES(`description`),'
                . '`sort_order`=VALUES(`sort_order`),`updated_at_utc`=VALUES(`updated_at_utc`)',
                [
                    'reason_key' => $key,
                    'label' => $label,
                    'description' => $description,
                    'sort_order' => $sortOrder,
                ],
            ));
        }

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_report_groups` ('
            . '`group_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`target_type` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`target_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`target_title_snapshot` VARCHAR(255) NOT NULL,'
            . '`reason_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`reason_label_snapshot` VARCHAR(100) NOT NULL,'
            . "`status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',"
            . '`assigned_moderator_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`report_count` INT UNSIGNED NOT NULL DEFAULT 1,'
            . '`active_dedupe_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`created_at_utc` DATETIME(6) NOT NULL,'
            . '`updated_at_utc` DATETIME(6) NOT NULL,'
            . '`latest_report_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`group_id`),'
            . 'UNIQUE KEY `uq_forwext_report_active_dedupe` (`active_dedupe_key`),'
            . 'KEY `idx_forwext_report_queue` (`status`,`latest_report_at_utc`,`group_id`),'
            . 'KEY `idx_forwext_report_assignee` (`assigned_moderator_user_id`,`status`,`latest_report_at_utc`),'
            . 'CONSTRAINT `fk_forwext_report_group_reason` FOREIGN KEY (`reason_key`) '
            . 'REFERENCES `forwext_report_reasons` (`reason_key`) ON DELETE RESTRICT,'
            . 'CONSTRAINT `fk_forwext_report_group_assignee` FOREIGN KEY (`assigned_moderator_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_reports` ('
            . '`report_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`group_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`reporter_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`detail` VARCHAR(2000) NOT NULL DEFAULT \'\','
            . '`created_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`report_id`),'
            . 'UNIQUE KEY `uq_forwext_report_group_reporter` (`group_id`,`reporter_user_id`),'
            . 'KEY `idx_forwext_reports_reporter` (`reporter_user_id`,`created_at_utc`,`report_id`),'
            . 'CONSTRAINT `fk_forwext_reports_group` FOREIGN KEY (`group_id`) '
            . 'REFERENCES `forwext_report_groups` (`group_id`) ON DELETE CASCADE,'
            . 'CONSTRAINT `fk_forwext_reports_reporter` FOREIGN KEY (`reporter_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_report_comments` ('
            . '`comment_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`group_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`moderator_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`body` TEXT NOT NULL,'
            . '`created_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`comment_id`),'
            . 'KEY `idx_forwext_report_comments_group` (`group_id`,`created_at_utc`,`comment_id`),'
            . 'CONSTRAINT `fk_forwext_report_comments_group` FOREIGN KEY (`group_id`) '
            . 'REFERENCES `forwext_report_groups` (`group_id`) ON DELETE CASCADE,'
            . 'CONSTRAINT `fk_forwext_report_comments_moderator` FOREIGN KEY (`moderator_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO `forwext_permissions` '
            . '(`permission_key`,`value_type`,`description`,`created_at_utc`,`updated_at_utc`) '
            . "VALUES ('report.create','flag','Report accessible content for moderation review.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE `value_type`=VALUES(`value_type`),`description`=VALUES(`description`),'
            . '`updated_at_utc`=VALUES(`updated_at_utc`)',
        ));
        foreach (['new_user', 'member', 'verified', 'moderator', 'administrator'] as $templateKey) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permission_template_rules` '
                . '(`template_key`,`permission_key`,`effect`,`numeric_limit`) '
                . "VALUES (:template_key,'report.create','allow',NULL) "
                . 'ON DUPLICATE KEY UPDATE `effect`=VALUES(`effect`),`numeric_limit`=NULL',
                ['template_key' => $templateKey],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA`=DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_report_reasons','forwext_report_groups','forwext_reports','forwext_report_comments')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA`=DATABASE() '
            . "AND `CONSTRAINT_NAME` IN ('fk_forwext_report_group_reason','fk_forwext_report_group_assignee',"
            . "'fk_forwext_reports_group','fk_forwext_reports_reporter','fk_forwext_report_comments_group','fk_forwext_report_comments_moderator')",
        ));
        $reasons = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_report_reasons` WHERE `reason_key` IN ('spam','harassment','privacy','illegal','other')",
        ));
        $permission = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key`='report.create' AND `value_type`='flag'",
        ));
        $templates = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user','member','verified','moderator','administrator') "
            . "AND `permission_key`='report.create' AND `effect`='allow'",
        ));
        $dedupeIndex = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` WHERE `TABLE_SCHEMA`=DATABASE() '
            . "AND `TABLE_NAME`='forwext_report_groups' AND `INDEX_NAME`='uq_forwext_report_active_dedupe' AND `NON_UNIQUE`=0",
        ));

        return (int) $tables === 4
            && (int) $foreignKeys === 6
            && (int) $reasons === 5
            && (int) $permission === 1
            && (int) $templates === 5
            && (int) $dedupeIndex === 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Report system schema, permissions, reasons or duplicate-group index are incomplete.');
    }
}
