<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreateAdminInformationArchitecture;
use Forwext\Database\Migrations\Core\CreateFirstPartyModuleManager;
use PHPUnit\Framework\TestCase;

final class FirstPartyModuleManagerMigrationTest extends TestCase
{
    public function testModuleManagerMigrationIsRegisteredAfterAcpInformationArchitecture(): void
    {
        $classes = array_map(static fn ($migration): string => $migration::class, CoreMigrationRegistry::all());

        $admin = array_search(CreateAdminInformationArchitecture::class, $classes, true);
        $modules = array_search(CreateFirstPartyModuleManager::class, $classes, true);

        self::assertIsInt($admin);
        self::assertIsInt($modules);
        self::assertGreaterThan($admin, $modules);
    }

    public function testMigrationContainsLifecycleScopedSettingsPurgeQueueAndDedicatedPermission(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents(
            $root . '/database/migrations/core/CreateFirstPartyModuleManager.php',
        );

        self::assertStringContainsString('forwext_first_party_modules', $source);
        self::assertStringContainsString('forwext_first_party_module_settings', $source);
        self::assertStringContainsString('forwext_first_party_module_purge_objects', $source);
        self::assertStringContainsString("'module.manage'", $source);
        self::assertStringContainsString("$templateKey === 'administrator' ? 'allow' : 'deny'", $source);
    }
}
