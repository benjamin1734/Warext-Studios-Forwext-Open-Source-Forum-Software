<?php

declare(strict_types=1);

namespace Forwext\Core\Notification;

use Closure;
use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface NotificationRepository
{
    /**
     * Serialize notification mutations for one recipient so dedupe/group decisions remain race-safe.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function withRecipientLock(EntityId $recipientUserId, Closure $callback): mixed;

    public function findByDedupe(EntityId $recipientUserId, string $dedupeKey): ?Notification;
    public function findOpenGroup(EntityId $recipientUserId, string $typeKey, string $groupKey): ?Notification;
    public function insert(Notification $notification, ?string $groupKey): void;
    /** @param array<string, scalar|null> $payload */
    public function incrementGroup(EntityId $notificationId, string $title, string $body, ?string $actionPath, array $payload, bool $inAppVisible, DateTimeImmutable $now): Notification;
    public function rememberDedupe(EntityId $recipientUserId, string $dedupeKey, EntityId $notificationId, DateTimeImmutable $now): void;
    public function preference(EntityId $userId, string $categoryKey, NotificationChannel $channel): ?bool;
    public function setPreference(EntityId $userId, string $categoryKey, NotificationChannel $channel, bool $enabled, DateTimeImmutable $now): void;
    public function queueDelivery(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void;
    /** @return list<NotificationDelivery> */
    public function dueDeliveries(DateTimeImmutable $now, int $limit): array;
    public function markDeliverySent(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void;
    public function markDeliveryFailed(EntityId $notificationId, NotificationChannel $channel, int $attempts, ?DateTimeImmutable $nextAttemptAt, string $errorCode): void;
    /** @return list<Notification> */
    public function inbox(EntityId $userId, int $limit, int $offset): array;
    public function unreadCount(EntityId $userId): int;
    public function markRead(EntityId $userId, EntityId $notificationId, DateTimeImmutable $now): bool;
}
