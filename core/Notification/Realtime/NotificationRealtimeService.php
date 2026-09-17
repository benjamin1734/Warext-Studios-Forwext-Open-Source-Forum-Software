<?php

declare(strict_types=1);

namespace Forwext\Core\Notification\Realtime;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Realtime\RealtimeEnvelope;
use Forwext\Core\Realtime\RealtimeTransport;
use JsonException;
use Throwable;

final readonly class NotificationRealtimeService
{
    public function __construct(
        private RealtimeTransport $transport,
        private NotificationRealtimeReader $reader,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function bootstrap(EntityId $userId): NotificationRealtimeBatch
    {
        $this->requireViewPermission($userId);
        $channel = NotificationRealtimeChannel::forUser($userId);
        return new NotificationRealtimeBatch($this->reader->latestSequence($channel), []);
    }

    public function read(EntityId $userId, int $afterSequence, int $limit = 100): NotificationRealtimeBatch
    {
        $this->requireViewPermission($userId);
        if ($afterSequence < 0 || $limit < 1 || $limit > 100) {
            throw new NotificationRealtimeException('Notification realtime cursor or limit is invalid.');
        }

        $channel = NotificationRealtimeChannel::forUser($userId);
        try {
            $envelopes = $this->transport->readAfter($channel, $afterSequence, $limit);
        } catch (Throwable $exception) {
            throw new NotificationRealtimeException('Notification realtime transport read failed.', previous: $exception);
        }

        $cursor = $afterSequence;
        /** @var array<string, int> $latestSequenceByNotification */
        $latestSequenceByNotification = [];
        /** @var array<string, EntityId> $ids */
        $ids = [];

        foreach ($envelopes as $envelope) {
            if (!$envelope instanceof RealtimeEnvelope) {
                throw new NotificationRealtimeException('Notification realtime transport returned an invalid envelope.');
            }
            $cursor = max($cursor, $envelope->sequence);
            if ($envelope->message->event !== NotificationRealtimePublisher::EVENT) {
                continue;
            }
            $id = $this->notificationIdFromPayload($envelope->message->payload);
            if ($id === null) {
                continue;
            }
            $key = $id->value();
            $ids[$key] = $id;
            $latestSequenceByNotification[$key] = max($latestSequenceByNotification[$key] ?? 0, $envelope->sequence);
        }

        if ($ids === []) {
            return new NotificationRealtimeBatch($cursor, []);
        }

        $snapshots = $this->reader->visibleByIds($userId, array_values($ids));
        $items = [];
        foreach ($latestSequenceByNotification as $notificationId => $sequence) {
            $snapshot = $snapshots[$notificationId] ?? null;
            if ($snapshot !== null) {
                $items[] = new NotificationRealtimeItem($sequence, $snapshot);
            }
        }
        usort(
            $items,
            static fn (NotificationRealtimeItem $left, NotificationRealtimeItem $right): int => $left->sequence <=> $right->sequence,
        );

        return new NotificationRealtimeBatch($cursor, $items);
    }

    private function requireViewPermission(EntityId $userId): void
    {
        UserId::assert($userId);
        if (!$this->authorizer->allows($userId, PermissionKey::fromString('notification.alert.view'))) {
            throw new PermissionDeniedException('Permission "notification.alert.view" is required.');
        }
    }

    private function notificationIdFromPayload(string $payload): ?EntityId
    {
        if (strlen($payload) > 512) {
            return null;
        }
        try {
            $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded) || !is_string($decoded['notification_id'] ?? null)) {
            return null;
        }
        try {
            return EntityId::fromString($decoded['notification_id']);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
