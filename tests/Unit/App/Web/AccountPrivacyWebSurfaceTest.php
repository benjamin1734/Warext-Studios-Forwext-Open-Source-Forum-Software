<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Account\AccountPrivacyHtml;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Presence\PresenceVisibility;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class AccountPrivacyWebSurfaceTest extends TestCase
{
    public function testPrivacySurfaceRendersProfileAndPresenceVisibilityControls(): void
    {
        $profile = new UserProfile(
            UserId::fromStored(str_repeat('a', 32)),
            'Hakkımda',
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

        $html = AccountPrivacyHtml::page(
            $profile,
            PresenceVisibility::Hidden,
            'csrf-token',
            new BasePath('/community'),
        );

        self::assertStringContainsString('action="/community/account/privacy"', $html);
        self::assertStringContainsString('name="action" value="save_profile_visibility"', $html);
        self::assertStringContainsString('name="profile_visibility"', $html);
        self::assertStringContainsString('name="about_visibility"', $html);
        self::assertStringContainsString('name="social_visibility"', $html);
        self::assertStringContainsString('name="media_visibility"', $html);
        self::assertStringContainsString('name="action" value="save_presence_visibility"', $html);
        self::assertStringContainsString('name="presence_visibility"', $html);
        self::assertStringContainsString('value="hidden" selected', $html);
        self::assertStringContainsString('/community/account/profile', $html);
        self::assertStringContainsString('/community/account/security', $html);
        self::assertStringContainsString('/community/account/relationships', $html);
        self::assertStringContainsString('name="_csrf" value="csrf-token"', $html);
    }

    public function testPrivacyRouteReusesExistingProfileAndPresenceSources(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Account/AccountPrivacyHandler.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileSettingsHandler.php');
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString("'account.privacy'", $factory);
        self::assertStringContainsString("new PathTemplate('/account/privacy')", $factory);
        self::assertStringContainsString('new AccountPrivacyHandler($viewerResolver, $profileService, $presence, $basePath)', $factory);
        self::assertStringContainsString('[$profileSettingsCsrf]', $factory);

        self::assertStringContainsString("'save_profile_visibility'", $handler);
        self::assertStringContainsString("'save_presence_visibility'", $handler);
        self::assertStringContainsString('ProfileVisibility::from(', $handler);
        self::assertStringContainsString('PresenceVisibility::from(', $handler);
        self::assertStringContainsString('$profile->about', $handler);
        self::assertStringContainsString('$profile->socialLinks', $handler);
        self::assertStringContainsString('$profile->tabs', $handler);
        self::assertStringContainsString('$this->presence->setVisibility(', $handler);

        self::assertStringNotContainsString('ProfileVisibility::from(', $profile);
        self::assertStringContainsString('$profile->profileVisibility', $profile);
        self::assertStringContainsString("'privacy.own'", $navigation);
        self::assertStringNotContainsString("'presence.own'", $navigation);
        self::assertStringContainsString('/* account-privacy-surface-v1 */', $css);
    }
}
