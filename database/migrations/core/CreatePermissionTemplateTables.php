<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreatePermissionTemplateTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915220000_permission_templates');
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
            'CREATE TABLE IF NOT EXISTS `forwext_permission_templates` ('
            . '`template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`name` VARCHAR(100) NOT NULL, '
            . '`description` VARCHAR(255) NOT NULL DEFAULT \'\', '
            . '`is_system` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, '
            . '`sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`created_at_utc` DATETIME(6) NOT NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`template_key`), '
            . 'KEY `idx_forwext_permission_template_sort` (`sort_order`, `template_key`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_permission_template_rules` ('
            . '`template_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`permission_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`effect` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`numeric_limit` BIGINT UNSIGNED NULL, '
            . 'PRIMARY KEY (`template_key`, `permission_key`), '
            . 'KEY `idx_forwext_permission_template_rule_permission` (`permission_key`, `template_key`), '
            . 'CONSTRAINT `fk_forwext_permission_template_rule_template` FOREIGN KEY (`template_key`) REFERENCES `forwext_permission_templates` (`template_key`) ON DELETE CASCADE, '
            . 'CONSTRAINT `fk_forwext_permission_template_rule_permission` FOREIGN KEY (`permission_key`) REFERENCES `forwext_permissions` (`permission_key`) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO `forwext_permissions` (`permission_key`, `value_type`, `description`, `created_at_utc`, `updated_at_utc`) VALUES '
            . "('forum.view', 'flag', 'View forum content.', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('forum.thread.create', 'flag', 'Create forum threads.', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('forum.post.create', 'flag', 'Create forum posts and replies.', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('forum.content.daily_limit', 'numeric', 'Maximum daily forum content creation baseline.', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('moderation.access', 'flag', 'Access moderation surfaces.', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('moderation.manage', 'flag', 'Perform moderation management actions.', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('acp.access', 'flag', 'Access the administration control panel.', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('acp.manage', 'flag', 'Perform administration management actions.', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE `value_type` = VALUES(`value_type`), `description` = VALUES(`description`), `updated_at_utc` = VALUES(`updated_at_utc`)',
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO `forwext_permission_templates` (`template_key`, `name`, `description`, `is_system`, `sort_order`, `created_at_utc`, `updated_at_utc`) VALUES '
            . "('new_user', 'New User', 'Safe starter profile for newly registered accounts.', 1, 10, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('member', 'Member', 'Default profile for established forum members.', 1, 20, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('verified', 'Verified Member', 'Higher forum limits for verified members.', 1, 30, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('moderator', 'Moderator', 'Forum moderation profile without ACP administration authority.', 1, 40, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)), "
            . "('administrator', 'Administrator', 'Administration profile with moderation and ACP authority.', 1, 50, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`), `is_system` = VALUES(`is_system`), `sort_order` = VALUES(`sort_order`), `updated_at_utc` = VALUES(`updated_at_utc`)',
        ));

        $rows = [
            ['new_user', 'forum.view', 'allow', null],
            ['new_user', 'forum.thread.create', 'deny', null],
            ['new_user', 'forum.post.create', 'allow', null],
            ['new_user', 'forum.content.daily_limit', 'allow', 10],
            ['new_user', 'moderation.access', 'deny', null],
            ['new_user', 'moderation.manage', 'deny', null],
            ['new_user', 'acp.access', 'deny', null],
            ['new_user', 'acp.manage', 'deny', null],
            ['member', 'forum.view', 'allow', null],
            ['member', 'forum.thread.create', 'allow', null],
            ['member', 'forum.post.create', 'allow', null],
            ['member', 'forum.content.daily_limit', 'allow', 100],
            ['member', 'moderation.access', 'deny', null],
            ['member', 'moderation.manage', 'deny', null],
            ['member', 'acp.access', 'deny', null],
            ['member', 'acp.manage', 'deny', null],
            ['verified', 'forum.view', 'allow', null],
            ['verified', 'forum.thread.create', 'allow', null],
            ['verified', 'forum.post.create', 'allow', null],
            ['verified', 'forum.content.daily_limit', 'allow', 250],
            ['verified', 'moderation.access', 'deny', null],
            ['verified', 'moderation.manage', 'deny', null],
            ['verified', 'acp.access', 'deny', null],
            ['verified', 'acp.manage', 'deny', null],
            ['moderator', 'forum.view', 'allow', null],
            ['moderator', 'forum.thread.create', 'allow', null],
            ['moderator', 'forum.post.create', 'allow', null],
            ['moderator', 'forum.content.daily_limit', 'allow', 500],
            ['moderator', 'moderation.access', 'allow', null],
            ['moderator', 'moderation.manage', 'allow', null],
            ['moderator', 'acp.access', 'deny', null],
            ['moderator', 'acp.manage', 'deny', null],
            ['administrator', 'forum.view', 'allow', null],
            ['administrator', 'forum.thread.create', 'allow', null],
            ['administrator', 'forum.post.create', 'allow', null],
            ['administrator', 'forum.content.daily_limit', 'allow', 5000],
            ['administrator', 'moderation.access', 'allow', null],
            ['administrator', 'moderation.manage', 'allow', null],
            ['administrator', 'acp.access', 'allow', null],
            ['administrator', 'acp.manage', 'allow', null],
        ];

        foreach ($rows as [$templateKey, $permissionKey, $effect, $numericLimit]) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permission_template_rules` (`template_key`, `permission_key`, `effect`, `numeric_limit`) '
                . 'VALUES (:template_key, :permission_key, :effect, :numeric_limit) '
                . 'ON DUPLICATE KEY UPDATE `effect` = VALUES(`effect`), `numeric_limit` = VALUES(`numeric_limit`)',
                [
                    'template_key' => $templateKey,
                    'permission_key' => $permissionKey,
                    'effect' => $effect,
                    'numeric_limit' => $numericLimit,
                ],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_permission_templates', 'forwext_permission_template_rules')",
        ));
        $systemTemplates = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_templates` '
            . "WHERE `template_key` IN ('new_user', 'member', 'verified', 'moderator', 'administrator') AND `is_system` = 1",
        ));
        $seedRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user', 'member', 'verified', 'moderator', 'administrator')",
        ));

        return (int) $tables === 2 && (int) $systemTemplates === 5 && (int) $seedRules === 40
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Permission template schema or built-in profiles are incomplete.');
    }
}
