<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Notification\Realtime;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Notification\Realtime\DatabaseNotificationRealtimeReader;
use PHPUnit\Framework\TestCase;

final class DatabaseNotificationRealtimeReaderTest extends TestCase
{
    public function testSnapshotLookupIsRecipientAndInAppScopedAgainstIdor(): void
    {
        $database = new RealtimeReaderRecordingDatabase();
        $reader = new DatabaseNotificationRealtimeReader($database);
        $userId = UserId::fromStored(str_repeat('a', 32));
        $reader->visibleByIds($userId, [EntityId::fromString(str_repeat('b', 32))]);

        self::assertCount(1, $database->fetchAllQueries);
        $query = $database->fetchAllQueries[0];
        self::assertStringContainsString('`recipient_user_id` = :recipient_user_id', $query->sql);
        self::assertStringContainsString('`in_app_visible` = 1', $query->sql);
        self::assertStringNotContainsString('payload_json', $query->sql);
        self::assertSame($userId->value(), $query->parameters['recipient_user_id']);
    }

    public function testBootstrapCursorIsScopedToDerivedChannel(): void
    {
        $database = new RealtimeReaderRecordingDatabase();
        $database->fetchValueResult = '42';
        $reader = new DatabaseNotificationRealtimeReader($database);
        self::assertSame(42, $reader->latestSequence('notification.user.' . str_repeat('c', 32)));
        self::assertCount(1, $database->fetchValueQueries);
        self::assertStringContainsString('MAX(`sequence_id`)', $database->fetchValueQueries[0]->sql);
    }
}

final class RealtimeReaderRecordingDatabase implements QueryExecutor
{
    /** @var list<CompiledQuery> */ public array $fetchAllQueries = [];
    /** @var list<CompiledQuery> */ public array $fetchValueQueries = [];
    public mixed $fetchValueResult = 0;
    public function execute(CompiledQuery $query): int { return 0; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { $this->fetchAllQueries[] = $query; return []; }
    public function fetchValue(CompiledQuery $query): mixed { $this->fetchValueQueries[] = $query; return $this->fetchValueResult; }
}
