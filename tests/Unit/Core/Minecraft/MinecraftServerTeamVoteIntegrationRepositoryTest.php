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
use Forwext\Core\Minecraft\Server\MinecraftServerTeamMember;
use PHPUnit\Framework\TestCase;

final class MinecraftServerTeamVoteIntegrationRepositoryTest extends TestCase
{
    public function testManagementDirectoryIncludesDelegatedManagersWithoutDuplicatingBoundParameters(): void
    {
        $database = new MinecraftServerTeamVoteIntegrationRepositoryDatabase();
        $repository = new DatabaseMinecraftServerRepository($database);
        $actor = EntityId::fromString('22222222222222222222222222222222');

        self::assertSame([], $repository->managementDirectory($actor, 50));

        $query = $database->fetchAllQueries[0];
        self::assertStringContainsString('forwext_minecraft_server_team_members', $query->sql);
        self::assertStringContainsString("tm.role_key='manager'", $query->sql);
        self::assertSame($actor->value(), $query->parameters['owner_user_id']);
        self::assertSame($actor->value(), $query->parameters['team_user_id']);
    }

    public function testTeamUpsertLocksOwnershipAndPersistsRole(): void
    {
        $database = new MinecraftServerTeamVoteIntegrationRepositoryDatabase();
        $database->fetchOneRows[] = ['owner_user_id'=>'11111111111111111111111111111111'];
        $database->executeResult = 1;
        $repository = new DatabaseMinecraftServerRepository($database);
        $now = new DateTimeImmutable('2026-10-04 20:30:00', new DateTimeZone('UTC'));

        $repository->upsertTeamMember(
            new MinecraftServerTeamMember(
                EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
                EntityId::fromString('22222222222222222222222222222222'),
                'manager',
                'Teknik sorumlu',
                EntityId::fromString('11111111111111111111111111111111'),
                $now,
                $now,
            ),
            EntityId::fromString('11111111111111111111111111111111'),
        );

        self::assertStringContainsString('FOR UPDATE', $database->fetchOneQueries[0]->sql);
        self::assertStringContainsString(
            'INSERT INTO forwext_minecraft_server_team_members',
            $database->executeQueries[0]->sql,
        );
        self::assertSame('manager', $database->executeQueries[0]->parameters['role_key']);
    }

    public function testVoteIntegrationStoresHashMaterialAndAuthenticatesByHash(): void
    {
        $database = new MinecraftServerTeamVoteIntegrationRepositoryDatabase();
        $database->fetchOneRows[] = ['owner_user_id'=>'11111111111111111111111111111111'];
        $database->executeResult = 1;
        $repository = new DatabaseMinecraftServerRepository($database);
        $hash = hash('sha256', str_repeat('A', 43));
        $now = new DateTimeImmutable('2026-10-04 20:30:00', new DateTimeZone('UTC'));

        $repository->saveVoteIntegration(
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            true,
            $hash,
            'ABCDEFGHIJ',
            true,
            EntityId::fromString('11111111111111111111111111111111'),
            EntityId::fromString('11111111111111111111111111111111'),
            $now,
        );

        self::assertStringContainsString('FOR UPDATE', $database->fetchOneQueries[0]->sql);
        $write = $database->executeQueries[0];
        self::assertStringContainsString('forwext_minecraft_server_vote_integrations', $write->sql);
        self::assertSame($hash, $write->parameters['token_hash']);
        self::assertSame('ABCDEFGHIJ', $write->parameters['token_prefix']);

        $database->fetchValueValues[] = $hash;
        self::assertTrue($repository->acceptsVoteIntegrationToken(
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            $hash,
        ));
        $database->fetchValueValues[] = str_repeat('0', 64);
        self::assertFalse($repository->acceptsVoteIntegrationToken(
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            $hash,
        ));
    }

    public function testVoteFeedHydratesOpaqueVoteIdAndCurrentAccountUsername(): void
    {
        $database = new MinecraftServerTeamVoteIntegrationRepositoryDatabase();
        $database->fetchAllRows[] = [[
            'vote_id'=>'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'server_id'=>'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'voter_user_id'=>'22222222222222222222222222222222',
            'username'=>'PlayerOne',
            'created_at_utc'=>'2026-10-04 20:25:00.000000',
        ]];
        $repository = new DatabaseMinecraftServerRepository($database);

        $feed = $repository->voteFeed(
            EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            100,
        );

        self::assertCount(1, $feed);
        self::assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $feed[0]->voteId->value());
        self::assertSame('PlayerOne', $feed[0]->accountUsername);
        self::assertStringContainsString('INNER JOIN forwext_users', $database->fetchAllQueries[0]->sql);
        self::assertStringContainsString('ORDER BY v.created_at_utc DESC,v.vote_id DESC', $database->fetchAllQueries[0]->sql);
    }
}

final class MinecraftServerTeamVoteIntegrationRepositoryDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executeQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchOneQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchAllQueries = [];
    /** @var list<CompiledQuery> */
    public array $fetchValueQueries = [];
    /** @var list<array<string,mixed>|null> */
    public array $fetchOneRows = [];
    /** @var list<list<array<string,mixed>>> */
    public array $fetchAllRows = [];
    /** @var list<mixed> */
    public array $fetchValueValues = [];
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
        $this->fetchAllQueries[] = $query;
        return array_shift($this->fetchAllRows) ?? [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->fetchValueQueries[] = $query;
        return array_shift($this->fetchValueValues);
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
