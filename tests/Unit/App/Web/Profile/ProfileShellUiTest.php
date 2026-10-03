<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Navigation\NavigationItem;
use Forwext\Core\Ui\Navigation\NavigationRegistry;
use PHPUnit\Framework\TestCase;

final class ProfileShellUiTest extends TestCase
{
    public function testSharedShellStylesheetCoversCoreForumProfileAdminAndResponsiveSurfaces(): void
    {
        $root = dirname(__DIR__, 5);
        $css = (string) file_get_contents($root . '/public/assets/site-components.css')
            . (string) file_get_contents($root . '/public/assets/site-pages.css');
        $baseCss = (string) file_get_contents($root . '/public/assets/site-base.css');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString("/assets/site-base.css", $profile);
        self::assertStringNotContainsString(".forum-home-layout{display:grid", $profile);
        self::assertStringNotContainsString('.forum-home-layout{', $baseCss);
        self::assertStringNotContainsString('.thread-post{', $baseCss);
        self::assertStringContainsString('/* forum-layer-ownership-v2 */', $css);

        self::assertStringContainsString('.top[data-scrolled="1"]', $css);
        self::assertStringContainsString('.nav-primary>a[aria-current="page"]', $css);
        self::assertStringContainsString('.brand-mark', $css);
        self::assertStringContainsString('.nav-primary-menu', $css);
        self::assertStringContainsString('.nav-secondary-group[hidden]', $css);
        self::assertStringContainsString('.nav-secondary-group>a', $css);
        self::assertStringContainsString('.nav-account-popover', $css);
        self::assertStringContainsString('.nav-user-tools', $css);
        self::assertStringContainsString('.forum-node:hover', $css);
        self::assertStringContainsString('.forum-thread-row:hover', $css);
        self::assertStringContainsString('.forum-thread-list-head', $css);
        self::assertStringContainsString('grid-template-columns:168px minmax(0,1fr)', $css);
        self::assertStringContainsString('/* navigation-shell-v1 */', $css);
        self::assertStringContainsString('/* shell-ownership-v2 */', $css);
        self::assertStringContainsString('/* shell-layout-ownership-v2 */', $css);
        self::assertStringContainsString('/* navigation-responsive-ownership-v2 */', $css);
        self::assertStringNotContainsString('.nav[data-mobile-open="1"]', $css);
        self::assertStringContainsString('.layout-sidebar{', $css);
        self::assertStringContainsString('.ui-page-slot{', $css);
        self::assertStringContainsString('/* forum-surfaces-v1 */', $css);
        self::assertStringContainsString(".forum-category{", $css);
        self::assertStringNotContainsString(".thread-view-actions .forum-category{", $css);
        self::assertStringNotContainsString('/* xf-navigation-layout */', $css);
        self::assertStringNotContainsString('/* xf-navigation-v3 */', $css);
        self::assertStringContainsString('.profile-wall-post:hover', $css);
        self::assertStringContainsString('.profile-tabs', $css);
        self::assertStringContainsString('.profile-content', $css);
        self::assertStringContainsString('.profile-section', $css);
        self::assertStringContainsString('.profile-content-row', $css);
        self::assertStringContainsString('.acp-card:hover', $css);
        self::assertStringContainsString('.search-hit:hover', $css);
        self::assertStringContainsString('.account-setting-row:hover', $css);
        self::assertStringContainsString('.notification-item.is-unread', $css);
        self::assertStringContainsString('.auth-entry-card::before', $css);
        self::assertStringContainsString('.marketplace-results .market-card:hover', $css);
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
        self::assertStringContainsString('relativeToBasePath', $asset);
        self::assertStringContainsString('brandHome', $asset);
        self::assertStringContainsString('data-nav-section', $asset);
        self::assertStringContainsString('activeNavSection', $asset);
        self::assertStringContainsString('forwext-nav-open', $asset);
        self::assertStringContainsString('.nav-account-menu', $asset);
        self::assertStringNotContainsString('innerHTML', $asset);
    }

    public function testSharedShellHonorsTheProvidedVisibleNavigationRegistry(): void
    {
        $navigation = new NavigationRegistry();
        $navigation->register(new NavigationItem('forums', 'Forumlar', '/forums', 50));
        $navigation->register(new NavigationItem('custom.docs', 'Dokümanlar', '/docs', 250));

        $html = ProfileHtml::page(
            'Test',
            '<p>Body</p>',
            new BasePath('/community'),
            $navigation,
        );

        self::assertStringContainsString('href="/community/forums"', $html);
        self::assertStringContainsString('href="/community/docs"', $html);
        self::assertStringContainsString('>Diğer ', $html);
        self::assertStringNotContainsString('href="/community/marketplace"', $html);
        self::assertStringNotContainsString('href="/community/members"', $html);
        self::assertStringNotContainsString('href="/community/faq"', $html);
        self::assertStringContainsString('data-forwext-subnav-shell hidden', $html);
    }
}
