<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateBugReportWorkflow implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918021000_bug_report_workflow');
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
            'CREATE TABLE IF NOT EXISTS forwext_bug_report_categories ('
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'label VARCHAR(100) NOT NULL,'
            . "description VARCHAR(255) NOT NULL DEFAULT '',"
            . 'default_severity VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (category_key),'
            . 'KEY idx_forwext_bug_category_active (active,sort_order,category_key)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach ([
            ['general','Genel','Genel hata bildirimleri.','medium',10],
            ['frontend','Arayüz','Arayüz, tema ve istemci tarafı hataları.','medium',20],
            ['backend','Backend','Sunucu tarafı ve iş mantığı hataları.','high',30],
            ['performance','Performans','Yavaşlık, yüksek kaynak kullanımı ve performans sorunları.','medium',40],
            ['security','Güvenlik','Güvenlik etkisi olabilecek hata bildirimleri.','high',50],
        ] as [$key,$label,$description,$severity,$sortOrder]) {
            $context->execute(new CompiledQuery(
                'INSERT IGNORE INTO forwext_bug_report_categories '
                . '(category_key,label,description,default_severity,sort_order,active,created_at_utc,updated_at_utc) '
                . 'VALUES (:category_key,:label,:description,:default_severity,:sort_order,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',
                [
                    'category_key'=>$key,
                    'label'=>$label,
                    'description'=>$description,
                    'default_severity'=>$severity,
                    'sort_order'=>$sortOrder,
                ],
            ));
        }

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_bug_reports ('
            . 'report_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'reporter_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'assigned_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'title VARCHAR(200) NOT NULL,'
            . 'summary TEXT NOT NULL,'
            . 'severity VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'finalized_at_utc DATETIME(6) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'version BIGINT UNSIGNED NOT NULL DEFAULT 1,'
            . 'PRIMARY KEY (report_id),'
            . 'KEY idx_forwext_bug_reporter (reporter_user_id,updated_at_utc,report_id),'
            . 'KEY idx_forwext_bug_queue (status,severity,created_at_utc,report_id),'
            . 'KEY idx_forwext_bug_category (category_key,status,created_at_utc),'
            . 'KEY idx_forwext_bug_assignee (assigned_user_id,status,updated_at_utc,report_id),'
            . 'CONSTRAINT fk_forwext_bug_category FOREIGN KEY (category_key) '
            . 'REFERENCES forwext_bug_report_categories (category_key) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_bug_reporter FOREIGN KEY (reporter_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL,'
            . 'CONSTRAINT fk_forwext_bug_assignee FOREIGN KEY (assigned_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_bug_report_history ('
            . 'history_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'report_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'event_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'visibility VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'payload_json TEXT NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (history_id),'
            . 'KEY idx_forwext_bug_history_report (report_id,created_at_utc,history_id),'
            . 'KEY idx_forwext_bug_history_actor (actor_user_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_bug_history_report FOREIGN KEY (report_id) '
            . 'REFERENCES forwext_bug_reports (report_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_bug_history_actor FOREIGN KEY (actor_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $permissions = [
            'bug.report.create' => 'Create bug reports.',
            'bug.report.view_own' => 'View own bug reports.',
            'bug.report.view_all' => 'View bug reports from all users.',
            'bug.report.manage' => 'Manage bug report workflow, severity and categories.',
            'bug.report.assign' => 'Assign or unassign bug reports.',
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
                    'bug.report.create',
                    'bug.report.view_own' => 'allow',
                    default => $staff ? 'allow' : 'deny',
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
            . "AND TABLE_NAME IN ('forwext_bug_report_categories','forwext_bug_reports','forwext_bug_report_history')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_bug_category','fk_forwext_bug_reporter','fk_forwext_bug_assignee',"
            . "'fk_forwext_bug_history_report','fk_forwext_bug_history_actor')",
        ));
        $categories = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_bug_report_categories WHERE category_key IN "
            . "('general','frontend','backend','performance','security')",
        ));
        $permissions = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permissions WHERE permission_key IN "
            . "('bug.report.create','bug.report.view_own','bug.report.view_all','bug.report.manage','bug.report.assign') "
            . "AND value_type='flag'",
        ));
        $templateRules = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_permission_template_rules '
            . "WHERE template_key IN ('new_user','member','verified','moderator','administrator') "
            . "AND permission_key IN ('bug.report.create','bug.report.view_own','bug.report.view_all',"
            . "'bug.report.manage','bug.report.assign')",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,':',INDEX_NAME)) FROM information_schema.STATISTICS "
            . 'WHERE TABLE_SCHEMA=DATABASE() AND ('
            . "(TABLE_NAME='forwext_bug_report_categories' AND INDEX_NAME='idx_forwext_bug_category_active') OR "
            . "(TABLE_NAME='forwext_bug_reports' AND INDEX_NAME IN "
            . "('idx_forwext_bug_reporter','idx_forwext_bug_queue','idx_forwext_bug_category','idx_forwext_bug_assignee')) OR "
            . "(TABLE_NAME='forwext_bug_report_history' AND INDEX_NAME IN "
            . "('idx_forwext_bug_history_report','idx_forwext_bug_history_actor')))",
        ));

        return (int) $tables === 3
            && (int) $foreignKeys === 5
            && (int) $categories === 5
            && (int) $permissions === 5
            && (int) $templateRules === 25
            && (int) $indexes === 7
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Bug report workflow schema, permissions or defaults are incomplete.');
    }
}
