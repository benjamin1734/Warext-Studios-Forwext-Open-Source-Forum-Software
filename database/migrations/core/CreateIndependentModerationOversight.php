<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateIndependentModerationOversight implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918006000_independent_moderation_oversight');
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
            "CREATE TABLE IF NOT EXISTS forwext_moderation_oversight_chain_state ("
            . "chain_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,"
            . "last_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "updated_at_utc DATETIME(6) NOT NULL,"
            . "PRIMARY KEY (chain_key)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));
        $context->execute(new CompiledQuery(
            "INSERT INTO forwext_moderation_oversight_chain_state "
            . "(chain_key,last_sequence,last_hash,updated_at_utc) "
            . "VALUES ('moderation',0,:genesis,UTC_TIMESTAMP(6)) "
            . "ON DUPLICATE KEY UPDATE chain_key=VALUES(chain_key)",
            ['genesis'=>str_repeat('0', 64)],
        ));

        $context->execute(new CompiledQuery(
            "CREATE TABLE IF NOT EXISTS forwext_moderation_oversight_entries ("
            . "sequence_no BIGINT UNSIGNED NOT NULL,"
            . "source_audit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "action VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "target_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "target_id VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "request_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "payload_json LONGTEXT NOT NULL,"
            . "payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "previous_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "chain_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "occurred_at_utc DATETIME(6) NOT NULL,"
            . "appended_at_utc DATETIME(6) NOT NULL,"
            . "PRIMARY KEY (sequence_no),"
            . "UNIQUE KEY uq_forwext_oversight_source_audit (source_audit_id),"
            . "KEY idx_forwext_oversight_actor (actor_user_id,occurred_at_utc),"
            . "KEY idx_forwext_oversight_request (request_id,occurred_at_utc),"
            . "KEY idx_forwext_oversight_action (action,occurred_at_utc),"
            . "KEY idx_forwext_oversight_chain_hash (chain_hash)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        $context->execute(new CompiledQuery(
            "CREATE TABLE IF NOT EXISTS forwext_moderation_oversight_review_cases ("
            . "case_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "source_audit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "opened_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "summary VARCHAR(1000) NOT NULL,"
            . "status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "opened_at_utc DATETIME(6) NOT NULL,"
            . "resolved_at_utc DATETIME(6) NULL,"
            . "resolved_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "resolution VARCHAR(1000) NULL,"
            . "PRIMARY KEY (case_id),"
            . "KEY idx_forwext_oversight_case_queue (status,opened_at_utc,case_id),"
            . "KEY idx_forwext_oversight_case_source (source_audit_id,opened_at_utc),"
            . "CONSTRAINT fk_forwext_oversight_case_source FOREIGN KEY (source_audit_id) "
            . "REFERENCES forwext_moderation_oversight_entries (source_audit_id) ON DELETE RESTRICT"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        $context->execute(new CompiledQuery(
            "CREATE TABLE IF NOT EXISTS forwext_moderation_oversight_anomaly_flags ("
            . "flag_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "source_audit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "flag_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "severity VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,"
            . "details VARCHAR(1000) NOT NULL,"
            . "created_at_utc DATETIME(6) NOT NULL,"
            . "resolved_at_utc DATETIME(6) NULL,"
            . "resolved_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "resolution VARCHAR(1000) NULL,"
            . "PRIMARY KEY (flag_id),"
            . "UNIQUE KEY uq_forwext_oversight_flag_source_type (source_audit_id,flag_type),"
            . "KEY idx_forwext_oversight_flag_queue (resolved_at_utc,severity,created_at_utc,flag_id),"
            . "CONSTRAINT fk_forwext_oversight_flag_source FOREIGN KEY (source_audit_id) "
            . "REFERENCES forwext_moderation_oversight_entries (source_audit_id) ON DELETE RESTRICT"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ));

        $context->execute(new CompiledQuery(
            "INSERT INTO forwext_permissions "
            . "(permission_key,value_type,description,created_at_utc,updated_at_utc) "
            . "VALUES ('audit.review','flag','Review independent moderation audit cases and anomaly flags.',"
            . "UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . "ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),"
            . "updated_at_utc=VALUES(updated_at_utc)",
        ));

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            $effect = in_array($templateKey, ['moderator','administrator'], true) ? 'allow' : 'deny';
            $context->execute(new CompiledQuery(
                "INSERT INTO forwext_permission_template_rules "
                . "(template_key,permission_key,effect,numeric_limit) "
                . "VALUES (:template_key,'audit.review',:effect,NULL) "
                . "ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL",
                ['template_key'=>$templateKey,'effect'=>$effect],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ("
            . "'forwext_moderation_oversight_chain_state','forwext_moderation_oversight_entries',"
            . "'forwext_moderation_oversight_review_cases','forwext_moderation_oversight_anomaly_flags')",
        ));
        $state = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_moderation_oversight_chain_state "
            . "WHERE chain_key='moderation' AND last_sequence>=0 AND CHAR_LENGTH(last_hash)=64",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ("
            . "'fk_forwext_oversight_case_source','fk_forwext_oversight_flag_source')",
        ));
        $permissionRules = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key='audit.review'",
        ));

        return (int) $tables === 4
            && (int) $state === 1
            && (int) $foreignKeys === 2
            && (int) $permissionRules === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Independent moderation oversight schema or permission defaults are incomplete.');
    }
}
