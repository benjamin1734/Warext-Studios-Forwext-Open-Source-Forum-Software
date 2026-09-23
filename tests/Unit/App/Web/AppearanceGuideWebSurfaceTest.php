<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class AppearanceGuideWebSurfaceTest extends TestCase
{
    public function testAppearanceGuideIsPermissionAwareReadOnlyAndLinksRealEditors(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Appearance/AppearanceGuideHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Appearance/AppearanceGuideHtml.php');
        $service = (string) file_get_contents($root . '/core/Ui/Appearance/Guide/AppearanceGuideService.php');

        self::assertStringContainsString("new PathTemplate('/admin/appearance')", $factory);
        self::assertStringContainsString('[HttpMethod::Get]', $factory);
        self::assertStringContainsString('appearance.manage', $service);
        self::assertStringContainsString('appearance.advanced', $service);
        self::assertStringContainsString('X-Robots-Tag', $handler);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringContainsString('Basit', $html);
        self::assertStringContainsString('Gelişmiş', $html);
        self::assertStringContainsString('Varsayılana dön', $html);
        self::assertStringContainsString('Canlı preview', $html);
        self::assertStringContainsString('Kurulum asistanı', $html);
        self::assertStringContainsString('/admin/appearance/themes', $html);
        self::assertStringContainsString('/admin/appearance/layout', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testPresetPreviewValuesAreStrictlyAllowlisted(): void
    {
        $root = dirname(__DIR__, 4);
        $preset = (string) file_get_contents($root . '/core/Ui/Appearance/Guide/AppearancePreset.php');

        self::assertStringContainsString('--guide-gap', $preset);
        self::assertStringContainsString('--guide-sidebar-width', $preset);
        self::assertStringContainsString('preg_match($patterns[$key]', $preset);
        self::assertStringNotContainsString('eval(', $preset);
    }
}
