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


    public function testEveryCoreAdminNavigationTargetHasARegisteredFirstPartyRoute(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $registry = (string) file_get_contents($root . '/core/Admin/Navigation/AdminNavigationRegistry.php');
        $sections = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunitySection.php');

        preg_match_all("/new PathTemplate\\('([^']+)'\\)/", $factory, $routeMatches);
        $routes = array_fill_keys($routeMatches[1] ?? [], true);

        preg_match_all("/case\\s+[A-Za-z]+\\s*=\\s*'([^']+)'/", $sections, $sectionMatches);
        foreach ($sectionMatches[1] ?? [] as $value) {
            $routes['/admin/' . $value] = true;
        }

        preg_match_all(
            "/new AdminNavigationItem\\([\\s\\S]*?'(admin\\.[^']+)'[\\s\\S]*?'(\\/(?:admin|support|bugs)[^']*)'[\\s\\S]*?\\n\\s*\\),/",
            $registry,
            $navigationMatches,
        );

        $targets = [];
        foreach (($navigationMatches[1] ?? []) as $index => $key) {
            $path = $navigationMatches[2][$index] ?? null;
            if (!is_string($path)) {
                continue;
            }
            $targets[$key] = $path;
            self::assertArrayHasKey(
                $path,
                $routes,
                sprintf('ACP navigation target %s (%s) must resolve to a registered first-party route.', $key, $path),
            );
        }

        self::assertCount(26, $targets);
        self::assertSame('/admin/users', $targets['admin.users'] ?? null);
        self::assertSame('/admin/system/operations', $targets['admin.system.operations'] ?? null);
        self::assertSame('/support/staff', $targets['admin.support'] ?? null);
        self::assertSame('/bugs/staff', $targets['admin.bugs'] ?? null);
    }


    public function testCoreAndAppearanceAcpSurfacesLoadSharedAdministrationStyles(): void
    {
        $root = dirname(__DIR__, 4);
        $helper = (string) file_get_contents($root . '/app/Web/Admin/AdminAssetsHtml.php');
        self::assertStringContainsString('/assets/admin.css', $helper);

        foreach ([
            'Admin/AdminDashboardHandler.php',
            'Admin/AdminCommunityHandler.php',
            'Admin/AdminModuleManagerHandler.php',
            'Admin/SystemIntegrationHandler.php',
            'Admin/SystemOperationsHandler.php',
            'Appearance/AppearanceGuideHandler.php',
            'Appearance/LayoutBuilderHandler.php',
            'Appearance/ThemeManageHandler.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/' . $file);
            self::assertStringContainsString('AdminAssetsHtml::headAssets', $source, $file);
        }
    }


    public function testModuleAndAnalyticsAcpRenderersLoadSharedAdministrationStyles(): void
    {
        $root = dirname(__DIR__, 4);

        foreach ([
            'Advertising/AdvertisingHtml.php',
            'Analytics/AnalyticsReportHtml.php',
            'Analytics/CommerceAnalyticsHtml.php',
            'Analytics/ContentEngagementHtml.php',
            'Analytics/ForumAnalyticsHtml.php',
            'Analytics/OperationsAnalyticsHtml.php',
            'EasterEgg/EasterEggHtml.php',
            'Marketplace/MarketplaceCategoryHtml.php',
            'Payment/PaymentHtml.php',
            'Promotion/PromotionHtml.php',
            'Reward/RewardHtml.php',
            'Subscription/SubscriptionHtml.php',
            'Trophy/TrophyHtml.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/' . $file);
            self::assertStringContainsString('AdminAssetsHtml::headAssets', $source, $file);
        }
    }


    public function testAppearanceAcpPresentationHasNoInlineStyles(): void
    {
        $root = dirname(__DIR__, 4);
        $asset = (string) file_get_contents($root . '/public/assets/admin.css');

        foreach ([
            'AppearanceGuideHtml.php',
            'LayoutBuilderHtml.php',
            'ThemeManageHtml.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/Appearance/' . $file);
            self::assertStringNotContainsString('<style>', $source, $file);
            self::assertStringNotContainsString('</style>', $source, $file);
        }

        self::assertStringContainsString('/* Appearance Studio */', $asset);
        self::assertStringContainsString('/* Layout Builder */', $asset);
        self::assertStringContainsString('/* Theme Manager */', $asset);
        self::assertSame(substr_count($asset, '{'), substr_count($asset, '}'));
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
