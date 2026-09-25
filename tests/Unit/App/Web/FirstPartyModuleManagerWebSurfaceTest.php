<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class FirstPartyModuleManagerWebSurfaceTest extends TestCase
{
    public function testModuleManagerSurfaceIsCsrfProtectedAndWiredIntoRuntimeGates(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminModuleManagerHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminModuleManagerHtml.php');

        self::assertStringContainsString("'admin.modules'", $factory);
        self::assertStringContainsString("new PathTemplate('/admin/modules')", $factory);
        self::assertStringContainsString('$moduleManagerCsrf', $factory);
        self::assertStringContainsString('FirstPartyModuleRouteMiddleware', $factory);
        self::assertStringContainsString('FirstPartyModuleConditionalMiddleware', $factory);
        self::assertStringContainsString('$moduleEasterEggMiddleware', $factory);
        self::assertStringContainsString('$moduleAdvertisingMiddleware', $factory);
        self::assertStringContainsString('$moduleAnalyticsMiddleware', $factory);

        self::assertStringContainsString('HttpAuditRequestId::fromRequest', $handler);
        self::assertStringContainsString('hash_equals($moduleKey, $confirmation)', $handler);
        self::assertStringContainsString("case 'uninstall_delete':", $handler);
        self::assertStringContainsString("case 'reset_setting':", $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);
        self::assertStringContainsString('private, no-store', $handler);

        self::assertStringContainsString('Dependency / Conflict Graph', $html);
        self::assertStringContainsString('Uninstall + veriyi sil', $html);
        self::assertStringContainsString('Override’ı kaldır', $html);
        self::assertStringContainsString('post → thread → forum → group → global', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testLifecycleServiceUsesDedicatedPermissionAndProtectsDeleteDataDependencies(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents(
            $root . '/core/Module/FirstParty/FirstPartyModuleService.php',
        );

        self::assertStringContainsString("private const ACP_PERMISSION = 'acp.access';", $service);
        self::assertStringContainsString("private const MODULE_PERMISSION = 'module.manage';", $service);
        self::assertStringContainsString('Dependent module data must be purged before deleting parent module data', $service);
        self::assertStringContainsString('FirstPartyModuleDataState::Retained', $service);
        self::assertStringContainsString("'pending_storage'=>\$pending", $service);
        self::assertStringContainsString('$this->database->transaction', $service);
        self::assertStringContainsString('$this->audit->append', $service);
    }
}
