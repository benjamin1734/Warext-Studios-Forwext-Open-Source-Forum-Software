<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ThemeManagementWebSurfaceTest extends TestCase
{
    public function testAdminThemeRouteUsesCsrfPermissionsRevisionControlsAndNoindex(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Appearance/ThemeManageHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Appearance/ThemeManageHtml.php');
        $service = (string) file_get_contents($root . '/core/Ui/Theme/ThemeService.php');

        self::assertStringContainsString('/admin/appearance/themes', $factory);
        self::assertStringContainsString('themeCsrfMiddleware', $factory);
        self::assertStringContainsString('appearance.manage', $service);
        self::assertStringContainsString('appearance.advanced', $service);
        self::assertStringContainsString('appearance.theme.stage', $service);
        self::assertStringContainsString('appearance.theme.publish', $service);
        self::assertStringContainsString('appearance.theme.rollback', $service);
        self::assertStringContainsString('X-Robots-Tag', $handler);
        self::assertStringContainsString('Revision diff', $html);
        self::assertStringContainsString('Staging’e al', $html);
        self::assertStringContainsString('Custom CSS', $html);
        self::assertStringContainsString('Custom JavaScript', $html);
    }

    public function testThemeAssetsAreSameOriginRevisionAddressedAndNosniff(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Appearance/ThemeAssetHandler.php');

        self::assertStringContainsString('/theme-assets/{themeKey}/{revisionId}/custom.css', $factory);
        self::assertStringContainsString('/theme-assets/{themeKey}/{revisionId}/custom.js', $factory);
        self::assertStringContainsString('X-Content-Type-Options', $handler);
        self::assertStringContainsString('immutable', $handler);
        self::assertStringContainsString('text/css; charset=utf-8', $handler);
        self::assertStringContainsString('text/javascript; charset=utf-8', $handler);
    }
}
