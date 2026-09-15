<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Poll;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Poll\DatabasePollRepository;
use Forwext\Core\Forum\Poll\PollOperationException;
use PHPUnit\Framework\TestCase;

final class DatabasePollRepositoryTest extends TestCase
{
    public function testVoteLocksPollAndWritesParticipantAndChoiceAtomically(): void
    {
        $database = new PollRecordingDatabase();
        $database->fetchOneQueue = [$this->pollRow(), null];
        $database->fetchAllQueue = [$this->optionRows()];
        $database->fetchValueQueue = [0];
        $repository = new DatabasePollRepository($database);

        $repository->castVote(
            $this->id('a'),
            $this->id('1'),
            [$this->id('c')],
            $this->time('2026-09-15 22:10:00.000000'),
        );

        self::assertTrue($database->transactionUsed);
        self::assertNotEmpty($database->fetchOneQueries);
        self::assertTrue($database->fetchOneQueries[0]->requiresTransaction);
        self::assertStringContainsString('FOR UPDATE', $database->fetchOneQueries[0]->sql);
        $sql = implode("\n", array_map(static fn (CompiledQuery $query): string => $query->sql, $database->executedQueries));
        self::assertStringContainsString('INSERT INTO `forwext_poll_votes`', $sql);
        self::assertStringContainsString('INSERT INTO `forwext_poll_vote_choices`', $sql);
    }

    public function testParticipantLimitFailsClosedUnderPollLock(): void
    {
        $database = new PollRecordingDatabase();
        $database->fetchOneQueue = [$this->pollRow(maxVoters: 1)];
        $database->fetchAllQueue = [$this->optionRows()];
        $database->fetchValueQueue = [1];
        $repository = new DatabasePollRepository($database);

        $this->expectException(PollOperationException::class);
        $repository->castVote(
            $this->id('a'),
            $this->id('1'),
            [$this->id('c')],
            $this->time('2026-09-15 22:10:00.000000'),
        );
    }

    public function testExistingVoteCannotChangeWhenPolicyDisallowsIt(): void
    {
        $database = new PollRecordingDatabase();
        $database->fetchOneQueue = [
            $this->pollRow(changeVote: false),
            ['vote_id' => $this->id('f')->value()],
        ];
        $database->fetchAllQueue = [
            $this->optionRows(),
            [['option_id' => $this->id('c')->value()]],
        ];
        $database->fetchValueQueue = [1];
        $repository = new DatabasePollRepository($database);

        $this->expectException(PollOperationException::class);
        $repository->castVote(
            $this->id('a'),
            $this->id('1'),
            [$this->id('d')],
            $this->time('2026-09-15 22:10:00.000000'),
        );
    }

    public function testSecretPollNeverLoadsVoterIdentitiesIntoResults(): void
    {
        $database = new PollRecordingDatabase();
        $database->fetchAllQueue = [[
            ['option_id' => $this->id('c')->value(), 'vote_count' => 2],
            ['option_id' => $this->id('d')->value(), 'vote_count' => 0],
        ]];
        $database->fetchValueQueue = [2];
        $repository = new DatabasePollRepository($database);
        $poll = $this->poll();

        $results = $repository->results($poll, $this->time('2026-09-15 22:10:00.000000'), true);

        self::assertCount(1, $database->fetchAllQueries);
        self::assertSame(2, $results->totalVoters);
        self::assertSame([], $results->options[0]->voterUserIds);
    }

    private function poll(): \Forwext\Core\Forum\Poll\Poll
    {
        $database = new PollRecordingDatabase();
        $database->fetchOneQueue = [$this->pollRow()];
        $database->fetchAllQueue = [$this->optionRows()];
        return (new DatabasePollRepository($database))->find($this->id('a'))
            ?? self::fail('Expected poll fixture.');
    }

    /** @return array<string, mixed> */
    private function pollRow(bool $changeVote = true, ?int $maxVoters = null): array
    {
        return [
            'poll_id' => $this->id('a')->value(),
            'thread_id' => $this->id('b')->value(),
            'creator_user_id' => $this->id('1')->value(),
            'question' => 'Pick one',
            'selection_mode' => 'single',
            'max_selections' => 1,
            'change_vote' => $changeVote ? 1 : 0,
            'voter_visibility' => 'secret',
            'result_visibility' => 'always',
            'closes_at_utc' => null,
            'max_voters' => $maxVoters,
            'closed_at_utc' => null,
            'created_at_utc' => '2026-09-15 22:00:00.000000',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function optionRows(): array
    {
        return [
            ['option_id' => $this->id('c')->value(), 'option_text' => 'One', 'sort_order' => 0],
            ['option_id' => $this->id('d')->value(), 'option_text' => 'Two', 'sort_order' => 1],
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

final class PollRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchValueQueries = [];
    /** @var list<array<string, mixed>|null> */
    public array $fetchOneQueue = [];
    /** @var list<list<array<string, mixed>>> */
    public array $fetchAllQueue = [];
    /** @var list<mixed> */
    public array $fetchValueQueue = [];
    public bool $transactionUsed = false;
    private bool $inside = false;

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->fetchOneQueries[] = $query;
        return array_shift($this->fetchOneQueue);
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->fetchAllQueries[] = $query;
        return array_shift($this->fetchAllQueue) ?? [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->fetchValueQueries[] = $query;
        return array_shift($this->fetchValueQueue) ?? 0;
    }

    public function inTransaction(): bool
    {
        return $this->inside;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->transactionUsed = true;
        $previous = $this->inside;
        $this->inside = true;
        try {
            return $callback($this);
        } finally {
            $this->inside = $previous;
        }
    }
}
