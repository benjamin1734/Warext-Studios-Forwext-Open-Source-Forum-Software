<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use PHPUnit\Framework\TestCase;

final class ProfileShellUiTest extends TestCase
{
    public function testSharedShellStylesheetCoversCoreForumProfileAdminAndResponsiveSurfaces(): void
    {
        $root = dirname(__DIR__, 5);
        $css = (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString('.top[data-scrolled="1"]', $css);
        self::assertStringContainsString('.nav a[aria-current="page"]', $css);
        self::assertStringContainsString('.forum-node:hover', $css);
        self::assertStringContainsString('.forum-thread-row:hover', $css);
        self::assertStringContainsString('.profile-wall-post:hover', $css);
        self::assertStringContainsString('.acp-card:hover', $css);
        self::assertStringContainsString('@media(max-width:920px)', $css);
        self::assertStringContainsString('.nav[data-mobile-open="1"]', $css);
        self::assertStringContainsString('@media(prefers-reduced-motion:reduce)', $css);
    }

    public function testNavigationEnhancementMarksCurrentPageAndClosesMobileMenuSafely(): void
    {
        $root = dirname(__DIR__, 5);
        $asset = (string) file_get_contents($root . '/public/assets/mobile-nav.js');

        self::assertStringContainsString('aria-current', $asset);
        self::assertStringContainsString('window.matchMedia("(min-width: 921px)")', $asset);
        self::assertStringContainsString('navigation.contains(target)', $asset);
        self::assertStringContainsString('header.dataset.scrolled', $asset);
        self::assertStringNotContainsString('innerHTML', $asset);
    }
}
