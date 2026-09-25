<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreateAddonBackendCapabilities;
use PHPUnit\Framework\TestCase;

final class AddonBackendCapabilitiesMigrationTest extends TestCase
{
    public function testMigrationIsRegisteredAfterAddonLifecycleAndCreatesTypedSettingTables(): void
    {
        $migrations = CoreMigrationRegistry::all();
        $classes = array_map(static fn ($migration): string => $migration::class, $migrations);

        $lifecycle = array_search(\Forwext\Database\Migrations\Core\CreateAddonLifecycle::class, $classes, true);
        $backend = array_search(CreateAddonBackendCapabilities::class, $classes, true);

        self::assertIsInt($lifecycle);
        self::assertIsInt($backend);
        self::assertGreaterThan($lifecycle, $backend);

        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/database/migrations/core/CreateAddonBackendCapabilities.php',
        );
        self::assertStringContainsString('forwext_addon_setting_definitions', $source);
        self::assertStringContainsString('forwext_addon_setting_values', $source);
        self::assertStringContainsString('ON DELETE CASCADE', $source);
    }
}
