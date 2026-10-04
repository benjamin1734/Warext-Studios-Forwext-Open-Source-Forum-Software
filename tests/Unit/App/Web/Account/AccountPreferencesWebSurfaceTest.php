<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Account;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Account\AccountPreferencesHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Notification\Sound\NotificationSoundSettings;
use Forwext\Core\Presence\PresenceVisibility;
use Forwext\Core\Profile\Activity\ProfileActivityScope;
use Forwext\Core\Profile\Activity\ProfileActivitySettings;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class AccountPreferencesWebSurfaceTest extends TestCase
{
    public function testPreferencesSummaryLinksOnlyToRealExistingSettingsSurfaces(): void
    {
        $userId = EntityId::fromString(str_repeat('a', 32));
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
            [],
            new DateTimeImmutable('2026-10-04T10:00:00+00:00'),
        );
        $activity = new ProfileActivitySettings(
            ProfileActivityScope::Followers,
            ProfileActivityScope::OwnerOnly,
        );
        $notifications = new NotificationSoundSettings(
            $userId,
            false,
            42,
            'soft',
            new DateTimeImmutable('2026-10-04T10:00:00+00:00'),
        );

        $html = AccountPreferencesHtml::page(
            $profile,
            $activity,
            PresenceVisibility::Members,
            $notifications,
            new BasePath('/community'),
        );

        self::assertStringContainsString('<h1>Tercihler ve Gizlilik</h1>', $html);
        self::assertStringContainsString('href="/community/account/profile"', $html);
        self::assertStringContainsString('href="/community/account/profile-activity"', $html);
        self::assertStringContainsString('href="/community/members/online#presence-settings"', $html);
        self::assertStringContainsString('href="/community/account/notification-settings"', $html);
        self::assertStringContainsString('href="/community/account/spellcheck-dictionary"', $html);
        self::assertStringContainsString('Takipçiler', $html);
        self::assertStringContainsString('Yalnız ben', $html);
        self::assertStringContainsString('42%', $html);
        self::assertStringNotContainsString('disabled', $html);
    }

    public function testPreferencesRouteReadsExistingServicesInsteadOfCreatingDuplicateState(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Account/AccountPreferencesHandler.php');
        $dashboard = (string) file_get_contents($root . '/app/Web/Account/AccountDashboardHtml.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');

        self::assertStringContainsString("new PathTemplate('/account/preferences')", $factory);
        self::assertStringContainsString('new AccountPreferencesHandler(', $factory);
        self::assertStringContainsString('$this->profiles->getOrDefault(', $handler);
        self::assertStringContainsString('$this->activity->settings(', $handler);
        self::assertStringContainsString('$this->presence->visibility(', $handler);
        self::assertStringContainsString('$this->notifications->settings(', $handler);
        self::assertStringNotContainsString('INSERT INTO', $handler);
        self::assertStringNotContainsString('UPDATE ', $handler);

        self::assertStringContainsString("'/account/preferences'", $dashboard);
        self::assertStringContainsString("'preferences.own'", $profile);
        self::assertStringContainsString("'preferences.own'", $navigation);
    }
}
