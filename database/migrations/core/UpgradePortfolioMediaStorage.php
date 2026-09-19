<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class UpgradePortfolioMediaStorage implements Migration
{
    /** @var array<string,string> */
    private const COLUMNS = [
        'storage_path' => "VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER path",
        'media_type' => "VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER storage_path",
        'extension' => "VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER media_type",
        'size_bytes' => "BIGINT UNSIGNED NULL AFTER extension",
        'sha256_hex' => "CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER size_bytes",
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919133000_portfolio_media_storage');
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
        foreach (self::COLUMNS as $column => $definition) {
            $exists = (int) $context->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'forwext_portfolio_media\' '
                . 'AND COLUMN_NAME=:column_name',
                ['column_name' => $column],
            ));
            if ($exists === 0) {
                $context->execute(new CompiledQuery(
                    'ALTER TABLE forwext_portfolio_media ADD COLUMN ' . $column . ' ' . $definition,
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $columns = (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'forwext_portfolio_media\' '
            . "AND COLUMN_NAME IN ('storage_path','media_type','extension','size_bytes','sha256_hex')",
        ));

        return $columns === count(self::COLUMNS)
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Portfolio media storage columns are incomplete.');
    }
}
