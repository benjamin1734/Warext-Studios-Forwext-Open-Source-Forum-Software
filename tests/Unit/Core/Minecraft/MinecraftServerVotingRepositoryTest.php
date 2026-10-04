<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Minecraft;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Minecraft\Server\DatabaseMinecraftServerRepository;
use PHPUnit\Framework\TestCase;

final class MinecraftServerVotingRepositoryTest extends TestCase
{
    public function testVoteSummaryUsesBoundVoterParametersAndHydratesCounts(): void
    {
        $database = new MinecraftServerVotingRepositoryDatabase();
        $database->fetchOneRows[] = [
            'total_votes'=>12,
            'votes_30d'=>5,
            'voted_today'=>1,
            'last_vote_at'=>'2026-10-04 18:30:00.000000',
        ];
        $repository = new DatabaseMinecraftServerRepository($database);
        $now = new DateTimeImmutable('2026-10-04 19:00:00', new DateTimeZone('UTC'));

        $summary = $repository->voteSummary(
            EntityId::fromString('11111111111111111111111111111111'),
            EntityId::fromString('22222222222222222222222222222222'),
            $now,
        );

        self::assertSame(12, $summary->totalVotes);
        self::assertSame(5, $summary->votesLast30Days);
        self::assertTrue($summary->votedToday);
        self::assertSame('2026-10-04 18:30:00', $summary->lastVoteAt?->format('Y-m-d H:i:s'));

        $query = $database->fetchOneQueries[0];
        self::assertArrayHasKey('voter_last', $query->parameters);
        self::assertArrayHasKey('voter_today', $query->parameters);
        self::assertSame('2026-10-04', $query->parameters['vote_day']);
    }

    public function testCastVoteUsesInsertIgnoreAndUtcDailyBucket(): void
    {
        $database = new MinecraftServerVotingRepositoryDatabase();
        $database->executeResult = 1;
        $repository = new DatabaseMinecraftServerRepository($database);
        $now = new DateTimeImmutable('2026-10-04 23:45:00', new DateTimeZone('UTC'));

        self::assertTrue($repository->castVote(
            EntityId::fromString('11111111111111111111111111111111'),
            EntityId::fromString('22222222222222222222222222222222'),
            $now,
        ));

        self::assertCount(1, $database->executeQueries);
        $query = $database->executeQueries[0];
        self::assertStringContainsString('INSERT IGNORE INTO forwext_minecraft_server_votes', $query->sql);
        self::assertStringContainsString("listing_state='published'", $query->sql);
        self::assertSame('2026-10-04', $query->parameters['vote_day']);
    }

    public function testDuplicateDailyVoteReturnsFalse(): void
    {
        $database = new MinecraftServerVotingRepositoryDatabase();
        $database->executeResult = 0;
        $repository = new DatabaseMinecraftServerRepository($database);

        self::assertFalse($repository->castVote(
            EntityId::fromString('11111111111111111111111111111111'),
            EntityId::fromString('22222222222222222222222222222222'),
            new DateTimeImmutable('2026-10-04 12:00:00', new DateTimeZone('UTC')),
        ));
    }
}

final class MinecraftServerVotingRepositoryDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executeQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<array<string,mixed>|null> */
    public array $fetchOneRows = [];
    public int $executeResult = 0;

    public function execute(CompiledQuery $query): int
    {
        $this->executeQueries[] = $query;
        return $this->executeResult;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->fetchOneQueries[] = $query;
        return array_shift($this->fetchOneRows);
    }

    public function fetchAll(CompiledQuery $query): array
    {
        unset($query);
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        unset($query);
        return 0;
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function transaction(Closure $callback): mixed
    {
        return $callback($this);
    }
}
