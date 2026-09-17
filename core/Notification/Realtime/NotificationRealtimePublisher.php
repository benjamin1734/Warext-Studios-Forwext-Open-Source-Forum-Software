<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Realtime;

use DateTimeImmutable;
use Forwext\Core\Notification\Notification;
use Forwext\Core\Realtime\RealtimeMessage;
use Forwext\Core\Realtime\RealtimeTransport;
use JsonException;

final readonly class NotificationRealtimePublisher
{
    public const EVENT = 'notification.changed';

    public function __construct(private RealtimeTransport $transport)
    {
    }

    public function publish(Notification $notification, DateTimeImmutable $now): void
    {
        if (!$notification->inAppVisible) {
            return;
        }

        try {
            $payload = json_encode(
                ['notification_id' => $notification->id->value()],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new NotificationRealtimeException('Unable to encode notification wake payload.', previous: $exception);
        }

        try {
            $this->transport->publish(new RealtimeMessage(
                NotificationRealtimeChannel::forUser($notification->recipientUserId),
                self::EVENT,
                $payload,
                $now,
            ));
        } catch (\Throwable $exception) {
            throw new NotificationRealtimeException('Unable to publish notification wake event.', previous: $exception);
        }
    }
}
