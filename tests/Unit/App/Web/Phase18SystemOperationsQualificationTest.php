<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase18SystemOperationsQualificationTest extends TestCase
{
    public function testSystemOperationsUsesDensePermissionAwareWorkspace(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Admin/SystemOperationsHtml.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/SystemOperationsHandler.php');
        $service = (string) file_get_contents($root . '/core/Admin/Operations/SystemOperationsService.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        self::assertStringContainsString('ops-section-tabs', $html);
        self::assertStringContainsString('ops-overview', $html);
        self::assertStringContainsString("self::overviewStat('Scheduled tasks'", $html);
        self::assertStringContainsString("self::overviewStat('Failed jobs'", $html);
        self::assertStringContainsString("self::overviewStat('Backups'", $html);
        self::assertStringContainsString("['all','health','maintenance','updates','jobs','backups','logs','repairs']", $handler);
        self::assertStringContainsString("SystemOperationsService::JOB_PERMISSION", $html);
        self::assertStringContainsString("SystemOperationsService::BACKUP_PERMISSION", $html);
        self::assertStringContainsString("SystemOperationsService::LOG_PERMISSION", $html);
        self::assertStringContainsString("SystemOperationsService::REPAIR_PERMISSION", $html);
        self::assertStringContainsString('system.failed_job.retry', $service);
        self::assertStringContainsString('system.scheduler.prune', $service);
        self::assertStringContainsString('acp-system-operations-density-v1', $css);
    }

    public function testLiveBrowserCoversEverySystemOperationsSection(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        foreach ([
            '/admin/system/operations?section=health',
            '/admin/system/operations?section=maintenance',
            '/admin/system/operations?section=updates',
            '/admin/system/operations?section=jobs',
            '/admin/system/operations?section=backups',
            '/admin/system/operations?section=logs&logs=50',
            '/admin/system/operations?section=repairs',
        ] as $route) {
            self::assertStringContainsString($route, $live);
        }

        self::assertStringContainsString('admin system operations: dense workspace contract failed', $live);
        self::assertStringContainsString('admin system operations ${label} mobile: responsive contract failed', $live);
    }
}
