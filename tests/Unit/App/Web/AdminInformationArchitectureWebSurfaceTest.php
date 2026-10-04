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

        self::assertCount(27, $targets);
        self::assertSame('/admin/users', $targets['admin.users'] ?? null);
        self::assertSame('/admin/navigation', $targets['admin.navigation'] ?? null);
        self::assertSame('/admin/system/operations', $targets['admin.system.operations'] ?? null);
        self::assertSame('/support/staff', $targets['admin.support'] ?? null);
        self::assertSame('/bugs/staff', $targets['admin.bugs'] ?? null);
    }


    public function testPublicNavigationManagerIsFirstPartyCsrfProtectedAndNoindex(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Admin/PublicNavigationHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/PublicNavigationHtml.php');
        $service = (string) file_get_contents($root . '/core/Admin/Navigation/PublicNavigationService.php');

        self::assertStringContainsString("new PathTemplate('/admin/navigation')", $factory);
        self::assertStringContainsString('publicNavigationCsrfMiddleware', $factory);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('Navigasyon Yönetimi', $html);
        self::assertStringContainsString("'navigation.items'", $service);
        self::assertStringContainsString("'acp.manage'", $service);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);
        self::assertStringContainsString('noindex,nofollow', $handler);
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
            'Admin/PublicNavigationHandler.php',
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


    public function testInternalAcpManagementResponsesArePrivateAndNoindex(): void
    {
        $root = dirname(__DIR__, 4);

        foreach ([
            'Advertising/AdvertisingManageHandler.php',
            'Analytics/AnalyticsReportBuilderHandler.php',
            'Analytics/CommerceAnalyticsHandler.php',
            'Analytics/ContentEngagementHandler.php',
            'Analytics/ForumAnalyticsHandler.php',
            'Analytics/OperationsAnalyticsHandler.php',
            'Appearance/AppearanceGuideHandler.php',
            'Appearance/LayoutBuilderHandler.php',
            'Appearance/ThemeManageHandler.php',
            'EasterEgg/EasterEggManageHandler.php',
            'Marketplace/MarketplaceCategoryManageHandler.php',
            'Payment/PaymentManageHandler.php',
            'Promotion/PromotionManageHandler.php',
            'Reward/RewardManageHandler.php',
            'Subscription/SubscriptionManageHandler.php',
            'Trophy/TrophyManageHandler.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/' . $file);
            self::assertStringContainsString('private, no-store', $source, $file);
            self::assertStringContainsString('X-Robots-Tag', $source, $file);
            self::assertStringContainsString('noindex,nofollow', $source, $file);
        }
    }


    public function testCoreAcpUsesOneBreadcrumbTrail(): void
    {
        $root = dirname(__DIR__, 4);

        foreach ([
            'AdminDashboardHandler.php',
            'AdminCommunityHandler.php',
            'AdminModuleManagerHandler.php',
            'PublicNavigationHandler.php',
            'SystemIntegrationHandler.php',
            'SystemOperationsHandler.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/Admin/' . $file);
            self::assertStringContainsString('breadcrumbs: new BreadcrumbTrail([])', $source, $file);
        }
    }


    public function testNonCoreAcpPagesKeepAdministrationBreadcrumbContext(): void
    {
        $root = dirname(__DIR__, 4);
        $helper = (string) file_get_contents($root . '/app/Web/Admin/AdminAssetsHtml.php');

        self::assertStringContainsString("new BreadcrumbItem('Administration', '/admin')", $helper);

        foreach ([
            'Appearance/AppearanceGuideHandler.php',
            'Appearance/LayoutBuilderHandler.php',
            'Appearance/ThemeManageHandler.php',
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
            self::assertStringContainsString('AdminAssetsHtml::breadcrumbTrail', $source, $file);
        }
    }


    public function testAnalyticsAndAdvertisingAcpAvoidStaticInlinePresentationStyles(): void
    {
        $root = dirname(__DIR__, 4);
        $asset = (string) file_get_contents($root . '/public/assets/admin.css');
        self::assertStringContainsString('analytics-and-advertising-admin-utilities-v1', $asset);
        self::assertStringContainsString('.acp-data-table', $asset);
        self::assertStringContainsString('.acp-table-wrap', $asset);

        foreach ([
            'Advertising/AdvertisingHtml.php',
            'Analytics/AnalyticsReportHtml.php',
            'Analytics/CommerceAnalyticsHtml.php',
            'Analytics/ContentEngagementHtml.php',
            'Analytics/ForumAnalyticsHtml.php',
            'Analytics/OperationsAnalyticsHtml.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/' . $file);
            self::assertStringNotContainsString('style="margin-top:16px;overflow:auto"', $source, $file);
            self::assertStringNotContainsString('style="width:100%;border-collapse:collapse"', $source, $file);
            self::assertStringNotContainsString('style="text-align:left"', $source, $file);
            self::assertStringNotContainsString('style="text-align:center"', $source, $file);
        }
    }


    public function testAppearanceStaticPresentationIsOwnedByAdminStylesheet(): void
    {
        $root = dirname(__DIR__, 4);
        $asset = (string) file_get_contents($root . '/public/assets/admin.css');
        $layout = (string) file_get_contents($root . '/app/Web/Appearance/LayoutBuilderHtml.php');
        $theme = (string) file_get_contents($root . '/app/Web/Appearance/ThemeManageHtml.php');

        self::assertStringContainsString('.builder-title', $asset);
        self::assertStringContainsString('.theme-main', $asset);
        self::assertStringNotContainsString('style="margin:0"', $layout);
        self::assertStringNotContainsString('style="display:grid;gap:18px"', $theme);
    }


    public function testStaticAdminSuccessNoticesUseSharedPresentation(): void
    {
        $root = dirname(__DIR__, 4);
        $asset = (string) file_get_contents($root . '/public/assets/admin.css');

        self::assertStringContainsString('.acp-success', $asset);

        foreach ([
            'Promotion/PromotionHtml.php',
            'Reward/RewardHtml.php',
            'Trophy/TrophyHtml.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/' . $file);
            self::assertStringContainsString('search-alert acp-success', $source, $file);
            self::assertStringNotContainsString('style="border-color:#2f6f47;background:#173722"', $source, $file);
        }
    }

    public function testAppearanceGuideKeepsOnlyDynamicPreviewInlineStyle(): void
    {
        $root = dirname(__DIR__, 4);
        $source = (string) file_get_contents($root . '/app/Web/Appearance/AppearanceGuideHtml.php');

        preg_match_all('/style="[^"]*"/', $source, $matches);
        self::assertCount(1, $matches[0] ?? []);
        self::assertStringContainsString('self::escape($previewStyle)', $source);
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
