<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class SystemOperationsWebSurfaceTest extends TestCase
{
    public function testOperationsRouteIsNativePermissionAwareAndCsrfProtected(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/SystemOperationsHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/SystemOperationsHtml.php');
        $service = (string) file_get_contents($root . '/core/Admin/Operations/SystemOperationsService.php');

        self::assertStringContainsString("'admin.system.operations'", $factory);
        self::assertStringContainsString("new PathTemplate('/admin/system/operations')", $factory);
        self::assertStringContainsString('$systemOperationsCsrf', $factory);
        self::assertStringContainsString('systemOperationsCsrfMiddleware', $factory);
        self::assertStringContainsString("'acp.access'", $service);
        self::assertStringContainsString('system.health.view', $service);
        self::assertStringContainsString('system.logs.view', $service);
        self::assertStringContainsString('system.jobs.manage', $service);
        self::assertStringContainsString('system.backup.manage', $service);
        self::assertStringContainsString('system.maintenance.manage', $service);
        self::assertStringContainsString('system.repair.manage', $service);
        self::assertStringContainsString('assertUpdateAllowed', $service);
        self::assertStringContainsString('self::BACKUP_PERMISSION', $service);
        self::assertStringContainsString('self::MAINTENANCE_PERMISSION', $service);
        self::assertStringContainsString('self::REPAIR_PERMISSION', $service);
        self::assertStringContainsString('X-Robots-Tag', $handler);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testFailedJobPayloadAndBackupDownloadAreNotExposedInHtml(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Admin/SystemOperationsHtml.php');
        $service = (string) file_get_contents($root . '/core/Admin/Operations/SystemOperationsService.php');
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertStringNotContainsString('$job->payload', $html);
        self::assertStringContainsString('failedJobMetadata', $service);
        $metadataStart = strpos($service, 'private function failedJobMetadata');
        $taskStart = strpos($service, 'private function scheduledTask', is_int($metadataStart) ? $metadataStart : 0);
        self::assertIsInt($metadataStart);
        self::assertIsInt($taskStart);
        $metadataSource = substr($service, $metadataStart, $taskStart - $metadataStart);
        self::assertStringNotContainsString('payload', $metadataSource);
        self::assertStringNotContainsString('/admin/system/backups/download', $factory);
        self::assertStringContainsString('Job payloadları ACP’ye taşınmaz', $html);
        self::assertStringContainsString('ACP download endpoint’i yoktur', $html);
    }

    public function testDestructiveActionsRequireExactTypedConfirmation(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Admin/SystemOperationsHandler.php');

        self::assertStringContainsString("!== 'MAINTENANCE'", $handler);
        self::assertStringContainsString("!== 'CLEAR CACHE'", $handler);
        self::assertStringContainsString("!== 'UPDATE'", $handler);
        self::assertStringContainsString("!== \$jobId", $handler);
        self::assertStringContainsString("!== \$name", $handler);
    }
}
