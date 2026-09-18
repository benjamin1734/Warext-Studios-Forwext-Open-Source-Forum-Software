<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateCoreAuditStream implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918005000_core_audit_stream');
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
            "CREATE TABLE IF NOT EXISTS forwext_core_audit_events ("
            . "audit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "scope VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "action VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "target_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "target_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "forum_node_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "before_json LONGTEXT NOT NULL,"
            . "after_json LONGTEXT NOT NULL,"
            . "occurred_at_utc DATETIME(6) NOT NULL,"
            . "PRIMARY KEY (audit_id),"
            . "KEY idx_forwext_core_audit_time (occurred_at_utc,audit_id),"
            . "KEY idx_forwext_core_audit_scope_time (scope,occurred_at_utc,audit_id),"
            . "KEY idx_forwext_core_audit_target (target_type,target_id,occurred_at_utc),"
            . "KEY idx_forwext_core_audit_actor (actor_user_id,occurred_at_utc),"
            . "KEY idx_forwext_core_audit_request (request_id,occurred_at_utc)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        $legacyExists = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_moderation_audit_events'",
        ));
        if ($legacyExists === 1) {
            $context->execute(new CompiledQuery(
                "INSERT INTO forwext_core_audit_events "
                . "(audit_id,scope,actor_user_id,action,target_type,target_id,forum_node_id,reason_code,request_id,"
                . "before_json,after_json,occurred_at_utc) "
                . "SELECT audit_id,'moderation',actor_user_id,action,target_type,target_id,forum_node_id,reason_code,request_id,"
                . "JSON_OBJECT('legacy_snapshot','[REDACTED_DURING_MIGRATION]'),"
                . "JSON_OBJECT('legacy_snapshot','[REDACTED_DURING_MIGRATION]'),occurred_at_utc "
                . "FROM forwext_moderation_audit_events "
                . "ON DUPLICATE KEY UPDATE audit_id=VALUES(audit_id)",
            ));
        }

        $context->execute(new CompiledQuery(
            "INSERT INTO forwext_permissions "
            . "(permission_key,value_type,description,created_at_utc,updated_at_utc) "
            . "VALUES ('audit.view','flag','View authorized core audit records.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . "ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),"
            . "updated_at_utc=VALUES(updated_at_utc)",
        ));

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            $effect = in_array($templateKey, ['moderator','administrator'], true) ? 'allow' : 'deny';
            $context->execute(new CompiledQuery(
                "INSERT INTO forwext_permission_template_rules "
                . "(template_key,permission_key,effect,numeric_limit) "
                . "VALUES (:template_key,'audit.view',:effect,NULL) "
                . "ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL",
                ['template_key'=>$templateKey,'effect'=>$effect],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_core_audit_events'",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_core_audit_events' "
            . "AND INDEX_NAME IN ('idx_forwext_core_audit_time','idx_forwext_core_audit_scope_time',"
            . "'idx_forwext_core_audit_target','idx_forwext_core_audit_actor','idx_forwext_core_audit_request')",
        ));
        $legacyMissing = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_moderation_audit_events legacy "
            . "LEFT JOIN forwext_core_audit_events central ON central.audit_id=legacy.audit_id "
            . "WHERE central.audit_id IS NULL",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key='audit.view'",
        ));

        return (int) $table === 1
            && (int) $indexes === 5
            && (int) $legacyMissing === 0
            && (int) $templateRules === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Core audit stream, legacy import or audit-view defaults are incomplete.');
    }
}
