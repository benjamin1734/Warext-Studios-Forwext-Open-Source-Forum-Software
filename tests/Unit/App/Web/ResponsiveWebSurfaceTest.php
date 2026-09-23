<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ResponsiveWebSurfaceTest extends TestCase
{
    public function testNativeShellUsesResponsiveCompilerAccessibleMobileNavAndSkipLink(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString('ResponsiveRegistry::coreDefaults()', $html);
        self::assertStringContainsString('new ResponsiveCssCompiler()', $html);
        self::assertStringContainsString('class="skip-link"', $html);
        self::assertStringContainsString('href="#main-content"', $html);
        self::assertStringContainsString('data-forwext-nav-toggle', $html);
        self::assertStringContainsString('aria-expanded="false"', $html);
        self::assertStringContainsString('aria-controls="forwext-primary-navigation"', $html);
        self::assertStringContainsString('data-forwext-primary-navigation', $html);
        self::assertStringContainsString('id="main-content"', $html);
        self::assertStringContainsString('/assets/mobile-nav.js', $html);
        self::assertStringContainsString('<html lang="tr" dir="ltr">', $html);
    }

    public function testMobileNavIsProgressivelyEnhancedAndKeyboardClosable(): void
    {
        $root = dirname(__DIR__, 4);
        $script = (string) file_get_contents($root . '/public/assets/mobile-nav.js');

        self::assertStringContainsString('forwextMobileNav = "enhanced"', $script);
        self::assertStringContainsString('aria-expanded', $script);
        self::assertStringContainsString('event.key === "Escape"', $script);
        self::assertStringContainsString('button.focus()', $script);
    }

    public function testModernFrontendSharesResponsiveManifest(): void
    {
        $root = dirname(__DIR__, 4);
        $typescript = (string) file_get_contents($root . '/packages/design-tokens/index.ts');

        self::assertStringContainsString(
            '../../resources/appearance/forwext-responsive-default.json',
            $typescript,
        );
        self::assertStringContainsString('forwextResponsiveManifest', $typescript);
    }
}
