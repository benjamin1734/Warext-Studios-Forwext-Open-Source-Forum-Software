<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase17ObservabilityWorkspaceQualificationTest extends TestCase
{
    public function testAnalyticsSurfacesShareObservabilityNavigation(): void
    {
        $root = dirname(__DIR__, 4);
        $nav = (string) file_get_contents($root . '/app/Web/Analytics/AnalyticsAdminNavHtml.php');
        $overview = (string) file_get_contents($root . '/app/Web/Analytics/ForumAnalyticsHtml.php');
        $content = (string) file_get_contents($root . '/app/Web/Analytics/ContentEngagementHtml.php');
        $operations = (string) file_get_contents($root . '/app/Web/Analytics/OperationsAnalyticsHtml.php');
        $commerce = (string) file_get_contents($root . '/app/Web/Analytics/CommerceAnalyticsHtml.php');
        $reports = (string) file_get_contents($root . '/app/Web/Analytics/AnalyticsReportHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        foreach ([
            "'/admin/analytics'",
            "'/admin/analytics/content'",
            "'/admin/analytics/operations'",
            "'/admin/analytics/commerce'",
            "'/admin/analytics/reports'",
            "'/admin/users'",
        ] as $route) {
            self::assertStringContainsString($route, $nav);
        }

        self::assertStringContainsString('AnalyticsAdminNavHtml::header(', $overview);
        self::assertStringContainsString('AnalyticsAdminNavHtml::header(', $content);
        self::assertStringContainsString('AnalyticsAdminNavHtml::header(', $operations);
        self::assertStringContainsString('AnalyticsAdminNavHtml::header(', $commerce);
        self::assertStringContainsString('AnalyticsAdminNavHtml::header(', $reports);
        self::assertStringContainsString('analytics-tabs', $css);
        self::assertStringContainsString('analytics-range', $css);
    }

    public function testLiveBrowserCoversObservabilityRoutesAndMobileReflow(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        self::assertStringContainsString('/admin/analytics/content', $live);
        self::assertStringContainsString('/admin/analytics/operations', $live);
        self::assertStringContainsString('/admin/analytics/commerce', $live);
        self::assertStringContainsString('/admin/analytics/reports', $live);
        self::assertStringContainsString('admin analytics: observability workspace contract failed', $live);
        self::assertStringContainsString('admin analytics ${label} mobile: responsive contract failed', $live);
    }
}
