<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Notification;

use PHPUnit\Framework\TestCase;

final class NotificationRealtimeWebSurfaceTest extends TestCase
{
    public function testFactoryRegistersActorScopedPollingAndSseWithoutRealtimeCsrfMutationSurface(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        self::assertStringContainsString("'/account/notifications/realtime'", $factory);
        self::assertStringContainsString("'/account/notifications/realtime/sse'", $factory);
        self::assertStringContainsString('DatabaseRealtimeMessageStore', $factory);
        self::assertStringContainsString('PollingRealtimeTransport', $factory);
        self::assertStringContainsString("connect-src 'self'", $factory);
        self::assertStringNotContainsString("notification-realtime', 'forwext.csrf", $factory);
    }

    public function testClientFallsBackWebSocketToSseToPollingAndKeepsSoundSupplementary(): void
    {
        $root = dirname(__DIR__, 5);
        $script = (string) file_get_contents($root . '/public/assets/notification-realtime.js');
        self::assertStringContainsString('new WebSocket(', $script);
        self::assertStringContainsString('new EventSource(', $script);
        self::assertStringContainsString('startPolling()', $script);
        self::assertStringContainsString("CustomEvent('forwext:notification'", $script);
        self::assertStringContainsString('ForwextNotificationSound?.play', $script);
        self::assertStringContainsString('textContent =', $script);
        self::assertStringNotContainsString('innerHTML =', $script);
    }

    public function testProfileFrontendLoadsSoundBeforeRealtimeClient(): void
    {
        $root = dirname(__DIR__, 5);
        $html = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $sound = strpos($html, 'notification-sound.js');
        $realtime = strpos($html, 'notification-realtime.js');
        self::assertIsInt($sound);
        self::assertIsInt($realtime);
        self::assertLessThan($realtime, $sound);
    }

    public function testDispatcherPublishesOnlyChangedInAppNotificationsAndContainsRealtimeFailure(): void
    {
        $root = dirname(__DIR__, 5);
        $dispatcher = (string) file_get_contents($root . '/core/Notification/NotificationDispatcher.php');
        self::assertStringContainsString('NotificationRealtimePublisher', $dispatcher);
        self::assertStringContainsString('$changed && $notification !== null', $dispatcher);
        self::assertStringContainsString('catch (NotificationRealtimeException)', $dispatcher);
        self::assertStringContainsString('$changed = true;', $dispatcher);
    }
}
