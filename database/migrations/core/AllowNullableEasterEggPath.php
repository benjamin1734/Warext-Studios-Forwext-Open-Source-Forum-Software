<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class AllowNullableEasterEggPath implements Migration
{
    public function id(): MigrationId
    {
        return MigrationId::fromString('20260919160500_easter_egg_path_nullable');
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
        $nullable = (string) $context->fetchValue(new CompiledQuery(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_easter_eggs' "
            . "AND COLUMN_NAME='path_pattern' LIMIT 1",
        ));
        if ($nullable !== 'YES') {
            $context->execute(new CompiledQuery(
                'ALTER TABLE forwext_easter_eggs MODIFY path_pattern VARCHAR(255) NULL',
            ));
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $nullable = (string) $context->fetchValue(new CompiledQuery(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='forwext_easter_eggs' "
            . "AND COLUMN_NAME='path_pattern' LIMIT 1",
        ));

        return $nullable === 'YES'
            ? MigrationVerification::passed()
            : MigrationVerification::failed('Easter egg path pattern must allow route-only definitions.');
    }
}
