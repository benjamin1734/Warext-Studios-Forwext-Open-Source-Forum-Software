<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Notification;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Notification\Notification;
use Forwext\Core\Notification\NotificationChannel;
use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDelivery;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRepository;
use Forwext\Core\Notification\NotificationRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NotificationDispatcherTest extends TestCase
{
    public function testDispatchRendersPersistsAndQueuesEnabledExternalChannels(): void
    {
        $repository = new NotificationMemoryRepository();
        $registry = new NotificationRegistry();
        $registry->register(new NotificationDefinition(
            'forum.mention', 'forum', '{{actor}} mentioned you', 'Open {{thread}} to view the mention.',
            [NotificationChannel::InApp, NotificationChannel::Email],
        ));
        $dispatcher = new NotificationDispatcher($registry, $repository);
        $userId = UserId::fromStored(str_repeat('a', 32));
        $now = new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC'));

        $notification = $dispatcher->dispatch(new NotificationRequest(
            $userId,
            'forum.mention',
            ['actor' => 'Ada', 'thread' => 'Roadmap'],
            groupKey: 'thread:42',
            dedupeKey: 'post:99:user:a',
            actionPath: '/threads/42#post-99',
            payload: ['post_id' => '99'],
        ), $now);

        self::assertNotNull($notification);
        self::assertSame('Ada mentioned you', $notification->title);
        self::assertSame('Open Roadmap to view the mention.', $notification->body);
        self::assertTrue($notification->inAppVisible);
        self::assertSame([NotificationChannel::Email], $repository->queuedChannels);
        self::assertSame($notification->id->value(), $repository->dedupes['post:99:user:a']?->value());
    }

    public function testDedupeReturnsExistingNotificationWithoutAdditionalDelivery(): void
    {
        $repository = new NotificationMemoryRepository();
        $registry = new NotificationRegistry();
        $registry->register(new NotificationDefinition('forum.reply', 'forum', 'Reply', 'New reply'));
        $dispatcher = new NotificationDispatcher($registry, $repository);
        $userId = UserId::fromStored(str_repeat('b', 32));
        $existing = new Notification(
            EntityId::fromString(str_repeat('c', 32)), $userId, 'forum.reply', 'forum', 'Reply', 'New reply',
            '/threads/1', [], true, 1,
            new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC')),
        );
        $repository->existingDedupe = $existing;

        $result = $dispatcher->dispatch(new NotificationRequest($userId, 'forum.reply', dedupeKey: 'reply:1'));

        self::assertSame($existing, $result);
        self::assertSame([], $repository->queuedChannels);
        self::assertSame([], $repository->inserted);
    }

    public function testGroupingUpdatesUnreadAlertAndRespectsChannelPreferenceOverride(): void
    {
        $repository = new NotificationMemoryRepository();
        $registry = new NotificationRegistry();
        $registry->register(new NotificationDefinition(
            'social.reaction', 'social', '{{count}} reactions', 'Your post has new reactions.',
            [NotificationChannel::InApp, NotificationChannel::Push],
        ));
        $userId = UserId::fromStored(str_repeat('d', 32));
        $existing = new Notification(
            EntityId::fromString(str_repeat('e', 32)), $userId, 'social.reaction', 'social', '1 reaction', 'Body',
            '/posts/7', [], true, 1,
            new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC')),
        );
        $repository->existingGroup = $existing;
        $repository->preferences['social:' . NotificationChannel::Push->value] = false;
        $repository->preferences['social:' . NotificationChannel::Email->value] = true;

        $result = (new NotificationDispatcher($registry, $repository))->dispatch(new NotificationRequest(
            $userId, 'social.reaction', ['count' => 2], groupKey: 'post:7', dedupeKey: 'reaction:2', actionPath: '/posts/7',
        ));

        self::assertNotNull($result);
        self::assertSame(2, $result->occurrences);
        self::assertSame([NotificationChannel::Email], $repository->queuedChannels);
        self::assertSame([], $repository->inserted);
    }

    public function testRequestRejectsExternalOrProtocolRelativeActionUrl(): void
    {
        $userId = UserId::fromStored(str_repeat('f', 32));
        foreach (['https://evil.example/x', '//evil.example/x'] as $path) {
            try {
                new NotificationRequest($userId, 'forum.reply', actionPath: $path);
                self::fail('Unsafe action path was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}

final class NotificationMemoryRepository implements NotificationRepository
{
    /** @var list<Notification> */ public array $inserted = [];
    /** @var list<NotificationChannel> */ public array $queuedChannels = [];
    /** @var array<string, EntityId> */ public array $dedupes = [];
    /** @var array<string, bool> */ public array $preferences = [];
    public ?Notification $existingDedupe = null;
    public ?Notification $existingGroup = null;

    public function findByDedupe(EntityId $recipientUserId, string $dedupeKey): ?Notification { return $this->existingDedupe; }
    public function findOpenGroup(EntityId $recipientUserId, string $typeKey, string $groupKey): ?Notification { return $this->existingGroup; }
    public function insert(Notification $notification, ?string $groupKey): void { $this->inserted[] = $notification; }
    public function incrementGroup(EntityId $notificationId, string $title, string $body, ?string $actionPath, array $payload, bool $inAppVisible, DateTimeImmutable $now): Notification
    {
        $old = $this->existingGroup ?? throw new \LogicException('No group configured.');
        return new Notification($old->id, $old->recipientUserId, $old->typeKey, $old->categoryKey, $title, $body, $actionPath, $payload, $inAppVisible, $old->occurrences + 1, $old->createdAt, $now, $old->readAt);
    }
    public function rememberDedupe(EntityId $recipientUserId, string $dedupeKey, EntityId $notificationId, DateTimeImmutable $now): void { $this->dedupes[$dedupeKey] = $notificationId; }
    public function preference(EntityId $userId, string $categoryKey, NotificationChannel $channel): ?bool { return $this->preferences[$categoryKey . ':' . $channel->value] ?? null; }
    public function setPreference(EntityId $userId, string $categoryKey, NotificationChannel $channel, bool $enabled, DateTimeImmutable $now): void { $this->preferences[$categoryKey . ':' . $channel->value] = $enabled; }
    public function queueDelivery(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void { $this->queuedChannels[] = $channel; }
    public function dueDeliveries(DateTimeImmutable $now, int $limit): array { return []; }
    public function markDeliverySent(EntityId $notificationId, NotificationChannel $channel, DateTimeImmutable $now): void {}
    public function markDeliveryFailed(EntityId $notificationId, NotificationChannel $channel, int $attempts, ?DateTimeImmutable $nextAttemptAt, string $errorCode): void {}
    public function inbox(EntityId $userId, int $limit, int $offset): array { return []; }
    public function unreadCount(EntityId $userId): int { return 0; }
    public function markRead(EntityId $userId, EntityId $notificationId, DateTimeImmutable $now): bool { return false; }
}
