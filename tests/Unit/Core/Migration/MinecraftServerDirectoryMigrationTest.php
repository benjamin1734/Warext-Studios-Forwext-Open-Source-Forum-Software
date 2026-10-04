<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateMinecraftServerDirectory;
use PHPUnit\Framework\TestCase;

final class MinecraftServerDirectoryMigrationTest extends TestCase
{
    public function testMigrationCreatesDirectoryStatusOwnershipAndPermissions(): void
    {
        $database = new MinecraftServerDirectoryRecordingDatabase();
        $migration = new CreateMinecraftServerDirectory();

        $migration->up(new MigrationContext($database));

        self::assertSame('20261004183000_minecraft_server_directory', $migration->id()->value());
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));

        foreach ([
            'forwext_minecraft_servers',
            'forwext_minecraft_server_status',
            'uq_forwext_minecraft_server_slug',
            'fk_forwext_minecraft_server_owner',
            'minecraft_server.create',
            'minecraft_server.manage_any',
        ] as $contract) {
            self::assertStringContainsString($contract, $sql);
        }

        $templateRules = array_filter(
            $database->executedQueries,
            static fn (CompiledQuery $query): bool => isset($query->parameters['template_key']),
        );
        self::assertCount(10, $templateRules);
    }

    public function testVerificationRequiresTablesForeignKeysPermissionsRulesAndUniqueSlug(): void
    {
        $database = new MinecraftServerDirectoryRecordingDatabase();
        $database->fetchValues = [2, 2, 2, 10, 1];

        self::assertTrue(
            (new CreateMinecraftServerDirectory())->verify(new MigrationContext($database))->isPassed(),
        );
    }
}

final class MinecraftServerDirectoryRecordingDatabase implements TransactionalQueryExecutor
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
