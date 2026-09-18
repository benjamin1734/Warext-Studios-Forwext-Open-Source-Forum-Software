<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateBugReportConversation implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918024000_bug_report_conversation');
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
            'CREATE TABLE IF NOT EXISTS forwext_bug_report_messages ('
            . 'message_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'report_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'author_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'author_role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'body TEXT NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (message_id),'
            . 'KEY idx_forwext_bug_message_report (report_id,created_at_utc,message_id),'
            . 'KEY idx_forwext_bug_message_author (author_user_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_bug_message_report FOREIGN KEY (report_id) '
            . 'REFERENCES forwext_bug_reports (report_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_bug_message_author FOREIGN KEY (author_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $permissions=[
            'bug.report.reply_own'=>'Add follow-up information to own bug reports.',
            'bug.report.reply_all'=>'Reply to bug reports from all users.',
        ];
        foreach($permissions as $permissionKey=>$description){
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:permission_key,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission_key'=>$permissionKey,'description'=>$description],
            ));
        }

        foreach(['new_user','member','verified','moderator','administrator'] as $templateKey){
            foreach(array_keys($permissions) as $permissionKey){
                $effect=$permissionKey==='bug.report.reply_own'
                    || in_array($templateKey,['moderator','administrator'],true)
                    ? 'allow'
                    : 'deny';
                $context->execute(new CompiledQuery(
                    'INSERT IGNORE INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template_key,:permission_key,:effect,NULL)',
                    ['template_key'=>$templateKey,'permission_key'=>$permissionKey,'effect'=>$effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table=$context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_bug_report_messages'",
        ));
        $foreignKeys=$context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN '
            . "('fk_forwext_bug_message_report','fk_forwext_bug_message_author')",
        ));
        $permissions=$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN "
            . "('bug.report.reply_own','bug.report.reply_all') AND value_type='flag'",
        ));
        $templateRules=$context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('bug.report.reply_own','bug.report.reply_all')",
        ));
        $indexes=$context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_bug_report_messages' "
            . "AND INDEX_NAME IN ('idx_forwext_bug_message_report','idx_forwext_bug_message_author')",
        ));

        return (int)$table===1
            && (int)$foreignKeys===2
            && (int)$permissions===2
            && (int)$templateRules===10
            && (int)$indexes===2
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Bug report conversation schema, permissions or indexes are incomplete.');
    }
}
