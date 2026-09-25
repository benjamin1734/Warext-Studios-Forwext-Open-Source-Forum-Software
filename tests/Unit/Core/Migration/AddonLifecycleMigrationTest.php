<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationScope;
use Forwext\Database\Migrations\Core\CreateAddonLifecycle;
use Forwext\Database\Migrations\Core\CreateSystemOperationsAcp;
use PHPUnit\Framework\TestCase;

final class AddonLifecycleMigrationTest extends TestCase
{
    public function testAddonLifecycleMigrationIsRegisteredAfterSystemOperationsAcp(): void
    {
        $classes = array_map(static fn ($migration): string => $migration::class, CoreMigrationRegistry::all());

        $operations = array_search(CreateSystemOperationsAcp::class, $classes, true);
        $addons = array_search(CreateAddonLifecycle::class, $classes, true);

        self::assertIsInt($operations);
        self::assertIsInt($addons);
        self::assertGreaterThan($operations, $addons);
    }

    public function testMigrationContainsRegistryRelationsAndDedicatedPermission(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents($root . '/database/migrations/core/CreateAddonLifecycle.php');

        self::assertStringContainsString('forwext_addons', $source);
        self::assertStringContainsString('forwext_addon_relations', $source);
        self::assertStringContainsString("'addon.manage'", $source);
        self::assertStringContainsString("$templateKey === 'administrator' ? 'allow' : 'deny'", $source);
    }

    public function testCanonicalVendorAddonIdCanOwnSharedMigrationEngineEntries(): void
    {
        $owner = MigrationOwner::addon('Acme/Demo');

        self::assertSame(MigrationScope::Addon, $owner->scope);
        self::assertSame('addon:Acme/Demo', $owner->key());
    }
}
