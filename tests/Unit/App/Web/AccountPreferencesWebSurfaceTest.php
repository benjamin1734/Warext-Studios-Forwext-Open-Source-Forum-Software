<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Account\AccountPreferencesHtml;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Notification\Sound\NotificationSoundSettings;
use Forwext\Core\Presence\PresenceVisibility;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class AccountPreferencesWebSurfaceTest extends TestCase
{
    public function testFactoryRegistersDedicatedCsrfProtectedPreferenceHub(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Account/AccountPreferencesHandler.php');

        self::assertStringContainsString("'account.preferences'", $factory);
        self::assertStringContainsString("new PathTemplate('/account/preferences')", $factory);
        self::assertStringContainsString("new PathTemplate('/account/presence')", $factory);
        self::assertStringContainsString('new AccountPreferencesHandler(', $factory);
        self::assertStringContainsString('$accountPreferencesCsrf', $factory);
        self::assertStringContainsString("'account-preferences'", $factory);
        self::assertStringContainsString("'forwext.csrf.account-preferences.v1'", $factory);

        self::assertStringContainsString('PresenceVisibility::from($value)', $handler);
        self::assertStringContainsString('$this->presence->setVisibility($actor', $handler);
        self::assertStringContainsString('$this->profiles->getOrDefault($actor', $handler);
        self::assertStringContainsString('$this->notificationSounds->settings($actor', $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);
        self::assertStringNotContainsString('INSERT INTO', $handler);
        self::assertStringNotContainsString('UPDATE ', $handler);
    }

    public function testPreferenceHubSummarizesExistingSettingsAndPostsOnlyPresence(): void
    {
        $userId = UserId::fromStored(str_repeat('b', 32));
        $profile = new UserProfile(
            $userId,
            '',
            null,
            null,
            ProfileVisibility::Members,
            ProfileVisibility::Private,
            ProfileVisibility::Public,
            ProfileVisibility::Members,
            [],
            ProfileService::defaultTabs(),
            new DateTimeImmutable('2026-10-04 10:00:00', new DateTimeZone('UTC')),
        );
        $notifications = new NotificationSoundSettings(
            $userId,
            false,
            72,
            'soft',
            new DateTimeImmutable('2026-10-04 10:00:00', new DateTimeZone('UTC')),
        );

        $html = AccountPreferencesHtml::page(
            $profile,
            PresenceVisibility::Members,
            $notifications,
            'csrf-token',
            new BasePath('/community'),
            true,
        );

        self::assertStringContainsString('Tercihler ve Gizlilik', $html);
        self::assertStringContainsString('/community/account/preferences', $html);
        self::assertStringContainsString('/community/account/profile', $html);
        self::assertStringContainsString('/community/account/notification-settings', $html);
        self::assertStringContainsString('/community/account/security', $html);
        self::assertStringContainsString('/community/account/sessions', $html);
        self::assertStringContainsString('/community/account/relationships', $html);
        self::assertStringContainsString('name="action" value="save_presence"', $html);
        self::assertStringContainsString('name="presence_visibility"', $html);
        self::assertStringContainsString('id="presence"', $html);
        self::assertStringContainsString('value="members" selected', $html);
        self::assertStringContainsString('72%', $html);
        self::assertStringContainsString('soft', $html);
        self::assertStringContainsString('Çevrimiçi görünürlük tercihin kaydedildi.', $html);
    }

    public function testPreferenceHubHasResponsiveAccountNavigationEntryPoints(): void
    {
        $root = dirname(__DIR__, 4);
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');
        $profileHtml = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $dashboard = (string) file_get_contents($root . '/app/Web/Account/AccountDashboardHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-components.css');

        self::assertStringContainsString("'preferences.own'", $navigation);
        self::assertStringContainsString("'/account/preferences'", $navigation);
        self::assertStringContainsString("'preferences.own'", $profileHtml);
        self::assertStringContainsString("'/account/preferences'", $dashboard);
        self::assertStringContainsString('.account-preferences-grid{', $css);
        self::assertStringContainsString('@media(max-width:820px)', $css);
    }
}
