<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Api\V1;

use Forwext\Core\Api\V1\DatabasePrivateApiV1ReadRepository;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class DatabasePrivateApiV1ReadRepositoryTest extends TestCase
{
    public function testConversationIndexUsesRealDirectMessageReadModel(): void
    {
        $database = new PrivateApiV1RecordingDatabase([
            [
                'conversation_id'=>str_repeat('1', 32),
                'other_user_id'=>str_repeat('2', 32),
                'other_username'=>'Alice',
                'preview'=>'Son mesaj',
                'unread_count'=>3,
                'updated_at_utc'=>'2026-10-03 12:30:00.000000',
            ],
        ]);
        $repository = new DatabasePrivateApiV1ReadRepository($database);
        $page = $repository->conversations(
            EntityId::fromString(str_repeat('a', 32)),
            1,
            30,
        );

        self::assertCount(1, $page->items);
        self::assertSame([
            'id'=>str_repeat('1', 32),
            'other_user_id'=>str_repeat('2', 32),
            'other_username'=>'Alice',
            'preview'=>'Son mesaj',
            'unread_count'=>3,
            'updated_at'=>'2026-10-03T12:30:00.000000Z',
        ], $page->items[0]);

        self::assertNotNull($database->lastQuery);
        self::assertStringContainsString('forwext_direct_conversation_participants', $database->lastQuery->sql);
        self::assertStringContainsString('forwext_direct_messages', $database->lastQuery->sql);
        self::assertStringNotContainsString('forwext_support_tickets', $database->lastQuery->sql);
        self::assertStringNotContainsString('forwext_bug_reports', $database->lastQuery->sql);
        self::assertSame(str_repeat('a', 32), $database->lastQuery->parameters['actor_id'] ?? null);
        self::assertSame(str_repeat('a', 32), $database->lastQuery->parameters['unread_actor_id'] ?? null);
    }

    public function testConversationPaginationUsesOneExtraRowForHasMore(): void
    {
        $rows = [];
        for ($index = 0; $index < 3; ++$index) {
            $rows[] = [
                'conversation_id'=>str_pad((string) ($index + 1), 32, '0', STR_PAD_LEFT),
                'other_user_id'=>str_pad((string) ($index + 11), 32, '0', STR_PAD_LEFT),
                'other_username'=>'User' . $index,
                'preview'=>'Message ' . $index,
                'unread_count'=>0,
                'updated_at_utc'=>'2026-10-03 12:30:00.000000',
            ];
        }

        $page = (new DatabasePrivateApiV1ReadRepository(
            new PrivateApiV1RecordingDatabase($rows),
        ))->conversations(EntityId::fromString(str_repeat('b', 32)), 1, 2);

        self::assertCount(2, $page->items);
        self::assertTrue($page->hasMore);
    }
}

final class PrivateApiV1RecordingDatabase implements QueryExecutor
{
    public ?CompiledQuery $lastQuery = null;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private array $rows)
    {
    }

    public function execute(CompiledQuery $query): int
    {
        $this->lastQuery = $query;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->lastQuery = $query;
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->lastQuery = $query;
        return $this->rows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->lastQuery = $query;
        return null;
    }
}
