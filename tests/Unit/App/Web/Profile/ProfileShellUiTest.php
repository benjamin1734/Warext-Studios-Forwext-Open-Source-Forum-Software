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
        $baseCss = (string) file_get_contents($root . '/public/assets/site-base.css');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString("/assets/site-base.css", $profile);
        self::assertStringNotContainsString(".forum-home-layout{display:grid", $profile);
        self::assertStringContainsString('.forum-home-layout', $baseCss);
        self::assertStringContainsString('.thread-post', $baseCss);

        self::assertStringContainsString('.top[data-scrolled="1"]', $css);
        self::assertStringContainsString('.nav-primary>a[aria-current="page"]', $css);
        self::assertStringContainsString('.site-masthead', $css);
        self::assertStringContainsString('.nav-primary-menu', $css);
        self::assertStringContainsString('.nav-secondary-group[hidden]', $css);
        self::assertStringContainsString('.nav-secondary-group>a', $css);
        self::assertStringContainsString('.nav-account-popover', $css);
        self::assertStringContainsString('.nav-user-tools', $css);
        self::assertStringContainsString('.forum-node:hover', $css);
        self::assertStringContainsString('.forum-thread-row:hover', $css);
        self::assertStringContainsString('.forum-thread-list-head', $css);
        self::assertStringContainsString('grid-template-columns:155px minmax(0,1fr)', $css);
        self::assertStringContainsString('/* xf-forum-surfaces-v2 */', $css);
        self::assertStringContainsString('.profile-wall-post:hover', $css);
        self::assertStringContainsString('.acp-card:hover', $css);
        self::assertStringContainsString('.search-hit:hover', $css);
        self::assertStringContainsString('.account-center-card:hover', $css);
        self::assertStringContainsString('.notification-item.is-unread', $css);
        self::assertStringContainsString('.auth-entry-card::before', $css);
        self::assertStringContainsString('.market-card:hover', $css);
        self::assertStringContainsString('.trophy-card:hover', $css);
        self::assertStringContainsString('.portfolio-media figure:hover', $css);
        self::assertStringContainsString('@media(max-width:920px)', $css);
        self::assertStringContainsString('.nav-shell[data-mobile-open="1"]', $css);
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
        self::assertStringContainsString('header.querySelectorAll', $asset);
        self::assertStringContainsString('sectionForPath', $asset);
        self::assertStringContainsString('data-nav-section', $asset);
        self::assertStringContainsString('activeNavSection', $asset);
        self::assertStringContainsString('.nav-account-menu', $asset);
        self::assertStringNotContainsString('innerHTML', $asset);
    }
}
