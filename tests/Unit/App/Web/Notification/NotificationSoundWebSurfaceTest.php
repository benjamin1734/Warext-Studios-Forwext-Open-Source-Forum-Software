<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Notification;

use PHPUnit\Framework\TestCase;

final class NotificationSoundWebSurfaceTest extends TestCase
{
    public function testFactoryRegistersAuthenticatedSoundSettingsAndDedicatedCsrfScope(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        self::assertStringContainsString('/account/notification-sound/csrf', $factory);
        self::assertStringContainsString('/account/notification-sound/categories/{categoryKey}', $factory);
        self::assertStringContainsString("'notification-sound', 'forwext.csrf.notification-sound.v1'", $factory);
        self::assertStringContainsString('EngineNotificationSoundPermissionResolver', $factory);
    }

    public function testPlaybackAssetRequiresUserInteractionAndUsesNoRemoteAudioUrls(): void
    {
        $root = dirname(__DIR__, 5);
        $script = (string) file_get_contents($root . '/public/assets/notification-sound.js');
        self::assertStringContainsString("window.addEventListener('pointerdown', unlock", $script);
        self::assertStringContainsString("window.addEventListener('keydown', unlock", $script);
        self::assertStringContainsString('AudioContext', $script);
        self::assertStringContainsString('if (!state.unlocked', $script);
        self::assertStringNotContainsString('new Audio(', $script);
        self::assertStringNotContainsString('http://', $script);
        self::assertStringNotContainsString('https://', $script);
    }
}
