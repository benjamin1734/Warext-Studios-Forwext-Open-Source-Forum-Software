<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreateSystemIntegrationAcp;
use Forwext\Database\Migrations\Core\CreateSystemOperationsAcp;
use PHPUnit\Framework\TestCase;

final class SystemOperationsAcpMigrationTest extends TestCase
{
    public function testMigrationIsRegisteredAfterSystemIntegrationAcp(): void
    {
        $classes = array_map(static fn ($migration): string => $migration::class, CoreMigrationRegistry::all());
        $integration = array_search(CreateSystemIntegrationAcp::class, $classes, true);
        $operations = array_search(CreateSystemOperationsAcp::class, $classes, true);

        self::assertIsInt($integration);
        self::assertIsInt($operations);
        self::assertGreaterThan($integration, $operations);
    }

    public function testMigrationSeedsGranularAdminOnlyOperationsPermissions(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents($root . '/database/migrations/core/CreateSystemOperationsAcp.php');

        foreach ([
            'system.health.view',
            'system.logs.view',
            'system.jobs.manage',
            'system.backup.manage',
            'system.maintenance.manage',
            'system.repair.manage',
        ] as $permission) {
            self::assertStringContainsString($permission, $source);
        }
        self::assertStringContainsString("\$templateKey === 'administrator' ? 'allow' : 'deny'", $source);
    }
}
