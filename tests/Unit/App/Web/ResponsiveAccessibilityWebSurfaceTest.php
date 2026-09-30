<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ResponsiveAccessibilityWebSurfaceTest extends TestCase
{
    public function testSharedShellExposesKeyboardMotionAndBrowserRegressionContracts(): void
    {
        $root = dirname(__DIR__, 4);
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-shell.css');
        $mobileNav = (string) file_get_contents($root . '/public/assets/mobile-nav.js');
        $package = (string) file_get_contents($root . '/package.json');
        $workflow = (string) file_get_contents($root . '/.github/workflows/qualification-matrix.yml');
        $fixtureRenderer = (string) file_get_contents($root . '/tools/browser/render-shell-fixtures.php');
        $browserSmoke = (string) file_get_contents($root . '/tools/browser/shell-smoke.mjs');

        self::assertStringContainsString('class="skip-link" href="#main-content"', $profile);
        self::assertStringContainsString('<main id="main-content"', $profile);
        self::assertStringContainsString('aria-label="Ana navigasyon"', $profile);
        self::assertStringContainsString('aria-expanded="false"', $profile);
        self::assertStringContainsString('aria-label="Hesap seçenekleri"', $profile);
        self::assertStringNotContainsString('class="nav-account-popover" role="menu"', $profile);

        self::assertStringContainsString('/* accessibility-regression-v1 */', $css);
        self::assertStringContainsString('.skip-link:focus-visible', $css);
        self::assertStringContainsString('@media(pointer:coarse)', $css);
        self::assertStringContainsString('input:not([type="checkbox"]):not([type="radio"])', $css);
        self::assertStringContainsString('min-height:44px', $css);
        self::assertStringContainsString('@media(prefers-reduced-motion:reduce)', $css);
        self::assertStringContainsString('@media(forced-colors:active)', $css);
        self::assertStringContainsString('overflow-x:clip', $css);

        self::assertStringContainsString('navigation.toggleAttribute("inert", !open)', $mobileNav);
        self::assertStringContainsString('navigation.setAttribute("aria-hidden", open ? "false" : "true")', $mobileNav);
        self::assertStringContainsString('event.key === "Tab"', $mobileNav);
        self::assertStringContainsString('visibleFocusable()', $mobileNav);
        self::assertStringContainsString('button.focus()', $mobileNav);

        self::assertStringContainsString('"browser:fixtures"', $package);
        self::assertStringContainsString('"browser:smoke"', $package);
        self::assertStringContainsString('"playwright": "^1.63.0"', $package);
        self::assertStringContainsString('browser-regression:', $workflow);
        self::assertStringContainsString('npx playwright install --with-deps chromium', $workflow);
        self::assertStringContainsString('frontend-browser-regression', $workflow);

        self::assertStringContainsString("'guest' => ProfileHtml::page", $fixtureRenderer);
        self::assertStringContainsString("str_ends_with(\$match[1], '/assets/mobile-nav.js')", $fixtureRenderer);
        self::assertStringContainsString('Browser fixture script isolation failed.', $fixtureRenderer);
        self::assertStringContainsString("'member' => ProfileHtml::page", $fixtureRenderer);
        self::assertStringContainsString("\$fixtures['moderator']", $fixtureRenderer);
        self::assertStringContainsString('width: 390', $browserSmoke);
        self::assertStringContainsString('width: 768', $browserSmoke);
        self::assertStringContainsString('width: 1024', $browserSmoke);
        self::assertStringContainsString('width: 1440', $browserSmoke);
        self::assertStringContainsString('horizontal overflow', $browserSmoke);
        self::assertStringContainsString('reduced-motion', $browserSmoke);
    }
}
