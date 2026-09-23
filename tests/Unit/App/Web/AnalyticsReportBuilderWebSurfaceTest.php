<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class AnalyticsReportBuilderWebSurfaceTest extends TestCase
{
    public function testReportBuilderAndExportsAreWiredWithCsrfAndPrivatePolicies(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root.'/app/Web/WebApplicationFactory.php');
        $builder = (string) file_get_contents($root.'/app/Web/Analytics/AnalyticsReportBuilderHandler.php');
        $export = (string) file_get_contents($root.'/app/Web/Analytics/AnalyticsReportExportHandler.php');

        self::assertStringContainsString("'analytics.reports'", $factory);
        self::assertStringContainsString("'/admin/analytics/reports'", $factory);
        self::assertStringContainsString("'analytics.reports.export'", $factory);
        self::assertStringContainsString("'/admin/analytics/reports/export'", $factory);
        self::assertStringContainsString("'analytics-report'", $factory);
        self::assertStringContainsString('[$analyticsReportCsrf]', $factory);

        self::assertStringContainsString("'private, no-store'", $builder);
        self::assertStringContainsString("'noindex,nofollow'", $builder);
        self::assertStringContainsString('HttpAuditRequestId::fromRequest($request)', $builder);

        self::assertStringContainsString("'text/csv; charset=utf-8'", $export);
        self::assertStringContainsString('Response::json(', $export);
        self::assertStringContainsString("'X-Content-Type-Options', 'nosniff'", $export);
        self::assertStringContainsString("'private, no-store'", $export);
    }

    public function testReportRepositoryUsesAggregatesAndAvoidsRawIdentityOrSensitivePayloadColumns(): void
    {
        $root = dirname(__DIR__, 4);
        $repository = (string) file_get_contents(
            $root.'/core/Analytics/Report/DatabaseAnalyticsReportRepository.php',
        );

        foreach ([
            'COUNT(*) AS count',
            'COUNT(DISTINCT e.user_id) AS count',
            'SUM(total_minor)',
            'privacyMinCount',
        ] as $required) {
            self::assertStringContainsString($required, $repository);
        }

        foreach ([
            'actor_user_id',
            'viewer_user_id',
            'buyer_user_id',
            'seller_user_id',
            'ip_fingerprint',
            'device_fingerprint',
            'network_fingerprint',
            'billing_json',
            'receipt_json',
            'before_json',
            'after_json',
            'payload_json',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $repository);
        }
    }

    public function testScopedAnalyticsPermissionsAndPrivacyFloorAreImplemented(): void
    {
        $root = dirname(__DIR__, 4);
        $access = (string) file_get_contents($root.'/core/Analytics/Access/AnalyticsAccessService.php');
        $service = (string) file_get_contents($root.'/core/Analytics/Report/AnalyticsReportService.php');
        $migration = (string) file_get_contents($root.'/database/migrations/core/CreateAnalyticsReportBuilder.php');

        self::assertStringContainsString("'analytics.view_site'", $access);
        self::assertStringContainsString("'analytics.report.use'", $service);
        self::assertStringContainsString("'analytics.export'", $service);
        self::assertStringContainsString("'analytics.report.manage_all'", $service);
        self::assertStringContainsString("'analytics.report.unaggregated'", $service);
        self::assertStringContainsString('max(5, $definition->privacyMinCount)', $service);

        foreach ([
            'analytics.view_forum',
            'analytics.view_content',
            'analytics.view_operations',
            'analytics.view_commerce',
            'analytics.report.use',
            'analytics.export',
        ] as $permission) {
            self::assertStringContainsString($permission, $migration);
        }
    }
}
