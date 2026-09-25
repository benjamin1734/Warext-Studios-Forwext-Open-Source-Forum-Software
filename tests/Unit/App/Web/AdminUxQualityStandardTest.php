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

        self::assertStringContainsString("['all','health','maintenance','jobs','backups','logs','repairs']", $handler);
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
