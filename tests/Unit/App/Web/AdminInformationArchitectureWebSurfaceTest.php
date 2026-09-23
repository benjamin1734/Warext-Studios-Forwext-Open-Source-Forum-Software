<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class AdminInformationArchitectureWebSurfaceTest extends TestCase
{
    public function testCentralAcpDashboardUsesBackendAccessAndCsrfProtectedActions(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminDashboardHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminDashboardHtml.php');
        $service = (string) file_get_contents($root . '/core/Admin/AdminInformationArchitectureService.php');

        self::assertStringContainsString("'admin.dashboard'", $factory);
        self::assertStringContainsString("new PathTemplate('/admin')", $factory);
        self::assertStringContainsString('[HttpMethod::Get, HttpMethod::Post]', $factory);
        self::assertStringContainsString('$adminNavigationCsrf', $factory);
        self::assertStringContainsString("'acp.access'", $service);
        self::assertStringContainsString('targetAndRecordRecent', $handler);
        self::assertStringContainsString('HttpMethod::Post', $handler);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('Yönetim alanlarında ara', $html);
        self::assertStringContainsString('İşlem gerekenler', $html);
        self::assertStringContainsString('Favoriler', $html);
        self::assertStringContainsString('Son kullanılanlar', $html);
        self::assertStringContainsString('Breadcrumb', $html);
        self::assertStringContainsString('X-Robots-Tag', $handler);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testQueueQueriesAreGuardedByTheirBackendPermissions(): void
    {
        $root = dirname(__DIR__, 4);
        $queues = (string) file_get_contents($root . '/core/Admin/Dashboard/AdminActionQueueService.php');

        self::assertStringContainsString("allows(\$actor, 'support.ticket.view_all')", $queues);
        self::assertStringContainsString("allows(\$actor, 'bug.report.view_all')", $queues);
        self::assertStringContainsString("allows(\$actor, 'moderation.access')", $queues);
        self::assertStringContainsString('forwext_support_tickets', $queues);
        self::assertStringContainsString('forwext_bug_reports', $queues);
        self::assertStringContainsString('forwext_report_groups', $queues);
        self::assertStringContainsString('forwext_moderation_tasks', $queues);
    }

    public function testNavigationTargetsAreStaticFirstPartyPathsRatherThanUserSuppliedRedirects(): void
    {
        $root = dirname(__DIR__, 4);
        $item = (string) file_get_contents($root . '/core/Admin/Navigation/AdminNavigationItem.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminDashboardHandler.php');

        self::assertStringContainsString('safe first-party management path', $item);
        self::assertStringContainsString("str_starts_with(\$path, '/admin/')", $item);
        self::assertStringContainsString("str_starts_with(\$path, '/moderation/')", $item);
        self::assertStringContainsString('$this->administration->targetAndRecordRecent', $handler);
        self::assertStringContainsString('$this->basePath->prepend($path)', $handler);
        self::assertStringNotContainsString("parsedBody()['url']", $handler);
    }
}
