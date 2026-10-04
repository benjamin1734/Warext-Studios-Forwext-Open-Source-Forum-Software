<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateMinecraftServerVoting;
use PHPUnit\Framework\TestCase;

final class MinecraftServerVotingMigrationTest extends TestCase
{
    public function testMigrationCreatesDailyVoteUniquenessForeignKeysAndPermissionDefaults(): void
    {
        $database = new MinecraftServerVotingRecordingDatabase();
        $migration = new CreateMinecraftServerVoting();

        $migration->up(new MigrationContext($database));

        self::assertSame('20261004195500_minecraft_server_voting', $migration->id()->value());
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        $bound = [];
        foreach ($database->executedQueries as $query) {
            foreach ($query->parameters as $value) {
                if (is_string($value)) {
                    $bound[] = $value;
                }
            }
        }
        $contract = $sql . "\n" . implode("\n", $bound);

        foreach ([
            'forwext_minecraft_server_votes',
            'uq_forwext_minecraft_server_vote_daily',
            'fk_forwext_minecraft_server_vote_server',
            'fk_forwext_minecraft_server_vote_user',
            'minecraft_server.vote',
            'forwext_permission_template_rules',
        ] as $expected) {
            self::assertStringContainsString($expected, $contract);
        }
    }

    public function testVerificationRequiresTableForeignKeysUniqueIndexPermissionAndTemplates(): void
    {
        $database = new MinecraftServerVotingRecordingDatabase();
        $database->fetchValues = [1, 2, 3, 1, 5];

        self::assertTrue(
            (new CreateMinecraftServerVoting())->verify(new MigrationContext($database))->isPassed(),
        );
    }
}

final class MinecraftServerVotingRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<int> */
    public array $fetchValues = [];

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        unset($query);
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        unset($query);
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->executedQueries[] = $query;
        return array_shift($this->fetchValues) ?? 0;
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
