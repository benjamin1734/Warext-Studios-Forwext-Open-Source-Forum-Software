<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreateForumMetadataTables;
use Forwext\Database\Migrations\Core\CreateForumNodeTables;
use Forwext\Database\Migrations\Core\CreatePermissionEngineTables;
use Forwext\Database\Migrations\Core\CreatePermissionTemplateTables;
use Forwext\Database\Migrations\Core\CreatePollTables;
use Forwext\Database\Migrations\Core\CreatePostDomainTables;
use Forwext\Database\Migrations\Core\CreateRoleAppearanceTable;
use Forwext\Database\Migrations\Core\CreateRoleGroupTables;
use Forwext\Database\Migrations\Core\CreateThreadDomainTables;
use Forwext\Database\Migrations\Core\RegisterFirstPartyPermissionNamespaces;
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

    public function testInstallerIncludesCurrentRolePermissionAndForumMigrations(): void
    {
        $classes = array_map(
            static fn (object $migration): string => $migration::class,
            CoreMigrationRegistry::all(),
        );

        self::assertContains(CreateRoleGroupTables::class, $classes);
        self::assertContains(CreatePermissionEngineTables::class, $classes);
        self::assertContains(CreatePermissionTemplateTables::class, $classes);
        self::assertContains(CreateRoleAppearanceTable::class, $classes);
        self::assertContains(RegisterFirstPartyPermissionNamespaces::class, $classes);
        self::assertContains(CreateForumNodeTables::class, $classes);
        self::assertContains(CreateThreadDomainTables::class, $classes);
        self::assertContains(CreatePostDomainTables::class, $classes);
        self::assertContains(CreateForumMetadataTables::class, $classes);
        self::assertContains(CreatePollTables::class, $classes);
    }
}
