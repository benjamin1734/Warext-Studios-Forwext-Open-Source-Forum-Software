<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class Phase16PlatformWorkspaceQualificationTest extends TestCase
{
    public function testPlatformWorkspacesShareDenseFirstPartyShell(): void
    {
        $root = dirname(__DIR__, 4);
        $navigation = (string) file_get_contents($root . '/app/Web/Admin/PublicNavigationHtml.php');
        $navigationHandler = (string) file_get_contents($root . '/app/Web/Admin/PublicNavigationHandler.php');
        $modules = (string) file_get_contents($root . '/app/Web/Admin/AdminModuleManagerHtml.php');
        $integrations = (string) file_get_contents($root . '/app/Web/Admin/SystemIntegrationHtml.php');
        $platform = (string) file_get_contents($root . '/app/Web/Admin/AdminPlatformNavHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/admin.css');

        foreach (['platform-tabs', 'platform-overview', 'platform-stat', 'platform-head'] as $marker) {
            self::assertStringContainsString($marker, $css);
        }

        self::assertStringContainsString("AdminPlatformNavHtml::render(\$basePath, 'navigation')", $navigation);
        self::assertStringContainsString("AdminPlatformNavHtml::render(\$basePath, 'modules')", $modules);
        self::assertStringContainsString("AdminPlatformNavHtml::render(\$basePath, 'integrations')", $integrations);
        self::assertStringContainsString("'/admin/navigation'", $platform);
        self::assertStringContainsString("'/admin/modules'", $platform);
        self::assertStringContainsString("'/admin/integrations'", $platform);
        self::assertStringContainsString("['all','enabled','disabled']", $navigationHandler);
        self::assertStringContainsString("['all','primary','more']", $navigationHandler);
        self::assertStringContainsString('nav-admin-filter platform-filter', $navigation);
        self::assertStringContainsString("AdminPlatformNavHtml::stat('Aktif'", $modules);
        self::assertStringContainsString("AdminPlatformNavHtml::stat('Secrets'", $integrations);
    }

    public function testLiveBrowserCoversPlatformRoutesAndMobileReflow(): void
    {
        $root = dirname(__DIR__, 4);
        $live = (string) file_get_contents($root . '/tools/browser/live-route-smoke.mjs');

        self::assertStringContainsString('/admin/navigation?state=all&placement=all', $live);
        self::assertStringContainsString('admin navigation: platform workspace contract failed', $live);
        self::assertStringContainsString('/admin/modules?state=all', $live);
        self::assertStringContainsString('admin modules: platform workspace contract failed', $live);
        self::assertStringContainsString('/admin/integrations', $live);
        self::assertStringContainsString('admin integrations: platform workspace contract failed', $live);
        self::assertStringContainsString('mobile: responsive contract failed', $live);
    }
}
