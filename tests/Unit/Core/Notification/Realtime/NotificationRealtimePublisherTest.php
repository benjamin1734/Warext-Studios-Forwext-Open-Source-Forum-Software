<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Notification\Realtime;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Notification\Notification;
use Forwext\Core\Notification\Realtime\NotificationRealtimeChannel;
use Forwext\Core\Notification\Realtime\NotificationRealtimePublisher;
use Forwext\Core\Realtime\RealtimeEnvelope;
use Forwext\Core\Realtime\RealtimeMessage;
use Forwext\Core\Realtime\RealtimeMode;
use Forwext\Core\Realtime\RealtimeTransport;
use PHPUnit\Framework\TestCase;

final class NotificationRealtimePublisherTest extends TestCase
{
    public function testPublisherUsesRecipientScopedChannelAndMinimalWakePayload(): void
    {
        $transport = new RealtimeCaptureTransport();
        $publisher = new NotificationRealtimePublisher($transport);
        $userId = UserId::fromStored(str_repeat('a', 32));
        $now = new DateTimeImmutable('2026-09-17 13:00:00', new DateTimeZone('UTC'));
        $notification = new Notification(
            EntityId::fromString(str_repeat('b', 32)), $userId, 'forum.reply', 'forum', 'Secret title', 'Secret body',
            '/threads/1', ['private' => 'value'], true, 1, $now, $now,
        );

        $publisher->publish($notification, $now);

        self::assertNotNull($transport->published);
        self::assertSame(NotificationRealtimeChannel::forUser($userId), $transport->published->channel);
        self::assertSame(NotificationRealtimePublisher::EVENT, $transport->published->event);
        self::assertSame(['notification_id' => str_repeat('b', 32)], json_decode($transport->published->payload, true, 16, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Secret title', $transport->published->payload);
        self::assertStringNotContainsString('Secret body', $transport->published->payload);
        self::assertStringNotContainsString('/threads/1', $transport->published->payload);
    }

    public function testEmailOnlyNotificationDoesNotPublishBrowserWake(): void
    {
        $transport = new RealtimeCaptureTransport();
        $publisher = new NotificationRealtimePublisher($transport);
        $now = new DateTimeImmutable('2026-09-17 13:00:00', new DateTimeZone('UTC'));
        $publisher->publish(new Notification(
            EntityId::fromString(str_repeat('c', 32)), UserId::fromStored(str_repeat('d', 32)),
            'mail.only', 'mail', 'Mail', 'Body', null, [], false, 1, $now, $now,
        ), $now);
        self::assertNull($transport->published);
    }
}

final class RealtimeCaptureTransport implements RealtimeTransport
{
    public ?RealtimeMessage $published = null;
    public function mode(): RealtimeMode { return RealtimeMode::Polling; }
    public function publish(RealtimeMessage $message): RealtimeEnvelope
    {
        $this->published = $message;
        return new RealtimeEnvelope(1, $message);
    }
    public function readAfter(string $channel, int $afterSequence, int $limit = 100): array { return []; }
}
