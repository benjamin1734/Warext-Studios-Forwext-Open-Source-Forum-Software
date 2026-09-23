<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class OperationsAnalyticsWebSurfaceTest extends TestCase
{
    public function testOperationsDashboardUsesSharedPermissionAndPrivateResponsePolicy(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Analytics/OperationsAnalyticsHandler.php');
        $service = (string) file_get_contents($root . '/core/Analytics/Operations/OperationsAnalyticsService.php');

        self::assertStringContainsString("'analytics.operations'", $factory);
        self::assertStringContainsString("'/admin/analytics/operations'", $factory);
        self::assertStringContainsString("$this->access->require($actor, 'analytics.view_operations')", $service);
        self::assertStringContainsString("['7', '30', '90']", $handler);
        self::assertStringContainsString("'private, no-store'", $handler);
        self::assertStringContainsString("'noindex,nofollow'", $handler);
    }

    public function testRepositoryAggregatesAuthoritativeOperationalMetadataWithoutFreeTextPayloads(): void
    {
        $root = dirname(__DIR__, 4);
        $repository = (string) file_get_contents(
            $root . '/core/Analytics/Operations/DatabaseOperationsAnalyticsRepository.php',
        );

        foreach ([
            'forwext_reports',
            'forwext_report_groups',
            'forwext_discipline_actions',
            'forwext_support_tickets',
            'forwext_bug_reports',
            'forwext_bug_report_categories',
            'forwext_core_audit_events',
        ] as $table) {
            self::assertStringContainsString($table, $repository);
        }

        foreach (['detail', 'summary', 'reason_text', 'before_json', 'after_json', 'payload_json'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $repository);
        }
    }
}
