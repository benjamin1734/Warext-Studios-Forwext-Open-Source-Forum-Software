<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class SystemIntegrationAcpWebSurfaceTest extends TestCase
{
    public function testIntegrationRouteIsBackendProtectedAndCsrfWrapped(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/SystemIntegrationHandler.php');
        $service = (string) file_get_contents($root . '/core/Admin/Integration/SystemIntegrationService.php');

        self::assertStringContainsString("'admin.integrations'", $factory);
        self::assertStringContainsString("new PathTemplate('/admin/integrations')", $factory);
        self::assertStringContainsString('[HttpMethod::Get, HttpMethod::Post]', $factory);
        self::assertStringContainsString('$systemIntegrationCsrf', $factory);
        self::assertStringContainsString("'acp.access'", $service);
        self::assertStringContainsString("'integration.manage'", $service);
        self::assertStringContainsString('HttpAuditRequestId::fromRequest', $handler);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);
    }

    public function testSecretSurfaceMasksValuesAndRequiresTypedDeletionConfirmation(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Admin/SystemIntegrationHtml.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/SystemIntegrationHandler.php');
        $service = (string) file_get_contents($root . '/core/Admin/Integration/SystemIntegrationService.php');

        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('autocomplete="new-password"', $html);
        self::assertStringContainsString('••••••••', $html);
        self::assertStringNotContainsString('$definition->secretName', $html);
        self::assertStringContainsString('hash_equals($key, $confirm)', $handler);
        self::assertStringContainsString('#[SensitiveParameter]', $service);
        self::assertStringContainsString("'integration.secret'", $service);
        self::assertStringNotContainsString("'configured_value'=>\$value", $service);
    }

    public function testComplexAcpSurfaceIncludesSearchFiltersResetCapabilitiesAndEnvironmentWarning(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Admin/SystemIntegrationHtml.php');

        self::assertStringContainsString('Entegrasyon ayarlarında ara', $html);
        self::assertStringContainsString('Tüm bölümler', $html);
        self::assertStringContainsString('Generated override’ı kaldır', $html);
        self::assertStringContainsString('environment override', $html);
        self::assertStringContainsString('Runtime capabilities', $html);
        self::assertStringContainsString('Secret değerleri hiçbir zaman ekrana geri basılmaz', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testGeneratedConfigurationUsesCrossRequestLockAndAtomicRename(): void
    {
        $root = dirname(__DIR__, 4);
        $store = (string) file_get_contents($root . '/core/Admin/Integration/GeneratedConfigStore.php');

        self::assertStringContainsString('flock($handle, $operation)', $store);
        self::assertStringContainsString('LOCK_EX', $store);
        self::assertStringContainsString('@rename($temporary, $this->path)', $store);
        self::assertStringContainsString("'.lock'", $store);
    }
}
