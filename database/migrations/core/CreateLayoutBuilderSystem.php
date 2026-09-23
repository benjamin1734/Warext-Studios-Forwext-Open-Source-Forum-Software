<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateLayoutBuilderSystem implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260923214100_layout_builder_system');
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
            'CREATE TABLE IF NOT EXISTS forwext_ui_layouts ('
            . 'layout_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'layout_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'draft_revision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'published_revision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,'
            . 'created_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'updated_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_at_utc DATETIME(6) NOT NULL,updated_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(layout_id),UNIQUE KEY uq_forwext_ui_layout_key(layout_key),'
            . 'KEY idx_forwext_ui_layout_updated(updated_at_utc,layout_id),'
            . 'CONSTRAINT fk_forwext_ui_layout_created_by FOREIGN KEY(created_by_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT,'
            . 'CONSTRAINT fk_forwext_ui_layout_updated_by FOREIGN KEY(updated_by_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));

        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS forwext_ui_layout_revisions ('
            . 'revision_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'layout_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . "source ENUM('editor','import') NOT NULL,"
            . 'document_json JSON NOT NULL,checksum_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
            . 'created_by_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at_utc DATETIME(6) NOT NULL,'
            . 'PRIMARY KEY(revision_id),KEY idx_forwext_ui_layout_revision_layout(layout_id,created_at_utc,revision_id),'
            . 'CONSTRAINT fk_forwext_ui_layout_revision_layout FOREIGN KEY(layout_id) REFERENCES forwext_ui_layouts(layout_id) ON DELETE CASCADE,'
            . 'CONSTRAINT fk_forwext_ui_layout_revision_actor FOREIGN KEY(created_by_user_id) REFERENCES forwext_users(user_id) ON DELETE RESTRICT'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tables = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() "
            . "AND TABLE_NAME IN ('forwext_ui_layouts','forwext_ui_layout_revisions')",
        ));
        $indexes = (int) $context->fetchValue(new CompiledQuery(
            "SELECT COUNT(DISTINCT CONCAT(TABLE_NAME,':',INDEX_NAME)) FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND ("
            . "(TABLE_NAME='forwext_ui_layouts' AND INDEX_NAME IN ('uq_forwext_ui_layout_key','idx_forwext_ui_layout_updated')) OR "
            . "(TABLE_NAME='forwext_ui_layout_revisions' AND INDEX_NAME='idx_forwext_ui_layout_revision_layout'))",
        ));

        return $tables === 2 && $indexes === 3
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Layout builder schema is incomplete.');
    }
}
