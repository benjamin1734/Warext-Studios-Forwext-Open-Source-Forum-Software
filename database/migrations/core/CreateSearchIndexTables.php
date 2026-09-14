<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateSearchIndexTables implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260914223000_search_index');
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
            'CREATE TABLE IF NOT EXISTS `forwext_search_documents` ('
            . '`document_key` CHAR(64) NOT NULL, '
            . '`document_type` VARCHAR(191) NOT NULL, '
            . '`document_id` VARCHAR(191) NOT NULL, '
            . '`title` VARCHAR(1000) NOT NULL, '
            . '`body` LONGTEXT NOT NULL, '
            . '`locale` VARCHAR(64) NULL, '
            . '`updated_at_utc` DATETIME(6) NOT NULL, '
            . 'PRIMARY KEY (`document_key`), '
            . 'UNIQUE KEY `uniq_forwext_search_identity` (`document_type`, `document_id`), '
            . 'FULLTEXT KEY `ft_forwext_search_title_body` (`title`, `body`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
        $context->execute(new CompiledQuery(
            'CREATE TABLE IF NOT EXISTS `forwext_search_document_scopes` ('
            . '`document_key` CHAR(64) NOT NULL, '
            . '`scope_token` VARCHAR(191) NOT NULL, '
            . 'PRIMARY KEY (`document_key`, `scope_token`), '
            . 'KEY `idx_forwext_search_scope` (`scope_token`, `document_key`), '
            . 'CONSTRAINT `fk_forwext_search_scope_document` FOREIGN KEY (`document_key`) '
            . 'REFERENCES `forwext_search_documents` (`document_key`) ON DELETE CASCADE'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ));
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $tableCount = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`TABLES` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` IN ('forwext_search_documents', 'forwext_search_document_scopes')",
        ));
        $fulltextCount = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `information_schema`.`STATISTICS` '
            . 'WHERE `TABLE_SCHEMA` = DATABASE() '
            . "AND `TABLE_NAME` = 'forwext_search_documents' "
            . "AND `INDEX_NAME` = 'ft_forwext_search_title_body' AND `INDEX_TYPE` = 'FULLTEXT'",
        ));

        return (int) $tableCount === 2 && (int) $fulltextCount >= 1
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Search tables or native FULLTEXT index are missing.');
    }
}
