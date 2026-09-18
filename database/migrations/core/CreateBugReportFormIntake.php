<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateBugReportFormIntake implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918023000_bug_report_form_intake');
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
            'CREATE TABLE IF NOT EXISTS forwext_bug_report_intake ('
            . 'report_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'reproduction_steps TEXT NOT NULL,'
            . 'expected_result TEXT NOT NULL,'
            . 'actual_result TEXT NOT NULL,'
            . 'reported_source_path VARCHAR(2048) NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (report_id),'
            . 'KEY idx_forwext_bug_intake_source (reported_source_path(191),created_at_utc),'
            . 'CONSTRAINT fk_forwext_bug_intake_report FOREIGN KEY (report_id) '
            . 'REFERENCES forwext_bug_reports (report_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_bug_report_attachments ('
            . 'attachment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'report_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'owner_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'filename VARCHAR(180) NOT NULL,'
            . 'media_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'extension VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'size_bytes BIGINT UNSIGNED NOT NULL,'
            . 'sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'storage_path VARCHAR(500) NOT NULL,'
            . 'image_width INT UNSIGNED NULL,'
            . 'image_height INT UNSIGNED NULL,'
            . 'metadata_stripped TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (attachment_id),'
            . 'KEY idx_forwext_bug_attachment_report (report_id,created_at_utc,attachment_id),'
            . 'KEY idx_forwext_bug_attachment_owner (owner_user_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_bug_attachment_report FOREIGN KEY (report_id) '
            . 'REFERENCES forwext_bug_reports (report_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_bug_attachment_owner FOREIGN KEY (owner_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_bug_report_intake','forwext_bug_report_attachments')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_bug_intake_report','fk_forwext_bug_attachment_report','fk_forwext_bug_attachment_owner')",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,':',INDEX_NAME)) FROM information_schema.STATISTICS "
            . 'WHERE TABLE_SCHEMA=DATABASE() AND ('
            . "(TABLE_NAME='forwext_bug_report_intake' AND INDEX_NAME='idx_forwext_bug_intake_source') OR "
            . "(TABLE_NAME='forwext_bug_report_attachments' AND INDEX_NAME IN "
            . "('idx_forwext_bug_attachment_report','idx_forwext_bug_attachment_owner')))",
        ));

        return (int) $tables === 2
            && (int) $foreignKeys === 3
            && (int) $indexes === 3
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Bug report form intake schema, relations or indexes are incomplete.');
    }
}
