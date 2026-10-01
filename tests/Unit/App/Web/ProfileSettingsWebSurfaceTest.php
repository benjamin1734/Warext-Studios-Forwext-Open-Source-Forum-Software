<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use Forwext\App\Web\Profile\ProfileSettingsHtml;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ProfileSettingsWebSurfaceTest extends TestCase
{
    public function testFactoryRegistersCsrfProtectedProfileSettingsWorkflow(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Profile/ProfileSettingsHandler.php');

        self::assertStringContainsString("'account.profile-settings'", $factory);
        self::assertStringContainsString("new PathTemplate('/account/profile')", $factory);
        self::assertStringContainsString('new ProfileSettingsHandler($users, $profileService, $mediaService, $viewerResolver, $basePath)', $factory);
        self::assertStringContainsString('$profileSettingsCsrf', $factory);
        self::assertStringContainsString("'profile-settings'", $factory);
        self::assertStringContainsString("'forwext.csrf.profile-settings.v1'", $factory);

        self::assertStringContainsString('ProfileVisibility::from($profileVisibility)', $handler);
        self::assertStringContainsString('$profile->socialLinks', $handler);
        self::assertStringContainsString('$profile->tabs', $handler);
        self::assertStringContainsString('ProfileMediaKind::Avatar', $handler);
        self::assertStringContainsString('ProfileMediaKind::Banner', $handler);
        self::assertStringContainsString('is_uploaded_file($file->temporaryPath)', $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);
    }

    public function testProfileSettingsHtmlExposesPrivacyAboutAndMediaControls(): void
    {
        $profile = new UserProfile(
            UserId::fromStored(str_repeat('a', 32)),
            'Profil hakkında',
            null,
            null,
            ProfileVisibility::Members,
            ProfileVisibility::Private,
            ProfileVisibility::Public,
            ProfileVisibility::Members,
            [],
            ProfileService::defaultTabs(),
            new DateTimeImmutable('2026-09-30 10:00:00', new DateTimeZone('UTC')),
        );

        $html = ProfileSettingsHtml::page(
            $profile,
            'forwext-user',
            'csrf-token',
            new BasePath('/community'),
        );

        self::assertStringContainsString('/community/account/profile', $html);
        self::assertStringContainsString('/community/members/forwext-user', $html);
        self::assertStringContainsString('name="about"', $html);
        self::assertStringContainsString('name="profile_visibility"', $html);
        self::assertStringContainsString('name="about_visibility"', $html);
        self::assertStringContainsString('name="social_visibility"', $html);
        self::assertStringContainsString('name="media_visibility"', $html);
        self::assertStringContainsString('value="members" selected', $html);
        self::assertStringContainsString('value="private" selected', $html);
        self::assertStringContainsString('value="upload_avatar"', $html);
        self::assertStringContainsString('value="upload_banner"', $html);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
    }

    public function testAccountAndOwnProfileExposeProfileSettingsEntryPoints(): void
    {
        $root = dirname(__DIR__, 4);
        $dashboard = (string) file_get_contents($root . '/app/Web/Account/AccountDashboardHtml.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileViewHandler.php');
        $css = (string) file_get_contents($root . '/public/assets/site-components.css')
            . (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString("'/account/profile'", $dashboard);
        self::assertStringContainsString('Profil ve Gizlilik', $dashboard);
        self::assertStringContainsString("prepend('/account/profile')", $profile);
        self::assertStringContainsString('Profil ve gizlilik', $profile);
        self::assertStringContainsString('.profile-settings-layout{', $css);
        self::assertStringContainsString('.profile-media-setting-card{', $css);
    }
}
