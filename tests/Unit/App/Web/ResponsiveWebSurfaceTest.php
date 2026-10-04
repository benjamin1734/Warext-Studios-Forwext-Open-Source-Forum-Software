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
        self::assertStringContainsString('data-forwext-subnav', $html);
        self::assertStringContainsString("'forums' => 'forums'", $html);
        self::assertStringContainsString('NavigationRuntime::registry()', $html);
        self::assertStringContainsString('id="main-content"', $html);
        self::assertStringContainsString('/assets/mobile-nav.js', $html);
        self::assertStringContainsString('<html lang="tr" dir="ltr">', $html);
    }

    public function testReferenceShellUsesDensePrimarySecondaryAndToolPopoverContracts(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-components.css');
        $script = (string) file_get_contents($root . '/public/assets/mobile-nav.js');

        self::assertStringContainsString('/* compact-reference-density-v1 */', $css);
        self::assertStringContainsString("'portfolio' => 'portfolio'", $html);
        self::assertStringContainsString("'faq' => 'faq'", $html);
        self::assertStringContainsString('NavigationPlacement::Primary', $html);
        self::assertStringContainsString('class="nav-account-grid"', $html);
        self::assertStringContainsString('nav-tool-menu--messages', $html);
        self::assertStringContainsString('nav-tool-menu--alerts', $html);
        self::assertStringContainsString('class="nav-search-menu"', $html);
        self::assertStringContainsString('class="nav-search-form"', $html);
        self::assertStringContainsString('name="q"', $html);
        self::assertStringContainsString('nav-tool-popover', $css);
        self::assertStringContainsString('nav-search-popover', $css);
        self::assertStringContainsString('details.classList.contains("nav-search-menu")', $script);
        self::assertStringContainsString('details.classList.contains("nav-tool-menu")', $script);
        self::assertStringContainsString('searchInput.focus()', $script);
    }

    public function testMobileNavIsProgressivelyEnhancedAndKeyboardClosable(): void
    {
        $root = dirname(__DIR__, 4);
        $script = (string) file_get_contents($root . '/public/assets/mobile-nav.js');

        self::assertStringContainsString('forwextMobileNav = "enhanced"', $script);
        self::assertStringContainsString('aria-expanded', $script);
        self::assertStringContainsString('event.key === "Escape"', $script);
        self::assertStringContainsString('button.focus()', $script);

        $css = (string) file_get_contents($root . '/public/assets/site-components.css');
        self::assertStringContainsString('overscroll-behavior:contain', $css);
        self::assertStringContainsString('pointer-events:none', $css);
        self::assertStringContainsString('position:fixed', $css);
        self::assertStringContainsString('z-index:2600', $css);
        self::assertStringContainsString('max-height:min(calc(100dvh - 66px),720px)', $css);
        self::assertStringContainsString('body.forwext-nav-open #main-content', $css);
        self::assertStringContainsString('pointer-events:none', $css);
        self::assertStringNotContainsString('transition:max-height 220ms ease', $css);
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
