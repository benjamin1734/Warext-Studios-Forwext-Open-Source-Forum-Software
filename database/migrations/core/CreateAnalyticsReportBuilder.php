<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateAnalyticsReportBuilder implements Migration
{
    private const PERMISSIONS = [
        'analytics.view_forum'=>'View forum and user activity analytics.',
        'analytics.view_content'=>'View content and engagement analytics.',
        'analytics.view_operations'=>'View moderation, support and bug operational analytics.',
        'analytics.view_commerce'=>'View Marketplace, revenue, referral and giveaway analytics.',
        'analytics.report.use'=>'Use the analytics report builder and saved reports.',
        'analytics.export'=>'Export authorized aggregate analytics reports.',
        'analytics.report.manage_all'=>'Manage saved analytics reports owned by other users.',
        'analytics.report.unaggregated'=>'Lower privacy aggregation thresholds for authorized analytics work.',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260923201500_analytics_report_builder');
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
            'CREATE TABLE IF NOT EXISTS forwext_analytics_saved_reports ('
            . 'report_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'name VARCHAR(120) NOT NULL,'
            . 'dataset VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'start_date DATE NOT NULL,end_date DATE NOT NULL,'
            . 'filters_json JSON NOT NULL,privacy_min_count SMALLINT UNSIGNED NOT NULL DEFAULT 5,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(report_id),'
            . 'KEY idx_forwext_analytics_saved_owner(owner_user_id,updated_at_utc,report_id),'
            . 'KEY idx_forwext_analytics_saved_dataset(dataset,updated_at_utc,report_id),'
            . 'CONSTRAINT fk_forwext_analytics_saved_owner FOREIGN KEY(owner_user_id) '
            . 'REFERENCES forwext_users(user_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        foreach (self::PERMISSIONS as $permission=>$description) {
            $context->execute(new CompiledQuery(
                'INSERT INTO forwext_permissions '
                . '(permission_key,value_type,description,created_at_utc,updated_at_utc) '
                . "VALUES (:permission,'flag',:description,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) "
                . 'ON DUPLICATE KEY UPDATE value_type=VALUES(value_type),description=VALUES(description),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                ['permission'=>$permission,'description'=>$description],
            ));
        }

        foreach (['new_user','member','verified','moderator','administrator'] as $template) {
            foreach (array_keys(self::PERMISSIONS) as $permission) {
                $effect = $template === 'administrator' ? 'allow' : 'deny';
                $context->execute(new CompiledQuery(
                    'INSERT INTO forwext_permission_template_rules '
                    . '(template_key,permission_key,effect,numeric_limit) '
                    . 'VALUES (:template,:permission,:effect,NULL) '
                    . 'ON DUPLICATE KEY UPDATE effect=VALUES(effect),numeric_limit=NULL',
                    ['template'=>$template,'permission'=>$permission,'effect'=>$effect],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME='forwext_analytics_saved_reports'",
        ));
        $indexes = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_analytics_saved_reports' "
            . "AND INDEX_NAME IN ('idx_forwext_analytics_saved_owner','idx_forwext_analytics_saved_dataset')",
        ));
        $rules = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key IN "
            . "('new_user','member','verified','moderator','administrator') AND permission_key IN ("
            . "'analytics.view_forum','analytics.view_content','analytics.view_operations','analytics.view_commerce',"
            . "'analytics.report.use','analytics.export','analytics.report.manage_all','analytics.report.unaggregated')",
        ));
        $adminAllows = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules WHERE template_key='administrator' "
            . "AND effect='allow' AND permission_key IN ("
            . "'analytics.view_forum','analytics.view_content','analytics.view_operations','analytics.view_commerce',"
            . "'analytics.report.use','analytics.export','analytics.report.manage_all','analytics.report.unaggregated')",
        ));
        $nonAdminAllows = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_permission_template_rules "
            . "WHERE template_key IN ('new_user','member','verified','moderator') AND effect='allow' "
            . "AND permission_key IN ("
            . "'analytics.view_forum','analytics.view_content','analytics.view_operations','analytics.view_commerce',"
            . "'analytics.report.use','analytics.export','analytics.report.manage_all','analytics.report.unaggregated')",
        ));

        return $table === 1 && $indexes === 2 && $rules === 40 && $adminAllows === 8 && $nonAdminAllows === 0
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Analytics report builder schema or permission defaults are incomplete.');
    }
}
