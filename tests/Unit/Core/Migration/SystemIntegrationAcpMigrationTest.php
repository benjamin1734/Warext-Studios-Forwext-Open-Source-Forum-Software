<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreateFirstPartyModuleManager;
use Forwext\Database\Migrations\Core\CreateSystemIntegrationAcp;
use PHPUnit\Framework\TestCase;

final class SystemIntegrationAcpMigrationTest extends TestCase
{
    public function testIntegrationAcpMigrationIsRegisteredAfterModuleManager(): void
    {
        $classes = array_map(static fn ($migration): string => $migration::class, CoreMigrationRegistry::all());

        $modules = array_search(CreateFirstPartyModuleManager::class, $classes, true);
        $integration = array_search(CreateSystemIntegrationAcp::class, $classes, true);

        self::assertIsInt($modules);
        self::assertIsInt($integration);
        self::assertGreaterThan($modules, $integration);
    }

    public function testMigrationGrantsIntegrationManageOnlyToAdministratorTemplate(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents(
            $root . '/database/migrations/core/CreateSystemIntegrationAcp.php',
        );

        self::assertStringContainsString("'integration.manage'", $source);
        self::assertStringContainsString("\$templateKey === 'administrator' ? 'allow' : 'deny'", $source);
        self::assertStringContainsString("COUNT(*) FROM forwext_permission_template_rules", $source);
    }
}
