<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateMinecraftServerTeamAndVoteIntegration;
use PHPUnit\Framework\TestCase;

final class MinecraftServerTeamVoteIntegrationMigrationTest extends TestCase
{
    public function testMigrationCreatesTeamIntegrationPersistenceAndPermissionDefaults(): void
    {
        $database = new MinecraftServerTeamVoteIntegrationRecordingDatabase();
        $migration = new CreateMinecraftServerTeamAndVoteIntegration();

        $migration->up(new MigrationContext($database));

        self::assertSame('20261004203000_minecraft_server_team_vote_integration', $migration->id()->value());
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
            'forwext_minecraft_server_team_members',
            'forwext_minecraft_server_vote_integrations',
            'fk_forwext_minecraft_server_team_server',
            'fk_forwext_minecraft_server_team_user',
            'fk_forwext_minecraft_server_team_added_by',
            'fk_forwext_minecraft_server_vote_integration_server',
            'fk_forwext_minecraft_server_vote_integration_user',
            'minecraft_server.team.manage',
            'minecraft_server.vote_integration.manage',
            'forwext_permission_template_rules',
        ] as $expected) {
            self::assertStringContainsString($expected, $contract);
        }
    }

    public function testVerificationRequiresTablesForeignKeysPermissionsAndTemplates(): void
    {
        $database = new MinecraftServerTeamVoteIntegrationRecordingDatabase();
        $database->fetchValues = [2, 5, 2, 10];

        self::assertTrue(
            (new CreateMinecraftServerTeamAndVoteIntegration())
                ->verify(new MigrationContext($database))
                ->isPassed(),
        );
    }
}

final class MinecraftServerTeamVoteIntegrationRecordingDatabase implements TransactionalQueryExecutor
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
