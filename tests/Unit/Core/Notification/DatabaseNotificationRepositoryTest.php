<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Notification;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Notification\DatabaseNotificationRepository;
use PHPUnit\Framework\TestCase;

final class DatabaseNotificationRepositoryTest extends TestCase
{
    public function testRecipientLockSerializesDispatchWithinTransaction(): void
    {
        $database = new NotificationRecordingDatabase();
        $userId = UserId::fromStored(str_repeat('9', 32));
        $database->fetchValueResults = [$userId->value()];
        $repository = new DatabaseNotificationRepository($database);
        $called = false;

        $result = $repository->withRecipientLock($userId, static function () use (&$called): string {
            $called = true;
            return 'locked';
        });

        self::assertTrue($called);
        self::assertSame('locked', $result);
        self::assertSame(1, $database->transactions);
        self::assertCount(1, $database->fetchValueQueries);
        self::assertStringContainsString('FOR UPDATE', $database->fetchValueQueries[0]->sql);
        self::assertSame($userId->value(), $database->fetchValueQueries[0]->parameters['user_id']);
    }

    public function testMarkReadIsRecipientScopedToPreventIdor(): void
    {
        $database = new NotificationRecordingDatabase();
        $database->executeResult = 1;
        $repository = new DatabaseNotificationRepository($database);
        $userId = UserId::fromStored(str_repeat('a', 32));
        $notificationId = EntityId::fromString(str_repeat('b', 32));

        self::assertTrue($repository->markRead(
            $userId,
            $notificationId,
            new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC')),
        ));

        self::assertCount(1, $database->executedQueries);
        $query = $database->executedQueries[0];
        self::assertStringContainsString('`recipient_user_id` = :recipient_user_id', $query->sql);
        self::assertSame($userId->value(), $query->parameters['recipient_user_id']);
        self::assertSame($notificationId->value(), $query->parameters['notification_id']);
    }

    public function testInboxQueryNeverReturnsEmailOnlyRecords(): void
    {
        $database = new NotificationRecordingDatabase();
        (new DatabaseNotificationRepository($database))->inbox(UserId::fromStored(str_repeat('c', 32)), 25, 0);
        self::assertCount(1, $database->fetchAllQueries);
        self::assertStringContainsString('`in_app_visible` = 1', $database->fetchAllQueries[0]->sql);
        self::assertStringContainsString('LIMIT 25 OFFSET 0', $database->fetchAllQueries[0]->sql);
    }
}

final class NotificationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */ public array $executedQueries = [];
    /** @var list<CompiledQuery> */ public array $fetchAllQueries = [];
    /** @var list<CompiledQuery> */ public array $fetchValueQueries = [];
    /** @var list<mixed> */ public array $fetchValueResults = [];
    public int $executeResult = 0;
    public int $transactions = 0;

    public function execute(CompiledQuery $query): int { $this->executedQueries[] = $query; return $this->executeResult; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { $this->fetchAllQueries[] = $query; return []; }
    public function fetchValue(CompiledQuery $query): mixed { $this->fetchValueQueries[] = $query; return array_shift($this->fetchValueResults); }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { ++$this->transactions; return $callback($this); }
}
