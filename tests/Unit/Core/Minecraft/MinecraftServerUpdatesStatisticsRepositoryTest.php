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
use Forwext\Core\Minecraft\Server\MinecraftServerUpdate;
use PHPUnit\Framework\TestCase;

final class MinecraftServerUpdatesStatisticsRepositoryTest extends TestCase
{
    public function testStatisticsFillsThirtyUtcVoteBucketsFromAuthoritativeRows(): void
    {
        $database = new MinecraftServerUpdatesStatisticsDatabase();
        $database->fetchOneRows[] = [
            'total_votes'=>40,
            'votes_30d'=>7,
            'published_updates'=>3,
            'last_vote_at'=>'2026-10-04 18:30:00.000000',
        ];
        $database->fetchAllRows[] = [
            ['vote_day'=>'2026-10-03','vote_count'=>2],
            ['vote_day'=>'2026-10-04','vote_count'=>5],
        ];
        $repository = new DatabaseMinecraftServerRepository($database);

        $statistics = $repository->statistics(
            EntityId::fromString('11111111111111111111111111111111'),
            new DateTimeImmutable('2026-10-04 20:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame(40, $statistics->totalVotes);
        self::assertSame(7, $statistics->votesLast30Days);
        self::assertSame(3, $statistics->publishedUpdates);
        self::assertCount(30, $statistics->dailyVotes);
        self::assertSame(2, $statistics->dailyVotes['2026-10-03']);
        self::assertSame(5, $statistics->dailyVotes['2026-10-04']);
        self::assertSame(0, $statistics->dailyVotes['2026-09-05']);
        self::assertSame('2026-10-04 18:30:00', $statistics->lastVoteAt?->format('Y-m-d H:i:s'));
    }

    public function testCreateUpdateLocksServerAndChecksRequiredOwnerBeforeInsert(): void
    {
        $database = new MinecraftServerUpdatesStatisticsDatabase();
        $database->fetchOneRows[] = ['owner_user_id'=>'22222222222222222222222222222222'];
        $database->executeResult = 1;
        $repository = new DatabaseMinecraftServerRepository($database);
        $now = new DateTimeImmutable('2026-10-04 20:00:00', new DateTimeZone('UTC'));

        $repository->createUpdate(
            new MinecraftServerUpdate(
                EntityId::fromString('33333333333333333333333333333333'),
                EntityId::fromString('11111111111111111111111111111111'),
                EntityId::fromString('22222222222222222222222222222222'),
                'Yeni sezon',
                'Yeni sezon özellikleri aktif edildi.',
                'published',
                $now,
                $now,
            ),
            EntityId::fromString('22222222222222222222222222222222'),
        );

        self::assertStringContainsString('FOR UPDATE', $database->fetchOneQueries[0]->sql);
        self::assertStringContainsString(
            'INSERT INTO forwext_minecraft_server_updates',
            $database->executeQueries[0]->sql,
        );
    }

    public function testStateChangeLocksServerAndUpdatesOnlyMatchingServerPost(): void
    {
        $database = new MinecraftServerUpdatesStatisticsDatabase();
        $database->fetchOneRows[] = ['owner_user_id'=>'22222222222222222222222222222222'];
        $database->executeResult = 1;
        $repository = new DatabaseMinecraftServerRepository($database);

        self::assertTrue($repository->setUpdateState(
            EntityId::fromString('11111111111111111111111111111111'),
            EntityId::fromString('33333333333333333333333333333333'),
            'hidden',
            new DateTimeImmutable('2026-10-04 20:00:00', new DateTimeZone('UTC')),
            EntityId::fromString('22222222222222222222222222222222'),
        ));

        $query = $database->executeQueries[0];
        self::assertStringContainsString('WHERE update_id=:update_id AND server_id=:server_id', $query->sql);
        self::assertSame('hidden', $query->parameters['state']);
    }
}

final class MinecraftServerUpdatesStatisticsDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executeQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<array<string,mixed>|null> */
    public array $fetchOneRows = [];
    /** @var list<list<array<string,mixed>>> */
    public array $fetchAllRows = [];
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
        return array_shift($this->fetchAllRows) ?? [];
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
