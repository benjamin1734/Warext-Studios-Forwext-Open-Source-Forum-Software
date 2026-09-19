<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateContentManagerSystem implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919090000_content_manager_system');
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
            'CREATE TABLE IF NOT EXISTS forwext_content_manager_operations ('
            . 'operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'target_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'action VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'content_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'filter_forum_node_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'filter_moderation_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'filter_deleted TINYINT(1) UNSIGNED NULL,'
            . 'filter_query VARCHAR(200) NULL,'
            . 'target_forum_node_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'total_count INT UNSIGNED NOT NULL,'
            . 'processed_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'succeeded_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'skipped_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'failed_count INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'started_at_utc DATETIME(6) NULL,'
            . 'completed_at_utc DATETIME(6) NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (operation_id),'
            . 'KEY idx_forwext_content_manager_actor (actor_user_id,created_at_utc,operation_id),'
            . 'KEY idx_forwext_content_manager_target (target_user_id,created_at_utc,operation_id),'
            . 'KEY idx_forwext_content_manager_status (status,updated_at_utc,operation_id),'
            . 'CONSTRAINT fk_forwext_content_manager_actor FOREIGN KEY (actor_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_content_manager_filter_forum FOREIGN KEY (filter_forum_node_id) '
            . 'REFERENCES forwext_nodes (node_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_content_manager_target_forum FOREIGN KEY (target_forum_node_id) '
            . 'REFERENCES forwext_nodes (node_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_content_manager_operation_items ('
            . 'operation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'content_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'content_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'forum_node_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'failure_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'processing_started_at_utc DATETIME(6) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (operation_id,content_type,content_id),'
            . 'KEY idx_forwext_content_manager_items_work (operation_id,status,content_type,content_id),'
            . 'KEY idx_forwext_content_manager_items_forum (forum_node_id,status,operation_id),'
            . 'CONSTRAINT fk_forwext_content_manager_items_operation FOREIGN KEY (operation_id) '
            . 'REFERENCES forwext_content_manager_operations (operation_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_content_manager_items_forum FOREIGN KEY (forum_node_id) '
            . 'REFERENCES forwext_nodes (node_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach ([
            'content_manager.access'=>'Access the user content manager and filtered content inventory.',
            'content_manager.execute'=>'Execute bounded bulk content-manager operations.',
        ] as $permissionKey=>$description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . 'VALUES (:permission_key,\'flag\',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$permissionKey,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            $effect = in_array($templateKey, ['moderator','administrator'], true) ? 'allow' : 'deny';
            foreach (['content_manager.access','content_manager.execute'] as $permissionKey) {
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template_key,:permission_key,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    [
                        'template_key'=>$templateKey,
                        'permission_key'=>$permissionKey,
                        'effect'=>$effect,
                    ],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_content_manager_operations','forwext_content_manager_operation_items')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() '
            . "AND CONSTRAINT_NAME IN ('fk_forwext_content_manager_actor','fk_forwext_content_manager_filter_forum',"
            . "'fk_forwext_content_manager_target_forum','fk_forwext_content_manager_items_operation',"
            . "'fk_forwext_content_manager_items_forum')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permissions '
            . "WHERE permission_key IN ('content_manager.access','content_manager.execute') AND value_type='flag'",
        ));
        $rules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('content_manager.access','content_manager.execute')",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,\':\',INDEX_NAME)) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND ('
            . "(TABLE_NAME='forwext_content_manager_operations' AND INDEX_NAME IN "
            . "('idx_forwext_content_manager_actor','idx_forwext_content_manager_target','idx_forwext_content_manager_status')) OR "
            . "(TABLE_NAME='forwext_content_manager_operation_items' AND INDEX_NAME IN "
            . "('idx_forwext_content_manager_items_work','idx_forwext_content_manager_items_forum')))",
        ));

        return (int) $tables === 2
            && (int) $foreignKeys === 5
            && (int) $permissions === 2
            && (int) $rules === 10
            && (int) $indexes === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Content manager queue, progress or permission schema is incomplete.');
    }
}
