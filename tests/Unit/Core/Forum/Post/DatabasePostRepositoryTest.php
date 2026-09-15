<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Post;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Post\DatabasePostRepository;
use Forwext\Core\Forum\Post\Post;
use Forwext\Core\Forum\Post\PostBody;
use Forwext\Core\Forum\Post\PostModerationState;
use PHPUnit\Framework\TestCase;

final class DatabasePostRepositoryTest extends TestCase
{
    public function testCreateSerializesPositionAgainstThreadRow(): void
    {
        $database = new PostRecordingDatabase();
        $database->fetchOneResults = [['thread_id' => $this->id('b')->value()]];
        $database->fetchValues = [0];
        $repository = new DatabasePostRepository($database);

        $post = $repository->create(
            $this->id('b'),
            $this->id('1'),
            PostBody::fromString('First'),
            false,
            true,
            $this->time('2026-09-15 20:00:00.000000'),
        );

        self::assertTrue($database->transactionUsed);
        self::assertSame(1, $post->position());
        self::assertTrue($post->isFirstPost());
        self::assertSame(1, $post->version());
        self::assertTrue($database->fetchOneQueries[0]->requiresTransaction);
        self::assertStringContainsString('SELECT `thread_id`', $database->fetchOneQueries[0]->sql);
        self::assertStringContainsString('FOR UPDATE', $database->fetchOneQueries[0]->sql);
        self::assertTrue($database->fetchValueQueries[0]->requiresTransaction);
        self::assertStringContainsString('MAX(`position`)', $database->fetchValueQueries[0]->sql);
        self::assertSame($this->id('b')->value(), $database->executedQueries[0]->parameters['thread_id']);
        self::assertSame(1, $database->executedQueries[0]->parameters['position']);
    }

    public function testReplyGetsNextMonotonicPosition(): void
    {
        $database = new PostRecordingDatabase();
        $database->fetchOneResults = [['thread_id' => $this->id('b')->value()]];
        $database->fetchValues = [7];
        $repository = new DatabasePostRepository($database);

        $post = $repository->create(
            $this->id('b'),
            $this->id('1'),
            PostBody::fromString('Reply'),
            true,
            false,
            $this->time('2026-09-15 20:00:00.000000'),
        );

        self::assertSame(8, $post->position());
        self::assertSame(PostModerationState::Pending, $post->moderationState());
    }

    public function testSavePersistsHistoryInSameTransactionAndAdvancesVersion(): void
    {
        $database = new PostRecordingDatabase();
        $repository = new DatabasePostRepository($database);
        $post = $this->storedPost();
        $post->edit(
            PostBody::fromString('Edited body'),
            $this->id('1'),
            $this->time('2026-09-15 20:01:00.000000'),
        );

        $repository->save($post);

        self::assertSame(4, $post->version());
        self::assertSame([], $post->pendingHistory());
        self::assertCount(2, $database->executedQueries);
        self::assertStringStartsWith('UPDATE `forwext_posts`', $database->executedQueries[0]->sql);
        self::assertSame(3, $database->executedQueries[0]->parameters['expected_version']);
        self::assertStringStartsWith('INSERT INTO `forwext_post_history`', $database->executedQueries[1]->sql);
        self::assertSame('Stored body', $database->executedQueries[1]->parameters['body_source']);
        self::assertSame('edited', $database->executedQueries[1]->parameters['action']);
    }

    public function testPaginationAndCountersComeFromAuthoritativePostRows(): void
    {
        $database = new PostRecordingDatabase();
        $database->fetchValues = [2];
        $database->fetchAllResult = [$this->row('a', 1), $this->row('c', 2)];
        $database->fetchOneResults = [['active_count' => 5, 'visible_count' => 3]];
        $repository = new DatabasePostRepository($database);

        $page = $repository->pageByThread($this->id('b'), 2, 2);
        $counters = $repository->counters($this->id('b'));

        self::assertSame(2, $page->page);
        self::assertSame(2, $page->perPage);
        self::assertSame(2, $page->total);
        self::assertCount(2, $page->posts);
        self::assertStringContainsString('`deleted` = 0', $database->fetchAllQueries[0]->sql);
        self::assertStringContainsString("`moderation_state` = 'visible'", $database->fetchAllQueries[0]->sql);
        self::assertStringContainsString('ORDER BY `position` ASC LIMIT 2 OFFSET 2', $database->fetchAllQueries[0]->sql);
        self::assertSame(5, $counters->active);
        self::assertSame(3, $counters->visible);
    }

    private function storedPost(): Post
    {
        return Post::hydrate(
            $this->id('a'),
            $this->id('b'),
            $this->id('1'),
            2,
            PostBody::fromString('Stored body'),
            PostModerationState::Visible,
            false,
            null,
            $this->time('2026-09-15 20:00:00.000000'),
            $this->time('2026-09-15 20:00:00.000000'),
            3,
        );
    }

    /** @return array<string, mixed> */
    private function row(string $postSeed, int $position): array
    {
        return [
            'post_id' => $this->id($postSeed)->value(),
            'thread_id' => $this->id('b')->value(),
            'author_user_id' => $this->id('1')->value(),
            'position' => $position,
            'body_source' => 'Body ' . $position,
            'moderation_state' => 'visible',
            'deleted' => 0,
            'deleted_at_utc' => null,
            'version' => 1,
            'created_at_utc' => '2026-09-15 20:00:00.000000',
            'updated_at_utc' => '2026-09-15 20:00:00.000000',
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

final class PostRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchValueQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];
    /** @var list<array<string, mixed>> */
    public array $fetchOneResults = [];
    /** @var list<int> */
    public array $fetchValues = [];
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
        $this->fetchOneQueries[] = $query;
        return array_shift($this->fetchOneResults) ?? null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return $this->fetchAllResult;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->fetchValueQueries[] = $query;
        return array_shift($this->fetchValues) ?? 0;
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
