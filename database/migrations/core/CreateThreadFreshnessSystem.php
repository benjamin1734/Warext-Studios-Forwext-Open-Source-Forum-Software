<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateThreadFreshnessSystem implements Migration
{
    private const PERMISSIONS = [
        'forum.thread.freshness.renew_own' => 'Renew freshness for an authored thread in an authorized forum.',
        'forum.thread.freshness.renew_any' => 'Renew or reopen freshness for any thread in an authorized forum.',
        'forum.thread.freshness.review' => 'Review stale-thread freshness cases in an authorized forum.',
        'forum.thread.freshness.manage_policy' => 'Manage per-forum thread freshness policy.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919100000_thread_freshness_system');
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
        $this->addThreadColumnIfMissing(
            $context,
            'archived',
            'ALTER TABLE forwext_threads ADD COLUMN archived TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER merged_into_thread_id',
        );
        $this->addThreadColumnIfMissing(
            $context,
            'archived_at_utc',
            'ALTER TABLE forwext_threads ADD COLUMN archived_at_utc DATETIME(6) NULL AFTER archived',
        );

        if ((int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_threads' AND INDEX_NAME='idx_forwext_threads_freshness_listing'",
        )) === 0) {
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_threads ADD INDEX idx_forwext_threads_freshness_listing '
                . '(forum_node_id,archived,deleted,merged_into_thread_id,updated_at_utc,thread_id)',
            ));
        }

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_thread_freshness_policies ('
            . 'forum_node_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'stale_after_days SMALLINT UNSIGNED NOT NULL,'
            . 'notify_after_days SMALLINT UNSIGNED NULL,'
            . 'auto_lock_after_days SMALLINT UNSIGNED NULL,'
            . 'auto_archive_after_days SMALLINT UNSIGNED NULL,'
            . 'auto_unfeature_after_days SMALLINT UNSIGNED NULL,'
            . 'moderator_review_after_days SMALLINT UNSIGNED NULL,'
            . 'renewal_cooldown_hours SMALLINT UNSIGNED NOT NULL DEFAULT 24,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (forum_node_id),'
            . 'CONSTRAINT fk_forwext_thread_freshness_policy_forum FOREIGN KEY (forum_node_id) '
            . 'REFERENCES forwext_nodes (node_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_thread_freshness_state ('
            . 'thread_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'last_activity_at_utc DATETIME(6) NOT NULL,'
            . 'last_renewed_at_utc DATETIME(6) NULL,'
            . 'renew_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'notified_at_utc DATETIME(6) NULL,'
            . 'review_requested_at_utc DATETIME(6) NULL,'
            . 'auto_locked_at_utc DATETIME(6) NULL,'
            . 'auto_archived_at_utc DATETIME(6) NULL,'
            . 'auto_unfeatured_at_utc DATETIME(6) NULL,'
            . 'last_evaluated_at_utc DATETIME(6) NULL,'
            . 'PRIMARY KEY (thread_id),'
            . 'KEY idx_forwext_thread_freshness_due (last_activity_at_utc,last_evaluated_at_utc,thread_id),'
            . 'CONSTRAINT fk_forwext_thread_freshness_state_thread FOREIGN KEY (thread_id) '
            . 'REFERENCES forwext_threads (thread_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_thread_freshness_reviews ('
            . 'review_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'thread_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'requested_at_utc DATETIME(6) NOT NULL,'
            . 'resolved_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'resolved_at_utc DATETIME(6) NULL,'
            . 'resolution VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'PRIMARY KEY (review_id),'
            . 'KEY idx_forwext_thread_freshness_reviews_pending (status,requested_at_utc,thread_id),'
            . 'KEY idx_forwext_thread_freshness_reviews_thread (thread_id,requested_at_utc),'
            . 'CONSTRAINT fk_forwext_thread_freshness_review_thread FOREIGN KEY (thread_id) '
            . 'REFERENCES forwext_threads (thread_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_thread_freshness_review_actor FOREIGN KEY (resolved_by_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT IGNORE INTO forwext_thread_freshness_state (thread_id,last_activity_at_utc,renew_count) '
            . 'SELECT t.thread_id,GREATEST(t.created_at_utc,COALESCE(MAX(p.updated_at_utc),t.updated_at_utc)),0 '
            . 'FROM forwext_threads t LEFT JOIN forwext_posts p ON p.thread_id=t.thread_id '
            . 'GROUP BY t.thread_id,t.created_at_utc,t.updated_at_utc',
        ));

        foreach (self::PERMISSIONS as $key=>$description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:permission_key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$key,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (array_keys(self::PERMISSIONS) as $permissionKey) {
                $effect = match ($permissionKey) {
                    'forum.thread.freshness.renew_own' => 'allow',
                    'forum.thread.freshness.renew_any',
                    'forum.thread.freshness.review' => in_array($templateKey,['moderator','administrator'],true) ? 'allow' : 'deny',
                    'forum.thread.freshness.manage_policy' => $templateKey === 'administrator' ? 'allow' : 'deny',
                    default => 'deny',
                };
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template_key,:permission_key,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    ['template_key'=>$templateKey,'permission_key'=>$permissionKey,'effect'=>$effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $columns = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_threads' AND COLUMN_NAME IN ('archived','archived_at_utc')",
        ));
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_thread_freshness_policies','forwext_thread_freshness_state',"
            . "'forwext_thread_freshness_reviews')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() '
            . "AND CONSTRAINT_NAME IN ('fk_forwext_thread_freshness_policy_forum',"
            . "'fk_forwext_thread_freshness_state_thread','fk_forwext_thread_freshness_review_thread',"
            . "'fk_forwext_thread_freshness_review_actor')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN '
            . "('forum.thread.freshness.renew_own','forum.thread.freshness.renew_any',"
            . "'forum.thread.freshness.review','forum.thread.freshness.manage_policy')",
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('forum.thread.freshness.renew_own','forum.thread.freshness.renew_any',"
            . "'forum.thread.freshness.review','forum.thread.freshness.manage_policy')",
        ));
        $index = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_threads' AND INDEX_NAME='idx_forwext_threads_freshness_listing'",
        ));

        return (int) $columns === 2
            && (int) $tables === 3
            && (int) $foreignKeys === 4
            && (int) $permissions === 4
            && (int) $rules === 20
            && (int) $index >= 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Thread freshness policy, state, archive or permission schema is incomplete.');
    }

    private function addThreadColumnIfMissing(MigrationContext $context, string $column, string $sql): void
    {
        $exists = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_threads' AND COLUMN_NAME=:column_name",
            ['column_name'=>$column],
        ));
        if ((int) $exists === 0) {
            $context->execute(new CompiledQuery($sql));
        }
    }
}
