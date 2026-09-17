<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Notification\Notification;
use Forwext\Core\Notification\NotificationChannel;
use Forwext\Core\Notification\NotificationChannelTransport;
use Forwext\Core\Notification\NotificationDelivery;
use Forwext\Core\Notification\NotificationDeliveryResult;
use Forwext\Core\Notification\NotificationDeliveryWorker;
use Forwext\Core\Notification\NotificationRepository;
use PHPUnit\Framework\TestCase;

final class NotificationDeliveryWorkerTest extends TestCase
{
    public function testRetryableFailureUsesBackoffAndOnlyStoresSafeErrorCode(): void
    {
        $repository = new WorkerMemoryRepository();
        $repository->deliveries = [new NotificationDelivery($this->notification(), NotificationChannel::Email, 0)];
        $worker = new NotificationDeliveryWorker($repository, [new FailingEmailTransport()]);
        $now = new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC'));

        self::assertSame(1, $worker->run(10, $now));
        self::assertSame(1, $repository->failedAttempts);
        self::assertSame('provider_unavailable', $repository->errorCode);
        self::assertSame('2026-09-17 12:00:30.000000', $repository->nextAttemptAt?->format('Y-m-d H:i:s.u'));
    }

    public function testTransportExceptionIsContainedAndRetried(): void
    {
        $repository = new WorkerMemoryRepository();
        $repository->deliveries = [new NotificationDelivery($this->notification(), NotificationChannel::Push, 2)];
        $worker = new NotificationDeliveryWorker($repository, [new ThrowingPushTransport()]);
        $now = new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC'));

        $worker->run(10, $now);

        self::assertSame(3, $repository->failedAttempts);
        self::assertSame('transport_exception', $repository->errorCode);
        self::assertSame('2026-09-17 12:02:00.000000', $repository->nextAttemptAt?->format('Y-m-d H:i:s.u'));
    }

    private function notification(): Notification
    {
        $now = new DateTimeImmutable('2026-09-17 11:59:00', new DateTimeZone('UTC'));
        return new Notification(
            EntityId::fromString(str_repeat('1', 32)), UserId::fromStored(str_repeat('2', 32)),
            'forum.reply', 'forum', 'Reply', 'A user replied.', '/threads/1', [], true, 1, $now, $now,
        );
    }
}

final class FailingEmailTransport implements NotificationChannelTransport
{
    public function channel(): NotificationChannel { return NotificationChannel::Email; }
    public function deliver(Notification $notification): NotificationDeliveryResult { return NotificationDeliveryResult::failed('provider_unavailable'); }
}

final class ThrowingPushTransport implements NotificationChannelTransport
{
    public function channel(): NotificationChannel { return NotificationChannel::Push; }
    public function deliver(Notification $notification): NotificationDeliveryResult { throw new \RuntimeException('secret provider response'); }
}

final class WorkerMemoryRepository implements NotificationRepository
{
    /** @var list<NotificationDelivery> */ public array $deliveries = [];
    public int $failedAttempts = 0;
    public ?DateTimeImmutable $nextAttemptAt = null;
    public ?string $errorCode = null;

    public function findByDedupe(EntityId $recipientUserId, string $dedupeKey): ?Notification { return null; }
    public function findOpenGroup(EntityId $recipientUserId, string $typeKey, string $groupKey): ?Notification { return null; }
    public function insert(Notification $notification, ?string $groupKey): void {}
    public function incrementGroup(EntityId $notificationId, string $title, string $body, ?string $actionPath, array $payload, bool $inAppVisible, DateTimeImmutable $now): Notification { throw new \LogicException('Not used.'); }
    public function rememberDedupe(EntityId $recipientUserId, string $dedupeKey, EntityId $notificationId, DateTimeImmutable $now): void {}
    public function preference(EntityId $userId, string $categoryKey, NotificationChannel $channel): ?bool { return null; }
    public function setPreference(EntityId $userId, string $categoryKey, NotificationChannel $channel, bool $enabled, DateTimeImmutable $now): void {}
    public function queueDelivery(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void {}
    public function dueDeliveries(DateTimeImmutable $now, int $limit): array { return array_slice($this->deliveries, 0, $limit); }
    public function markDeliverySent(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void {}
    public function markDeliveryFailed(EntityId $notificationId, NotificationChannel $channel, int $attempts, ?DateTimeImmutable $nextAttemptAt, string $errorCode): void
    {
        $this->failedAttempts = $attempts;
        $this->nextAttemptAt = $nextAttemptAt;
        $this->errorCode = $errorCode;
    }
    public function inbox(EntityId $userId, int $limit, int $offset): array { return []; }
    public function unreadCount(EntityId $userId): int { return 0; }
    public function markRead(EntityId $userId, EntityId $notificationId, DateTimeImmutable $now): bool { return false; }
}
