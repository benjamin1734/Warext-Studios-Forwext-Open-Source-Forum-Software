<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSearchIndexLifecycleTables implements Migration
{
    private const PERMISSION = 'search.use';

    /** @var array<string, array{table:string,id:string,type:string}> */
    private const SIMPLE_SOURCES = [
        'user' => ['table' => 'forwext_users', 'id' => 'user_id', 'type' => 'user'],
        'forum' => ['table' => 'forwext_nodes', 'id' => 'node_id', 'type' => 'forum'],
        'post' => ['table' => 'forwext_posts', 'id' => 'post_id', 'type' => 'post'],
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260917003000_search_index_lifecycle');
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
            'CREATE TABLE IF NOT EXISTS `forwext_search_index_changes` ('
            . '`document_type` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`document_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . '`revision` BIGINT UNSIGNED NOT NULL DEFAULT 1, '
            . '`attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0, '
            . '`available_at_utc` DATETIME(6) NOT NULL, '
            . '`locked_until_utc` DATETIME(6) NULL, '
            . '`last_error_code` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`document_type`,`document_id`), '
            . 'KEY `idx_forwext_search_index_changes_due` '
            . '(`available_at_utc`,`locked_until_utc`,`attempts`,`document_type`,`document_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $this->ensureLeaseColumn($context);

        $context->execute(new CompiledQuery(
            'INSERT INTO `forwext_permissions` '
            . '(`permission_key`,`value_type`,`description`,`created_at_utc`,`updated_at_utc`) '
            . "VALUES (:permission_key,'flag','Use permission-aware native search.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE `value_type`=VALUES(`value_type`), '
            . '`description`=VALUES(`description`), `updated_at_utc`=VALUES(`updated_at_utc`)',
            ['permission_key' => self::PERMISSION],
        ));
        foreach (['new_user', 'member', 'verified', 'moderator', 'administrator'] as $templateKey) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permission_template_rules` '
                . '(`template_key`,`permission_key`,`effect`,`numeric_limit`) '
                . "VALUES (:template_key,:permission_key,'allow',NULL) "
                . 'ON DUPLICATE KEY UPDATE `effect`=VALUES(`effect`), `numeric_limit`=NULL',
                ['template_key' => $templateKey, 'permission_key' => self::PERMISSION],
            ));
        }

        $this->seedExistingContent($context, 'user', 'forwext_users', 'user_id');
        $this->seedExistingContent($context, 'forum', 'forwext_nodes', 'node_id');
        $this->seedExistingContent($context, 'thread', 'forwext_threads', 'thread_id');
        $this->seedExistingContent($context, 'post', 'forwext_posts', 'post_id');

        foreach (self::SIMPLE_SOURCES as $name => $source) {
            $this->createSimpleTriggers($context, $name, $source['table'], $source['id'], $source['type']);
        }
        $this->createThreadTriggers($context);
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME`=\'forwext_search_index_changes\'',
        ));
        $leaseColumn = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS` '
            . 'WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME`=\'forwext_search_index_changes\' '
            . 'AND `COLUMN_NAME`=\'locked_until_utc\'',
        ));
        $triggers = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TRIGGERS` '
            . 'WHERE `TRIGGER_SCHEMA`=DATABASE() AND `TRIGGER_NAME` LIKE \'trg_forwext_search_%\'',
        ));
        $permission = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key`=:permission_key',
            ['permission_key' => self::PERMISSION],
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user','member','verified','moderator','administrator') "
            . 'AND `permission_key`=:permission_key AND `effect`=\'allow\'',
            ['permission_key' => self::PERMISSION],
        ));

        return (int) $table === 1
            && (int) $leaseColumn === 1
            && (int) $triggers === 13
            && (int) $permission === 1
            && (int) $rules === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Search lifecycle outbox, lease, triggers or permission defaults are incomplete.');
    }

    private function ensureLeaseColumn(MigrationContext $context): void
    {
        $exists = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`COLUMNS` '
            . 'WHERE `TABLE_SCHEMA`=DATABASE() AND `TABLE_NAME`=\'forwext_search_index_changes\' '
            . 'AND `COLUMN_NAME`=\'locked_until_utc\'',
        ));
        if ((int) $exists === 0) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE `forwext_search_index_changes` '
                . 'ADD COLUMN `locked_until_utc` DATETIME(6) NULL AFTER `available_at_utc`',
            ));
        }
    }

    private function seedExistingContent(MigrationContext $context, string $type, string $table, string $id): void
    {
        $context->execute(new CompiledQuery(
            'INSERT INTO `forwext_search_index_changes` '
            . '(`document_type`,`document_id`,`revision`,`attempts`,`available_at_utc`,`locked_until_utc`,`last_error_code`,`updated_at_utc`) '
            . 'SELECT :document_type, `' . $id . '`, 1, 0, UTC_TIMESTAMP(6), NULL, NULL, UTC_TIMESTAMP(6) '
            . 'FROM `' . $table . '` '
            . 'ON DUPLICATE KEY UPDATE `revision`=`revision`+1, `attempts`=0, '
            . '`available_at_utc`=UTC_TIMESTAMP(6), `locked_until_utc`=NULL, '
            . '`last_error_code`=NULL, `updated_at_utc`=UTC_TIMESTAMP(6)',
            ['document_type' => $type],
        ));
    }

    private function createSimpleTriggers(
        MigrationContext $context,
        string $name,
        string $table,
        string $id,
        string $type,
    ): void {
        foreach (['insert' => 'NEW', 'update' => 'NEW', 'delete' => 'OLD'] as $event => $rowAlias) {
            $trigger = 'trg_forwext_search_' . $name . '_' . $event;
            $context->execute(new CompiledQuery('DROP TRIGGER IF EXISTS `' . $trigger . '`'));
            $context->execute(new CompiledQuery(
                'CREATE TRIGGER `' . $trigger . '` AFTER ' . strtoupper($event)
                . ' ON `' . $table . '` FOR EACH ROW '
                . self::queueValueSql($type, $rowAlias . '.`' . $id . '`'),
            ));
        }
    }

    private function createThreadTriggers(MigrationContext $context): void
    {
        foreach ([
            'trg_forwext_search_thread_insert',
            'trg_forwext_search_thread_update',
            'trg_forwext_search_thread_before_delete',
            'trg_forwext_search_thread_delete',
        ] as $trigger) {
            $context->execute(new CompiledQuery('DROP TRIGGER IF EXISTS `' . $trigger . '`'));
        }

        $context->execute(new CompiledQuery(
            'CREATE TRIGGER `trg_forwext_search_thread_insert` AFTER INSERT ON `forwext_threads` FOR EACH ROW '
            . self::queueValueSql('thread', 'NEW.`thread_id`'),
        ));

        $context->execute(new CompiledQuery(
            'CREATE TRIGGER `trg_forwext_search_thread_update` AFTER UPDATE ON `forwext_threads` FOR EACH ROW '
            . 'BEGIN '
            . self::queueValueSql('thread', 'NEW.`thread_id`') . '; '
            . 'IF NOT (OLD.`forum_node_id` <=> NEW.`forum_node_id`) '
            . 'OR NOT (OLD.`title` <=> NEW.`title`) '
            . 'OR NOT (OLD.`moderation_state` <=> NEW.`moderation_state`) '
            . 'OR NOT (OLD.`deleted` <=> NEW.`deleted`) '
            . 'OR NOT (OLD.`merged_into_thread_id` <=> NEW.`merged_into_thread_id`) THEN '
            . self::queueThreadPostsSql('NEW.`thread_id`') . '; '
            . 'END IF; END',
        ));

        // MySQL does not execute child-table triggers for rows removed by an FK cascade.
        // Capture post ids before the thread delete so stale post documents are still removed asynchronously.
        $context->execute(new CompiledQuery(
            'CREATE TRIGGER `trg_forwext_search_thread_before_delete` BEFORE DELETE ON `forwext_threads` FOR EACH ROW '
            . self::queueThreadPostsSql('OLD.`thread_id`'),
        ));
        $context->execute(new CompiledQuery(
            'CREATE TRIGGER `trg_forwext_search_thread_delete` AFTER DELETE ON `forwext_threads` FOR EACH ROW '
            . self::queueValueSql('thread', 'OLD.`thread_id`'),
        ));
    }

    private static function queueValueSql(string $type, string $idExpression): string
    {
        return 'INSERT INTO `forwext_search_index_changes` '
            . '(`document_type`,`document_id`,`revision`,`attempts`,`available_at_utc`,`locked_until_utc`,`last_error_code`,`updated_at_utc`) '
            . "VALUES ('" . $type . "', " . $idExpression . ', 1, 0, UTC_TIMESTAMP(6), NULL, NULL, UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `revision`=`revision`+1, `attempts`=0, '
            . '`available_at_utc`=UTC_TIMESTAMP(6), `locked_until_utc`=NULL, '
            . '`last_error_code`=NULL, `updated_at_utc`=UTC_TIMESTAMP(6)';
    }

    private static function queueThreadPostsSql(string $threadIdExpression): string
    {
        return 'INSERT INTO `forwext_search_index_changes` '
            . '(`document_type`,`document_id`,`revision`,`attempts`,`available_at_utc`,`locked_until_utc`,`last_error_code`,`updated_at_utc`) '
            . "SELECT 'post', p.`post_id`, 1, 0, UTC_TIMESTAMP(6), NULL, NULL, UTC_TIMESTAMP(6) "
            . 'FROM `forwext_posts` p WHERE p.`thread_id`=' . $threadIdExpression . ' '
            . 'ON DUPLICATE KEY UPDATE `revision`=`revision`+1, `attempts`=0, '
            . '`available_at_utc`=UTC_TIMESTAMP(6), `locked_until_utc`=NULL, '
            . '`last_error_code`=NULL, `updated_at_utc`=UTC_TIMESTAMP(6)';
    }
}
