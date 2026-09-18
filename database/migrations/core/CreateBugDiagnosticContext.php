<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateBugDiagnosticContext implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918022000_bug_diagnostic_context');
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
            'CREATE TABLE IF NOT EXISTS forwext_bug_report_diagnostics ('
            . 'report_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'url_path VARCHAR(2048) NOT NULL,'
            . 'route_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'forum_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'thread_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'post_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'theme_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'module_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'browser_family VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'browser_major SMALLINT UNSIGNED NULL,'
            . 'os_family VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'device_class VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'user_agent_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'request_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'captured_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (report_id),'
            . 'KEY idx_forwext_bug_diag_route (route_name,captured_at_utc),'
            . 'KEY idx_forwext_bug_diag_module (module_key,captured_at_utc),'
            . 'KEY idx_forwext_bug_diag_entities (forum_id,thread_id,post_id),'
            . 'KEY idx_forwext_bug_diag_request (request_id),'
            . 'KEY idx_forwext_bug_diag_client (user_agent_fingerprint,captured_at_utc),'
            . 'CONSTRAINT fk_forwext_bug_diag_report FOREIGN KEY (report_id) '
            . 'REFERENCES forwext_bug_reports (report_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_bug_diag_actor FOREIGN KEY (actor_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $table = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME='forwext_bug_report_diagnostics'",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN '
            . "('fk_forwext_bug_diag_report','fk_forwext_bug_diag_actor')",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_bug_report_diagnostics' "
            . "AND INDEX_NAME IN ('idx_forwext_bug_diag_route','idx_forwext_bug_diag_module',"
            . "'idx_forwext_bug_diag_entities','idx_forwext_bug_diag_request','idx_forwext_bug_diag_client')",
        ));

        return (int) $table === 1
            && (int) $foreignKeys === 2
            && (int) $indexes === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Bug diagnostic context schema or indexes are incomplete.');
    }
}
