<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSupportTicketIntake implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260918012000_support_ticket_intake');
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
            'CREATE TABLE IF NOT EXISTS forwext_support_category_fields ('
            . 'category_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'field_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'label VARCHAR(120) NOT NULL,'
            . 'field_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'required TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,'
            . "choices_json TEXT NOT NULL,"
            . 'max_length SMALLINT UNSIGNED NOT NULL DEFAULT 255,'
            . "help_text VARCHAR(500) NOT NULL DEFAULT '',"
            . 'sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,'
            . 'active TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (category_key,field_key),'
            . 'KEY idx_forwext_support_field_active (category_key,active,sort_order,field_key),'
            . 'CONSTRAINT fk_forwext_support_field_category FOREIGN KEY (category_key) '
            . 'REFERENCES forwext_support_categories (category_key) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_ticket_intake ('
            . 'ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'description TEXT NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (ticket_id),'
            . 'CONSTRAINT fk_forwext_support_intake_ticket FOREIGN KEY (ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_ticket_field_values ('
            . 'ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'field_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'field_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'value_json TEXT NOT NULL,'
            . 'PRIMARY KEY (ticket_id,field_key),'
            . 'CONSTRAINT fk_forwext_support_field_value_ticket FOREIGN KEY (ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_ticket_context_links ('
            . 'ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'context_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'target_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'label_snapshot VARCHAR(255) NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY (ticket_id),'
            . 'KEY idx_forwext_support_context_target (context_type,target_id,ticket_id),'
            . 'CONSTRAINT fk_forwext_support_context_ticket FOREIGN KEY (ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_ticket_attachments ('
            . 'attachment_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
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
            . 'KEY idx_forwext_support_attachment_ticket (ticket_id,created_at_utc,attachment_id),'
            . 'KEY idx_forwext_support_attachment_owner (owner_user_id,created_at_utc),'
            . 'CONSTRAINT fk_forwext_support_attachment_ticket FOREIGN KEY (ticket_id) '
            . 'REFERENCES forwext_support_tickets (ticket_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_support_attachment_owner FOREIGN KEY (owner_user_id) '
            . 'REFERENCES forwext_users (user_id) ON DELETE SET NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_support_submission_rate_limits ('
            . 'scope_name VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'bucket_start_utc DATETIME(6) NOT NULL,'
            . 'attempts INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'PRIMARY KEY (scope_name,fingerprint,bucket_start_utc),'
            . 'KEY idx_forwext_support_rate_bucket (bucket_start_utc,scope_name)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            . "AND TABLE_NAME IN ('forwext_support_category_fields','forwext_support_ticket_intake',"
            . "'forwext_support_ticket_field_values','forwext_support_ticket_context_links',"
            . "'forwext_support_ticket_attachments','forwext_support_submission_rate_limits')",
        ));
        $foreignKeys = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS '
            . 'WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME IN ('
            . "'fk_forwext_support_field_category','fk_forwext_support_intake_ticket',"
            . "'fk_forwext_support_field_value_ticket','fk_forwext_support_context_ticket',"
            . "'fk_forwext_support_attachment_ticket','fk_forwext_support_attachment_owner')",
        ));
        $indexes = $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,':',INDEX_NAME)) FROM information_schema.STATISTICS "
            . 'WHERE TABLE_SCHEMA=DATABASE() AND ('
            . "(TABLE_NAME='forwext_support_category_fields' AND INDEX_NAME='idx_forwext_support_field_active') OR "
            . "(TABLE_NAME='forwext_support_ticket_context_links' AND INDEX_NAME='idx_forwext_support_context_target') OR "
            . "(TABLE_NAME='forwext_support_ticket_attachments' AND INDEX_NAME IN "
            . "('idx_forwext_support_attachment_ticket','idx_forwext_support_attachment_owner')) OR "
            . "(TABLE_NAME='forwext_support_submission_rate_limits' AND INDEX_NAME='idx_forwext_support_rate_bucket'))",
        ));

        return (int) $tables === 6
            && (int) $foreignKeys === 6
            && (int) $indexes === 5
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Support intake schema, relations or indexes are incomplete.');
    }
}
