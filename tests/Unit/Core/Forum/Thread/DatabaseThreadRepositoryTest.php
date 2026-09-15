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
use Forwext\Core\Forum\Thread\ThreadConcurrencyException;
use Forwext\Core\Forum\Thread\ThreadId;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Forum\Thread\ThreadTitle;
use Forwext\Core\Forum\Thread\ThreadTypeKey;
use Forwext\Core\Forum\Thread\ThreadTypeRegistry;
use PHPUnit\Framework\TestCase;

final class DatabaseThreadRepositoryTest extends TestCase
{
    public function testInsertUsesBoundParametersAndAdvancesVersion(): void
    {
        $database = new ThreadRecordingDatabase();
        $repository = new DatabaseThreadRepository($database, ThreadTypeRegistry::withCoreDefaults());
        $thread = $this->newThread();

        $repository->save($thread);

        self::assertTrue($database->transactionUsed);
        self::assertCount(1, $database->executedQueries);
        $query = $database->executedQueries[0];
        self::assertStringStartsWith('INSERT INTO `forwext_threads`', $query->sql);
        self::assertStringNotContainsString($thread->title()->value(), $query->sql);
        self::assertSame($thread->title()->value(), $query->parameters['title']);
        self::assertSame('discussion', $query->parameters['type_key']);
        self::assertSame(1, $query->parameters['version']);
        self::assertSame(1, $thread->version());
    }

    public function testUpdateUsesOptimisticVersionAndRejectsStaleWrite(): void
    {
        $database = new ThreadRecordingDatabase();
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
        $database->executeResult = 0;

        try {
            $repository->save($thread);
            self::fail('Stale thread writes must fail.');
        } catch (ThreadConcurrencyException) {
            self::assertSame(4, $thread->version());
            self::assertSame(4, $database->executedQueries[0]->parameters['expected_version']);
            self::assertSame(5, $database->executedQueries[0]->parameters['version']);
        }
    }

    public function testFindHydratesRegisteredThreadType(): void
    {
        $database = new ThreadRecordingDatabase();
        $database->fetchOneResult = $this->row();
        $repository = new DatabaseThreadRepository($database, ThreadTypeRegistry::withCoreDefaults());

        $thread = $repository->find($this->id('a'));

        self::assertNotNull($thread);
        self::assertSame('discussion', $thread->typeKey()->value());
        self::assertSame('Stored thread', $thread->title()->value());
        self::assertSame(3, $thread->version());
    }

    public function testForumListingIsBoundedAndParameterizesForumId(): void
    {
        $database = new ThreadRecordingDatabase();
        $database->fetchAllResult = [$this->row()];
        $repository = new DatabaseThreadRepository($database, ThreadTypeRegistry::withCoreDefaults());

        $threads = $repository->findByForum($this->id('b'), 25, 10);

        self::assertCount(1, $threads);
        self::assertCount(1, $database->fetchAllQueries);
        $query = $database->fetchAllQueries[0];
        self::assertSame($this->id('b')->value(), $query->parameters['forum_node_id']);
        self::assertStringContainsString('LIMIT 25 OFFSET 10', $query->sql);
        self::assertStringContainsString('`sticky` DESC, `featured` DESC', $query->sql);
    }

    private function newThread(): Thread
    {
        return Thread::create(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            ThreadTypeKey::fromString('discussion'),
            ThreadTitle::fromString('Bound title'),
            false,
            $this->time('2026-09-15 18:00:00.000000'),
        );
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'thread_id' => $this->id('a')->value(),
            'forum_node_id' => $this->id('b')->value(),
            'author_user_id' => $this->id('1')->value(),
            'type_key' => 'discussion',
            'title' => 'Stored thread',
            'moderation_state' => 'visible',
            'locked' => 0,
            'sticky' => 1,
            'featured' => 0,
            'version' => 3,
            'created_at_utc' => '2026-09-15 18:00:00.000000',
            'updated_at_utc' => '2026-09-15 18:30:00.000000',
        ];
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

final class ThreadRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];
    /** @var array<string, mixed>|null */
    public ?array $fetchOneResult = null;
    /** @var list<array<string, mixed>> */
    public array $fetchAllResult = [];
    public int $executeResult = 1;
    public bool $transactionUsed = false;
    private bool $inside = false;

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return $this->executeResult;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return $this->fetchOneResult;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return $this->fetchAllResult;
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
        $this->transactionUsed = true;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = false;
        }
    }
}
