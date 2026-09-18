<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSupportConversationTools implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918013000_support_conversation_tools');
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
            'CREATE TABLE IF NOT EXISTS forwext_support_canned_responses ('
            . 'response_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'title VARCHAR(120) NOT NULL,'
            . 'body TEXT NOT NULL,'
            . 'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (response_key),'
            . 'KEY idx_forwext_support_canned_active (active,sort_order,response_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'INSERT IGNORE INTO forwext_support_canned_responses '
            . '(response_key,title,body,active,sort_order,created_at_utc,updated_at_utc) '
            . "VALUES ('more_info','Ek bilgi iste','Talebinizi inceleyebilmemiz için lütfen eksik ayrıntıları paylaşın.',"
            . '1,10,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_ticket_messages ('
            . 'message_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'author_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'author_role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'visibility VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'body TEXT NOT NULL,'
            . 'canned_response_key_snapshot VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'canned_response_title_snapshot VARCHAR(120) NULL,'
            . 'copied_from_message_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (message_id),'
            . 'KEY idx_forwext_support_message_ticket (ticket_id,created_at_utc,message_id),'
            . 'KEY idx_forwext_support_message_author (author_user_id,created_at_utc),'
            . 'KEY idx_forwext_support_message_copy (copied_from_message_id),'
            . 'CONSTRAINT fk_forwext_support_message_ticket FOREIGN KEY (ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_support_message_author FOREIGN KEY (author_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_support_message_copy FOREIGN KEY (copied_from_message_id) '
            . 'REFERENCES forwext_support_ticket_messages (message_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_ticket_history ('
            . 'history_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'event_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'visibility VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'payload_json TEXT NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (history_id),'
            . 'KEY idx_forwext_support_history_ticket (ticket_id,created_at_utc,history_id),'
            . 'KEY idx_forwext_support_history_actor (actor_user_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_support_history_ticket FOREIGN KEY (ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_support_history_actor FOREIGN KEY (actor_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_ticket_escalations ('
            . 'ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'escalation_level TINYINT UNSIGNED NOT NULL,'
            . 'escalated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'escalated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (ticket_id),'
            . 'KEY idx_forwext_support_escalation_level (escalation_level,escalated_at_utc,ticket_id),'
            . 'CONSTRAINT fk_forwext_support_escalation_ticket FOREIGN KEY (ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_support_escalation_actor FOREIGN KEY (escalated_by_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_ticket_relations ('
            . 'relation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'relation_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'source_ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'target_ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (relation_id),'
            . 'UNIQUE KEY uq_forwext_support_relation (relation_type,source_ticket_id,target_ticket_id),'
            . 'KEY idx_forwext_support_relation_target (target_ticket_id,relation_type,created_at_utc),'
            . 'CONSTRAINT fk_forwext_support_relation_source FOREIGN KEY (source_ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_support_relation_target FOREIGN KEY (target_ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_support_relation_actor FOREIGN KEY (created_by_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $permissions = [
            'support.ticket.reply_all' => 'Reply publicly to support tickets from all users.',
            'support.ticket.internal_note' => 'Add staff-only internal notes to support tickets.',
            'support.ticket.assign' => 'Assign or unassign support tickets.',
            'support.ticket.escalate' => 'Escalate active support tickets.',
            'support.ticket.merge' => 'Merge eligible support tickets from the same requester.',
            'support.ticket.split' => 'Split public support messages into new tickets.',
            'support.canned_response.manage' => 'Manage reusable support canned responses.',
        ];
        foreach ($permissions as $permissionKey => $description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:permission_key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$permissionKey,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            foreach (array_keys($permissions) as $permissionKey) {
                $effect = in_array($templateKey, ['moderator','administrator'], true) ? 'allow' : 'deny';
                $context->execute(new CompiledQuery(
                    'INSERT IGNORE INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template_key,:permission_key,:effect,NULL)',
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
            . "AND TABLE_NAME IN ('forwext_support_canned_responses','forwext_support_ticket_messages',"
            . "'forwext_support_ticket_history','forwext_support_ticket_escalations',"
            . "'forwext_support_ticket_relations')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_support_message_ticket','fk_forwext_support_message_author','fk_forwext_support_message_copy',"
            . "'fk_forwext_support_history_ticket','fk_forwext_support_history_actor',"
            . "'fk_forwext_support_escalation_ticket','fk_forwext_support_escalation_actor',"
            . "'fk_forwext_support_relation_source','fk_forwext_support_relation_target','fk_forwext_support_relation_actor')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN ("
            . "'support.ticket.reply_all','support.ticket.internal_note','support.ticket.assign',"
            . "'support.ticket.escalate','support.ticket.merge','support.ticket.split',"
            . "'support.canned_response.manage') AND value_type='flag'",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('support.ticket.reply_all','support.ticket.internal_note','support.ticket.assign',"
            . "'support.ticket.escalate','support.ticket.merge','support.ticket.split','support.canned_response.manage')",
        ));
        $starter = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_support_canned_responses WHERE response_key='more_info'",
        ));
        $relationIndex = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_support_ticket_relations' "
            . "AND INDEX_NAME='uq_forwext_support_relation' AND NON_UNIQUE=0",
        ));

        return (int) $tables === 5
            && (int) $foreignKeys === 10
            && (int) $permissions === 7
            && (int) $templateRules === 35
            && (int) $starter === 1
            && (int) $relationIndex === 3
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Support conversation/tools schema, permissions or indexes are incomplete.');
    }
}
