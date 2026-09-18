<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSupportTicketDomain implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918011000_support_ticket_domain');
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
            'CREATE TABLE IF NOT EXISTS forwext_support_categories ('
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'label VARCHAR(100) NOT NULL,'
            . "description VARCHAR(255) NOT NULL DEFAULT '',"
            . 'default_priority VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'first_response_minutes INT UNSIGNED NULL,'
            . 'resolution_minutes INT UNSIGNED NULL,'
            . 'sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (category_key),'
            . 'KEY idx_forwext_support_category_active (active,sort_order,category_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'INSERT IGNORE INTO forwext_support_categories '
            . '(category_key,label,description,default_priority,first_response_minutes,resolution_minutes,'
            . 'sort_order,active,created_at_utc,updated_at_utc) '
            . "VALUES ('general','Genel Destek','Genel destek talepleri.','normal',1440,4320,10,1,"
            . 'UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_tickets ('
            . 'ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'requester_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'assigned_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'subject VARCHAR(200) NOT NULL,'
            . 'priority VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'first_response_due_at_utc DATETIME(6) NULL,'
            . 'resolution_due_at_utc DATETIME(6) NULL,'
            . 'first_responded_at_utc DATETIME(6) NULL,'
            . 'resolved_at_utc DATETIME(6) NULL,'
            . 'closed_at_utc DATETIME(6) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'version BIGINT UNSIGNED NOT NULL DEFAULT 1,'
            . 'PRIMARY KEY (ticket_id),'
            . 'KEY idx_forwext_support_requester (requester_user_id,updated_at_utc,ticket_id),'
            . 'KEY idx_forwext_support_queue (status,priority,resolution_due_at_utc,created_at_utc,ticket_id),'
            . 'KEY idx_forwext_support_assignee (assigned_user_id,status,updated_at_utc,ticket_id),'
            . 'KEY idx_forwext_support_first_response_sla (first_responded_at_utc,first_response_due_at_utc,status),'
            . 'CONSTRAINT fk_forwext_support_ticket_category FOREIGN KEY (category_key) '
            . 'REFERENCES forwext_support_categories (category_key) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_support_ticket_requester FOREIGN KEY (requester_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_support_ticket_assignee FOREIGN KEY (assigned_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $permissions = [
            'support.ticket.create' => 'Create support tickets.',
            'support.ticket.view_own' => 'View own support tickets.',
            'support.ticket.reply_own' => 'Reply to own support tickets.',
            'support.ticket.view_all' => 'View support tickets from all users.',
            'support.ticket.manage' => 'Manage support tickets.',
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
                $staff = in_array($templateKey, ['moderator','administrator'], true);
                $effect = match ($permissionKey) {
                    'support.ticket.create',
                    'support.ticket.view_own',
                    'support.ticket.reply_own' => 'allow',
                    'support.ticket.view_all',
                    'support.ticket.manage' => $staff ? 'allow' : 'deny',
                    default => 'deny',
                };
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
            . "AND TABLE_NAME IN ('forwext_support_categories','forwext_support_tickets')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_support_ticket_category','fk_forwext_support_ticket_requester',"
            . "'fk_forwext_support_ticket_assignee')",
        ));
        $category = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_support_categories WHERE category_key='general'",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN ("
            . "'support.ticket.create','support.ticket.view_own','support.ticket.reply_own',"
            . "'support.ticket.view_all','support.ticket.manage') AND value_type='flag'",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('support.ticket.create','support.ticket.view_own','support.ticket.reply_own',"
            . "'support.ticket.view_all','support.ticket.manage')",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS '
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_support_tickets' "
            . "AND INDEX_NAME IN ('idx_forwext_support_requester','idx_forwext_support_queue',"
            . "'idx_forwext_support_assignee','idx_forwext_support_first_response_sla')",
        ));

        return (int) $tables === 2
            && (int) $foreignKeys === 3
            && (int) $category === 1
            && (int) $permissions === 5
            && (int) $templateRules === 25
            && (int) $indexes === 4
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Support ticket domain schema, permissions or defaults are incomplete.');
    }
}
