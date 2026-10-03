<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class AdminUxQualityStandardTest extends TestCase
{
    public function testComplexNativeAcpSurfacesExposeSharedGuidanceAndProgressiveControls(): void
    {
        $root = dirname(__DIR__, 4);
        $helper = (string) file_get_contents($root . '/app/Web/Admin/AdminUxQualityHtml.php');

        self::assertStringContainsString('Güvenli varsayılan', $helper);
        self::assertStringContainsString('Önizleme / doğrulama', $helper);
        self::assertStringContainsString('Geri dönüş', $helper);
        self::assertStringNotContainsString('<script', $helper);

        foreach ([
            'AdminDashboardHtml.php',
            'AdminCommunityHtml.php',
            'AdminModuleManagerHtml.php',
            'SystemIntegrationHtml.php',
            'SystemOperationsHtml.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/Admin/' . $file);
            self::assertStringContainsString('AdminUxQualityHtml::guidance', $source, $file);
            self::assertStringNotContainsString('<script', $source, $file);
        }
    }

    public function testNativeAcpPresentationUsesOneStaticStylesheet(): void
    {
        $root = dirname(__DIR__, 4);
        $asset = (string) file_get_contents($root . '/public/assets/admin.css');
        $qualityHelper = (string) file_get_contents($root . '/app/Web/Admin/AdminUxQualityHtml.php');

        self::assertNotSame('', trim($asset));
        self::assertStringContainsString('.acp-breadcrumbs', $asset);
        self::assertStringContainsString('.acp-ux-guide', $asset);
        self::assertStringContainsString(':focus-visible', $asset);
        self::assertStringContainsString('.mod-actions--spaced', $asset);
        self::assertStringContainsString('.mod-settings-body', $asset);
        self::assertSame(substr_count($asset, '{'), substr_count($asset, '}'));
        self::assertStringNotContainsString('function css(', $qualityHelper);

        foreach ([
            'AdminDashboardHtml.php',
            'AdminCommunityHtml.php',
            'AdminModuleManagerHtml.php',
            'SystemIntegrationHtml.php',
            'SystemOperationsHtml.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/Admin/' . $file);
            self::assertStringNotContainsString('<style>', $source, $file);
            self::assertStringNotContainsString('AdminUxQualityHtml::css', $source, $file);
            if ($file === 'AdminModuleManagerHtml.php') {
                self::assertStringNotContainsString('style="margin-top:', $source, $file);
                self::assertStringContainsString('mod-actions--spaced', $source, $file);
                self::assertStringContainsString('mod-settings-body', $source, $file);
            }
        }

        $assetsHelper = (string) file_get_contents($root . '/app/Web/Admin/AdminAssetsHtml.php');
        self::assertStringContainsString('/assets/admin.css', $assetsHelper);

        foreach ([
            'AdminDashboardHandler.php',
            'AdminCommunityHandler.php',
            'AdminModuleManagerHandler.php',
            'SystemIntegrationHandler.php',
            'SystemOperationsHandler.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/app/Web/Admin/' . $file);
            self::assertStringContainsString('AdminAssetsHtml::headAssets', $source, $file);
            self::assertStringContainsString('headAssets:', $source, $file);
        }
    }

    public function testModuleManagerHasServerValidatedSearchAndLifecycleStateFilter(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminModuleManagerHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminModuleManagerHtml.php');

        self::assertStringContainsString("['all', 'enabled', 'disabled', 'uninstalled']", $handler);
        self::assertStringContainsString("optionalString(\$query, 'q', 80)", $handler);
        self::assertStringContainsString('name="q"', $html);
        self::assertStringContainsString('name="state"', $html);
        self::assertStringContainsString('Filtreyi sıfırla', $html);
        self::assertStringContainsString('dependency/conflict graph', strtolower($html));
        self::assertStringContainsString('exact module key', strtolower($html));
    }

    public function testSystemOperationsFilterIsBoundedAndDoesNotReplaceBackendPermissions(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Admin/SystemOperationsHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/SystemOperationsHtml.php');

        self::assertStringContainsString("['all','health','maintenance','updates','jobs','backups','logs','repairs']", $handler);
        self::assertStringContainsString('name="section"', $html);
        self::assertStringContainsString('Filtreyi sıfırla', $html);
        self::assertStringContainsString('typed confirmation', strtolower($html));
        self::assertStringContainsString('self::allowed(', $html);
    }

    public function testCommunityAccessAndForumListsCanBeFilteredWithoutClientSideAuthorization(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Admin/AdminCommunityHtml.php');
        $service = (string) file_get_contents($root . '/core/Admin/Community/AdminCommunityService.php');

        self::assertStringContainsString("'ux_query'=>\$uxQuery", $handler);
        self::assertStringContainsString('Grup veya rol ara', $html);
        self::assertStringContainsString('Node ara', $html);
        self::assertStringContainsString('Permission analyzer', $html);
        self::assertStringContainsString('Kaydedilmiş görünüm önizlemesi', $html);
        self::assertStringContainsString("MANAGE_PERMISSION = 'acp.manage'", $service);
    }
}
