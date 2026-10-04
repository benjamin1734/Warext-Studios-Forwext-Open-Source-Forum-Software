<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateMinecraftServerSeasons;
use PHPUnit\Framework\TestCase;

final class MinecraftServerSeasonsMigrationTest extends TestCase
{
    public function testMigrationCreatesSeasonAndEntryTablesWithCascadeOwnership(): void
    {
        $database = new MinecraftServerSeasonsRecordingDatabase();
        $migration = new CreateMinecraftServerSeasons();

        $migration->up(new MigrationContext($database));

        self::assertSame('20261004191000_minecraft_server_seasons', $migration->id()->value());
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));

        foreach ([
            'forwext_minecraft_server_seasons',
            'forwext_minecraft_server_season_entries',
            'uq_forwext_minecraft_server_season_slug',
            'fk_forwext_minecraft_server_season_entry_season',
            'fk_forwext_minecraft_server_season_entry_server',
            'ON DELETE CASCADE',
        ] as $contract) {
            self::assertStringContainsString($contract, $sql);
        }
    }

    public function testVerificationRequiresTablesForeignKeysAndUniqueSlug(): void
    {
        $database = new MinecraftServerSeasonsRecordingDatabase();
        $database->fetchValues = [2, 2, 1];

        self::assertTrue(
            (new CreateMinecraftServerSeasons())->verify(new MigrationContext($database))->isPassed(),
        );
    }
}

final class MinecraftServerSeasonsRecordingDatabase implements TransactionalQueryExecutor
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
