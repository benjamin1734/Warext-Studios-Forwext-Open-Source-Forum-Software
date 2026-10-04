<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateMinecraftServerManagement;
use PHPUnit\Framework\TestCase;

final class MinecraftServerManagementMigrationTest extends TestCase
{
    public function testMigrationCreatesClaimsOwnershipAuditAndPermissionDefaults(): void
    {
        $database = new MinecraftServerManagementRecordingDatabase();
        $migration = new CreateMinecraftServerManagement();

        $migration->up(new MigrationContext($database));

        self::assertSame('20261004194500_minecraft_server_management', $migration->id()->value());
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        $boundValues = [];
        foreach ($database->executedQueries as $query) {
            foreach ($query->parameters as $value) {
                if (is_string($value)) {
                    $boundValues[] = $value;
                }
            }
        }
        $contractText = $sql . "\n" . implode("\n", $boundValues);

        foreach ([
            'forwext_minecraft_server_claims',
            'forwext_minecraft_server_ownership_events',
            'fk_forwext_minecraft_server_claim_server',
            'fk_forwext_minecraft_server_owner_event_server',
            'minecraft_server.manage_own',
            'minecraft_server.claim',
            'minecraft_server.transfer',
            'forwext_permission_template_rules',
        ] as $contract) {
            self::assertStringContainsString($contract, $contractText);
        }
    }

    public function testVerificationRequiresTablesForeignKeysPermissionsAndTemplates(): void
    {
        $database = new MinecraftServerManagementRecordingDatabase();
        $database->fetchValues = [2, 7, 3, 15];

        self::assertTrue(
            (new CreateMinecraftServerManagement())->verify(new MigrationContext($database))->isPassed(),
        );
    }
}

final class MinecraftServerManagementRecordingDatabase implements TransactionalQueryExecutor
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
