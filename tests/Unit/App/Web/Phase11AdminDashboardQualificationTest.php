<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase11AdminDashboardQualificationTest extends TestCase
{
    public function testDenseDashboardShellIsCoveredByLiveBrowser(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminDashboardHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        foreach ([
            'acp-overview',
            'acp-section-index',
            'acp-directory-row',
            'acp-search-results',
            'acp-queue-strip',
        ] as $marker) {
            self::assertStringContainsString($marker, $html);
        }

        self::assertStringContainsString('acp-dashboard-density-v2', $css);
        foreach ([
            'acp-overview',
            'acp-section-index',
            'acp-directory-row',
            'acp-queue-strip',
        ] as $marker) {
            self::assertStringContainsString($marker, $css);
        }

        self::assertStringContainsString('/admin?q=user', $live);
        self::assertStringContainsString('admin: dense dashboard contract failed', $live);
        self::assertStringContainsString('admin search: dense results contract failed', $live);
        self::assertStringContainsString('admin mobile: dense dashboard responsive contract failed', $live);
        self::assertStringContainsString('overviewColumns !== 2', $live);
        self::assertStringContainsString('rowColumns !== 1', $live);
    }

    public function testDenseDashboardKeepsRealPermissionAndCsrfFlow(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminDashboardHandler.php');
        $service = (string) file_get_contents($root . '/core/Admin/AdminInformationArchitectureService.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminDashboardHtml.php');

        self::assertStringContainsString("public const ACCESS_PERMISSION = 'acp.access'", $service);
        self::assertStringContainsString('targetAndRecordRecent', $handler);
        self::assertStringContainsString('toggleFavorite', $handler);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('navigation_key', $html);
        self::assertStringContainsString('aria-pressed=', $html);
    }
}
