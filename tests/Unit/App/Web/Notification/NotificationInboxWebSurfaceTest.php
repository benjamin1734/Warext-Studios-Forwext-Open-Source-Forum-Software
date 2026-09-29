<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Notification\NotificationInboxHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Notification\Notification;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class NotificationInboxWebSurfaceTest extends TestCase
{
    public function testNativeInboxRouteUsesDedicatedCsrfAndPermissionAwareService(): void
    {
        $root = dirname(__DIR__, 5);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Notification/NotificationInboxHandler.php');

        self::assertStringContainsString("'account.notifications'", $factory);
        self::assertStringContainsString("new PathTemplate('/account/notifications')", $factory);
        self::assertStringContainsString('new NotificationInboxService(', $factory);
        self::assertStringContainsString('$notificationInboxCsrf', $factory);
        self::assertStringContainsString("'notification-inbox'", $factory);

        self::assertStringContainsString('$this->inbox->markRead($actor, EntityId::fromString($rawId))', $handler);
        self::assertStringContainsString("preg_match('/^[a-f0-9]{32}$/D'", $handler);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);
    }

    public function testInboxHtmlEscapesNotificationContentAndRejectsExternalActionUrl(): void
    {
        $notification = new Notification(
            EntityId::fromString(str_repeat('a', 32)),
            EntityId::fromString(str_repeat('b', 32)),
            'test.notification',
            'test',
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            'https://evil.example/path',
            [],
            true,
            1,
            new DateTimeImmutable('2026-09-27 14:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-27 14:01:00', new DateTimeZone('UTC')),
        );

        $html = NotificationInboxHtml::page(
            [$notification],
            1,
            1,
            false,
            'csrf-token',
            new BasePath('/forum'),
            new DateTimeZone('Europe/Istanbul'),
        );

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('https://evil.example/path', $html);
        self::assertStringContainsString('Okundu işaretle', $html);
    }

    public function testNavigationAndRealtimeBootstrapExposeUnreadBadgeContract(): void
    {
        $root = dirname(__DIR__, 5);
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');
        $handler = (string) file_get_contents($root . '/app/Web/Notification/NotificationRealtimeHandler.php');
        $asset = (string) file_get_contents($root . '/public/assets/notification-realtime.js');
        $css = (string) file_get_contents($root . '/public/assets/site-base.css')
            . (string) file_get_contents($root . '/public/assets/site-shell.css');

        self::assertStringContainsString("'notifications.own'", $navigation);
        self::assertStringContainsString("'/account/notifications'", $navigation);
        self::assertStringContainsString("\$payload['unread_count']", $handler);
        self::assertStringContainsString('nav-notification-badge', $asset);
        self::assertStringContainsString('data-nav-key="notifications.own"', $asset);
        self::assertStringContainsString('.nav-notification-badge', $css);
    }
}
