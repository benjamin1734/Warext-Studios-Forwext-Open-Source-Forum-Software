<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Thread;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Thread\DatabaseThreadRepository;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use Forwext\Core\Forum\Thread\ThreadTypeRegistry;
use PHPUnit\Framework\TestCase;

final class DatabaseThreadModerationVisibilityTest extends TestCase
{
    public function testNormalLookupAndListingExcludeDeletedAndMergedTombstones(): void
    {
        $database = new ThreadModerationVisibilityDatabase();
        $repository = new DatabaseThreadRepository($database, ThreadTypeRegistry::withCoreDefaults());

        self::assertNull($repository->find($this->id('a')));
        self::assertSame([], $repository->findByForum($this->id('b')));

        self::assertCount(1, $database->fetchOneQueries);
        self::assertStringContainsString('`deleted` = 0', $database->fetchOneQueries[0]->sql);
        self::assertStringContainsString('`merged_into_thread_id` IS NULL', $database->fetchOneQueries[0]->sql);
        self::assertCount(1, $database->fetchAllQueries);
        self::assertStringContainsString('`deleted` = 0', $database->fetchAllQueries[0]->sql);
        self::assertStringContainsString('`merged_into_thread_id` IS NULL', $database->fetchAllQueries[0]->sql);
    }

    public function testNormalOptimisticUpdateCannotResurrectInactiveThread(): void
    {
        $database = new ThreadModerationVisibilityDatabase();
        $repository = new DatabaseThreadRepository($database, ThreadTypeRegistry::withCoreDefaults());
        $thread = Thread::hydrate(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Stored'),
            ThreadModerationState::Visible,
            false,
            false,
            false,
            $this->time('2026-09-15 18:00:00.000000'),
            $this->time('2026-09-15 18:00:00.000000'),
            4,
        );

        $repository->save($thread);

        self::assertCount(1, $database->executedQueries);
        self::assertStringContainsString('`deleted` = 0', $database->executedQueries[0]->sql);
        self::assertStringContainsString('`merged_into_thread_id` IS NULL', $database->executedQueries[0]->sql);
        self::assertSame(5, $thread->version());
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        self::assertInstanceOf(DateTimeImmutable::class, $time);
        return $time;
    }
}

final class ThreadModerationVisibilityDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    private bool $inside = false;

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->fetchOneQueries[] = $query;
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return null;
    }

    public function inTransaction(): bool
    {
        return $this->inside;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = false;
        }
    }
}
