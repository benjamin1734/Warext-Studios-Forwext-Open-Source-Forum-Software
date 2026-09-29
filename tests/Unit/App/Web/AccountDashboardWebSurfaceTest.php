<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use Forwext\App\Web\Account\AccountDashboardHtml;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class AccountDashboardWebSurfaceTest extends TestCase
{
    public function testFactoryRegistersAuthenticatedNativeAccountCenter(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Account/AccountDashboardHandler.php');

        self::assertStringContainsString("'account.index'", $factory);
        self::assertStringContainsString("new PathTemplate('/account')", $factory);
        self::assertStringContainsString('new AccountDashboardHandler(', $factory);
        self::assertStringContainsString('resolve($request) === null', $handler);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);
    }

    public function testAccountCenterLinksExistingNativeAccountSurfaces(): void
    {
        $html = AccountDashboardHtml::page(new BasePath('/community'));

        foreach ([
            '/community/account/notifications',
            '/community/account/notification-settings',
            '/community/account/profile-url',
            '/community/account/profile-activity',
            '/community/account/bookmarks',
            '/community/account/referrals',
            '/community/account/upgrades',
            '/community/account/spellcheck-dictionary',
            '/community/account/discipline',
            '/community/bugs',
        ] as $path) {
            self::assertStringContainsString($path, $html);
        }
        self::assertStringContainsString('Hesabım', $html);
        self::assertStringContainsString('account-center-grid', $html);
    }

    public function testMemberNavigationExposesAccountCenterWithoutChangingAnonymousNavigation(): void
    {
        $root = dirname(__DIR__, 4);
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');
        $css = (string) file_get_contents($root . '/public/assets/site-base.css')
            . (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString("'account.own'", $navigation);
        self::assertStringContainsString("'Hesabım'", $navigation);
        self::assertStringContainsString("NavigationAudience::Member", $navigation);
        self::assertStringContainsString('.account-center-grid', $css);
    }
}
