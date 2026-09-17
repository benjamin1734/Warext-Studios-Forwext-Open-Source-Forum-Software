<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateModerationWorkspaceTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918001000_moderation_workspace_tasks');
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
            'CREATE TABLE IF NOT EXISTS `forwext_moderation_tasks` ('
            . '`task_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`title` VARCHAR(200) NOT NULL,'
            . '`description` TEXT NOT NULL,'
            . "`priority` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'normal',"
            . "`status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'open',"
            . '`created_by_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . '`assigned_user_id` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . '`due_at_utc` DATETIME(6) NULL,'
            . '`created_at_utc` DATETIME(6) NOT NULL,'
            . '`updated_at_utc` DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (`task_id`),'
            . 'KEY `idx_forwext_mod_tasks_state` (`status`, `priority`, `updated_at_utc`),'
            . 'KEY `idx_forwext_mod_tasks_assignee` (`assigned_user_id`, `status`, `updated_at_utc`),'
            . 'CONSTRAINT `fk_forwext_mod_tasks_creator` FOREIGN KEY (`created_by_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE RESTRICT,'
            . 'CONSTRAINT `fk_forwext_mod_tasks_assignee` FOREIGN KEY (`assigned_user_id`) '
            . 'REFERENCES `forwext_users` (`user_id`) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` = 'forwext_moderation_tasks'",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`REFERENTIAL_CONSTRAINTS` '
            . 'WHERE `CONSTRAINT_SCHEMA` = DATABASE() '
            . "AND `CONSTRAINT_NAME` IN ('fk_forwext_mod_tasks_creator', 'fk_forwext_mod_tasks_assignee')",
        ));

        return (int) $table === 1 && (int) $foreignKeys === 2
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Moderation task table or user foreign keys are missing.');
    }
}
