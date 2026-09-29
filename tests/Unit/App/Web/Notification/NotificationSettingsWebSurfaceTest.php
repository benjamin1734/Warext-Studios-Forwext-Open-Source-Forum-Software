<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Notification\NotificationSettingsHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Notification\Sound\NotificationSoundSettings;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class NotificationSettingsWebSurfaceTest extends TestCase
{
    public function testFactoryRegistersNativeSettingsWithExistingNotificationSoundCsrf(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Notification/NotificationSettingsHandler.php');

        self::assertStringContainsString("'account.notification-settings'", $factory);
        self::assertStringContainsString("new PathTemplate('/account/notification-settings')", $factory);
        self::assertStringContainsString('new NotificationSettingsHandler(', $factory);
        self::assertStringContainsString('$notificationSoundCsrf', $factory);
        self::assertStringContainsString('$this->sounds->updateSettings(', $handler);
        self::assertStringContainsString("\$volume < 0 || \$volume > 100", $handler);
        self::assertStringContainsString("preg_match('/^[a-z][a-z0-9_-]{1,31}$/D'", $handler);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);
    }

    public function testSettingsHtmlRendersPresetSelectionAndEscapesLabels(): void
    {
        $settings = new NotificationSoundSettings(
            EntityId::fromString(str_repeat('a', 32)),
            false,
            65,
            'soft',
            new DateTimeImmutable('2026-09-27 14:00:00', new DateTimeZone('UTC')),
        );

        $html = NotificationSettingsHtml::page(
            $settings,
            ['soft' => 'Soft', 'chime' => '<Chime>'],
            'csrf-token',
            new BasePath('/forum'),
            true,
        );

        self::assertStringContainsString('Bildirim ayarları kaydedildi.', $html);
        self::assertStringContainsString('value="65"', $html);
        self::assertStringContainsString('value="soft" selected', $html);
        self::assertStringContainsString('&lt;Chime&gt;', $html);
        self::assertStringContainsString('/forum/account/notifications', $html);
        self::assertStringContainsString('data-notification-preview', $html);
        self::assertStringContainsString('surface-head notification-settings-head', $html);
        self::assertStringContainsString('notification-settings-form surface-panel', $html);
    }

    public function testSettingsClientUsesExistingSoundPreviewApiWithoutHtmlInjection(): void
    {
        $root = dirname(__DIR__, 5);
        $asset = (string) file_get_contents($root . '/public/assets/notification-settings.js');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-base.css')
            . (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString('ForwextNotificationSound?.preview', $asset);
        self::assertStringContainsString('ForwextNotificationSound?.load', $asset);
        self::assertStringContainsString('data-notification-volume', $asset);
        self::assertStringNotContainsString('innerHTML', $asset);
        self::assertStringContainsString('/assets/notification-settings.js', $profile);
        self::assertStringContainsString('.notification-settings-form', $css);
    }
}
