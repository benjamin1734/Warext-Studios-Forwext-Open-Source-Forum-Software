<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreatePermissionEngineTables;
use Forwext\Database\Migrations\Core\CreatePermissionTemplateTables;
use Forwext\Database\Migrations\Core\CreateRoleAppearanceTable;
use Forwext\Database\Migrations\Core\CreateRoleGroupTables;
use PHPUnit\Framework\TestCase;

final class CoreMigrationRegistryTest extends TestCase
{
    public function testInstallerMigrationIdsAreValidUniqueAndChronological(): void
    {
        $ids = [];

        foreach (CoreMigrationRegistry::all() as $migration) {
            $ids[] = $migration->id()->value();
        }

        self::assertNotEmpty($ids);
        self::assertCount(count($ids), array_unique($ids));

        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids, 'Core migrations must be registered in chronological id order.');
    }

    public function testInstallerIncludesCurrentRoleAndPermissionMigrations(): void
    {
        $classes = array_map(
            static fn (object $migration): string => $migration::class,
            CoreMigrationRegistry::all(),
        );

        self::assertContains(CreateRoleGroupTables::class, $classes);
        self::assertContains(CreatePermissionEngineTables::class, $classes);
        self::assertContains(CreatePermissionTemplateTables::class, $classes);
        self::assertContains(CreateRoleAppearanceTable::class, $classes);
    }
}
