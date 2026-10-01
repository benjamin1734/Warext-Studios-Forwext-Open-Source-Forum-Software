<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class FrontendCssLayeringTest extends TestCase
{
    public function testNativeShellUsesExplicitBaseComponentAndPageLayers(): void
    {
        $root = dirname(__DIR__, 4);
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        foreach (['site-base.css', 'site-components.css', 'site-pages.css'] as $asset) {
            self::assertFileExists($root . '/public/assets/' . $asset);
            self::assertNotSame('', trim((string) file_get_contents($root . '/public/assets/' . $asset)));
        }

        self::assertFileDoesNotExist($root . '/public/assets/site-shell.css');

        $base = strpos($profile, "/assets/site-base.css");
        $components = strpos($profile, "/assets/site-components.css");
        $pages = strpos($profile, "/assets/site-pages.css");

        self::assertIsInt($base);
        self::assertIsInt($components);
        self::assertIsInt($pages);
        self::assertLessThan($components, $base);
        self::assertLessThan($pages, $components);

        self::assertStringContainsString('Forwext base UI layer', (string) file_get_contents($root . '/public/assets/site-base.css'));
        self::assertStringContainsString('Forwext component UI layer', (string) file_get_contents($root . '/public/assets/site-components.css'));
        self::assertStringContainsString('Forwext page UI layer', (string) file_get_contents($root . '/public/assets/site-pages.css'));
    }

    public function testSharedShellSelectorsAreNotOwnedByTheBaseLayer(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $components = (string) file_get_contents($root . '/public/assets/site-components.css');

        self::assertStringContainsString('/* shell-ownership-v2 */', $components);
        self::assertStringContainsString('/* shell-layout-ownership-v2 */', $components);

        foreach (['top', 'topin', 'brand', 'wrap', 'breadcrumbs', 'layout-shell', 'layout-sidebar', 'site-footer', 'footerin', 'core-brand-footer', 'ui-page-slot'] as $class) {
            self::assertSame(
                0,
                self::rootClassDefinitionCount($base, $class),
                sprintf('%s must not be defined in the base layer.', $class),
            );
            self::assertGreaterThan(
                0,
                self::rootClassDefinitionCount($components, $class),
                sprintf('%s must be owned by the component layer.', $class),
            );
        }

        self::assertSame(0, self::rootClassDefinitionCount($base, 'nav'));
    }

    public function testProfileFoundationIsNotOwnedByTheBaseLayer(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $components = (string) file_get_contents($root . '/public/assets/site-components.css');

        self::assertStringContainsString('/* profile-foundation-ownership-v2 */', $components);
        self::assertStringContainsString('/* profile-mobile-foundation-v2 */', $components);
        self::assertStringNotContainsString('.account-center-card,}', $base);

        foreach (['profile', 'banner', 'profilebody', 'profilehead', 'profile-relationship-actions', 'tabs', 'profile-wall'] as $class) {
            self::assertSame(0, self::rootClassDefinitionCount($base, $class));
            self::assertGreaterThan(0, self::rootClassDefinitionCount($components, $class));
        }
    }

    public function testMarketplaceTrophyAndBugFabAreNotOwnedByTheBaseLayer(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $components = (string) file_get_contents($root . '/public/assets/site-components.css');

        self::assertStringContainsString('/* marketplace-and-trophy-foundation-v2 */', $components);
        self::assertStringContainsString('/* bug-report-fab-foundation-v2 */', $components);

        foreach (['market-card', 'market-grid', 'trophy-card', 'trophy-banner', 'bug-report-fab'] as $class) {
            self::assertSame(0, self::rootClassDefinitionCount($base, $class));
            self::assertGreaterThan(0, self::rootClassDefinitionCount($components, $class));
        }
    }

    public function testSearchFoundationIsNotOwnedByTheBaseLayer(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $components = (string) file_get_contents($root . '/public/assets/site-components.css');
        $pages = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('/* search-foundation-ownership-v2 */', $components);

        foreach (['search-form', 'search-actions', 'search-hit', 'search-alert'] as $class) {
            self::assertSame(0, self::rootClassDefinitionCount($base, $class));
            self::assertGreaterThan(0, self::rootClassDefinitionCount($components . $pages, $class));
        }

        self::assertSame(0, self::rootClassDefinitionCount($base, 'presence-settings'));
        self::assertGreaterThan(0, self::rootClassDefinitionCount($pages, 'presence-settings'));
    }

    public function testNotificationSurfaceIsNotOwnedByTheBaseLayer(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $components = (string) file_get_contents($root . '/public/assets/site-components.css');
        $pages = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('/* notification-surface-ownership-v2 */', $components);
        self::assertStringNotContainsString('.notification-hero{', $base);
        self::assertStringNotContainsString('.notification-settings-hero{', $base);

        foreach (['notification-item', 'notification-unread', 'notification-settings-form', 'notification-settings-notice'] as $class) {
            self::assertSame(0, self::rootClassDefinitionCount($base, $class));
            self::assertGreaterThan(0, self::rootClassDefinitionCount($components . $pages, $class));
        }
    }

    public function testAccountSocialListsAreOwnedByThePageLayer(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $pages = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('/* account-social-lists-ownership-v2 */', $pages);

        foreach (['relationship-panel', 'relationship-row', 'bookmark-card', 'activity-feed-item'] as $class) {
            self::assertSame(
                0,
                self::rootClassDefinitionCount($base, $class),
                sprintf('%s must not be defined in the base layer.', $class),
            );
            self::assertGreaterThan(
                0,
                self::rootClassDefinitionCount($pages, $class),
                sprintf('%s must be owned by the page layer.', $class),
            );
        }
    }

    public function testAuthSurfaceIsNotOwnedByTheBaseLayer(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $components = (string) file_get_contents($root . '/public/assets/site-components.css');

        self::assertStringContainsString('/* auth-surface-ownership-v2 */', $components);

        foreach (['auth-entry', 'auth-entry-card', 'auth-entry-form', 'auth-entry-check', 'auth-entry-challenge', 'auth-entry-links', 'auth-entry-submit', 'auth-entry-actions'] as $class) {
            self::assertSame(
                0,
                self::rootClassDefinitionCount($base, $class),
                sprintf('%s must not be defined in the base layer.', $class),
            );
            self::assertGreaterThan(
                0,
                self::rootClassDefinitionCount($components, $class),
                sprintf('%s must be owned by the component layer.', $class),
            );
        }
    }

    public function testForumAndThreadSurfacesAreNotOwnedByTheBaseLayer(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $components = (string) file_get_contents($root . '/public/assets/site-components.css');
        $pages = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('/* forum-layer-ownership-v2 */', $pages);

        foreach (['forum-home-layout', 'forum-node', 'forum-thread-row', 'thread-post', 'thread-post-author'] as $class) {
            self::assertSame(
                0,
                self::rootClassDefinitionCount($base, $class),
                sprintf('%s must not be defined in the base layer.', $class),
            );
            self::assertGreaterThan(
                0,
                self::rootClassDefinitionCount($components . $pages, $class),
                sprintf('%s must be owned by component/page layers.', $class),
            );
        }
    }

    public function testBaseLayerIsBalancedAndAccountStatisticsArePageOwned(): void
    {
        $root = dirname(__DIR__, 4);
        $base = (string) file_get_contents($root . '/public/assets/site-base.css');
        $components = (string) file_get_contents($root . '/public/assets/site-components.css');
        $pages = (string) file_get_contents($root . '/public/assets/site-pages.css');

        foreach ([$base, $components, $pages] as $css) {
            self::assertSame(
                substr_count($css, '{'),
                substr_count($css, '}'),
                'Every frontend CSS layer must have balanced braces.',
            );
        }

        self::assertStringContainsString('/* account-and-statistics-page-ownership-v2 */', $pages);

        foreach (['account-center', 'stats-grid', 'stat'] as $class) {
            self::assertSame(
                0,
                self::rootClassDefinitionCount($base, $class),
                sprintf('%s must not be defined in the base layer.', $class),
            );
            self::assertGreaterThan(
                0,
                self::rootClassDefinitionCount($pages, $class),
                sprintf('%s must be owned by the page layer.', $class),
            );
        }

        foreach (['account-center-hero', 'account-center-grid', 'account-center-card'] as $class) {
            self::assertSame(
                0,
                self::rootClassDefinitionCount($base . $components . $pages, $class),
                sprintf('%s is obsolete compatibility CSS and must stay removed.', $class),
            );
        }
    }

    private static function rootClassDefinitionCount(string $css, string $class): int
    {
        preg_match_all(
            '/(?:^|})\\s*\\.' . preg_quote($class, '/') . '\\s*\\{/m',
            $css,
            $matches,
        );

        return count($matches[0] ?? []);
    }
}
