<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateFaqSupportBridge implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918015000_faq_support_bridge');
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
            'CREATE TABLE IF NOT EXISTS forwext_faq_support_drafts ('
            . 'draft_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'source_message_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'suggested_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'suggested_category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'question VARCHAR(300) NOT NULL,'
            . 'answer TEXT NOT NULL,'
            . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (draft_id),'
            . 'UNIQUE KEY uq_forwext_faq_support_source_message (source_message_id),'
            . 'KEY idx_forwext_faq_support_status (status,created_at_utc,draft_id),'
            . 'KEY idx_forwext_faq_support_ticket (ticket_id,status,created_at_utc),'
            . 'CONSTRAINT fk_forwext_faq_support_ticket FOREIGN KEY (ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_faq_support_message FOREIGN KEY (source_message_id) '
            . 'REFERENCES forwext_support_ticket_messages (message_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_faq_support_suggester FOREIGN KEY (suggested_by_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_faq_support_category FOREIGN KEY (suggested_category_key) '
            . 'REFERENCES forwext_faq_categories (category_key) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT INTO forwext_permissions '
            . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
            . "VALUES ('support.faq_draft.suggest','flag','Suggest FAQ drafts from public staff ticket replies.',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
            . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),updated_at_utc=VALUES(updated_at_utc)',
        ));

        foreach (['new_user','member','verified','moderator','administrator'] as $templateKey) {
            $effect=in_array($templateKey,['moderator','administrator'],true)?'allow':'deny';
            $context->execute(new CompiledQuery(
                'INSERT IGNORE INTO forwext_permission_template_rules '
                . '(template_key,permission_key,effect,numeric_limit) '
                . "VALUES (:template_key,'support.faq_draft.suggest',:effect,NULL)",
                ['template_key'=>$templateKey,'effect'=>$effect],
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table=$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_faq_support_drafts'",
        ));
        $foreignKeys=$context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() '
            . "AND CONSTRAINT_NAME IN ('fk_forwext_faq_support_ticket','fk_forwext_faq_support_message',"
            . "'fk_forwext_faq_support_suggester','fk_forwext_faq_support_category')",
        ));
        $permission=$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key='support.faq_draft.suggest' AND value_type='flag'",
        ));
        $templates=$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE permission_key='support.faq_draft.suggest' "
            . "AND template_key IN ('new_user','member','verified','moderator','administrator')",
        ));
        return (int)$table===1&&(int)$foreignKeys===4&&(int)$permission===1&&(int)$templates===5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('FAQ/support bridge schema or permission defaults are incomplete.');
    }
}
