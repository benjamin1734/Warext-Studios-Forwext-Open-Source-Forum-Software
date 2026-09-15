<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateRoleGroupTables;
use PHPUnit\Framework\TestCase;

final class RoleGroupMigrationTest extends TestCase
{
    public function testMigrationCreatesNormalizedRoleAndGroupAssignmentTables(): void
    {
        $database = new RoleGroupMigrationRecordingDatabase();
        $migration = new CreateRoleGroupTables();

        $migration->up(new MigrationContext($database));

        self::assertCount(5, $database->executedSql);
        $sql = implode("\n", $database->executedSql);
        self::assertStringContainsString('forwext_user_groups', $sql);
        self::assertStringContainsString('forwext_roles', $sql);
        self::assertStringContainsString('forwext_user_primary_groups', $sql);
        self::assertStringContainsString('PRIMARY KEY (`user_id`)', $sql);
        self::assertStringContainsString('forwext_user_secondary_groups', $sql);
        self::assertStringContainsString('forwext_user_role_assignments', $sql);
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);
    }

    public function testVerificationFailsClosedWhenExpectedSchemaIsIncomplete(): void
    {
        $database = new RoleGroupMigrationRecordingDatabase();
        $database->fetchValues = [4, 0];

        $result = (new CreateRoleGroupTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class RoleGroupMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<string> */
    public array $executedSql = [];

    /** @var list<int> */
    public array $fetchValues = [];

    public function execute(CompiledQuery $query): int
    {
        $this->executedSql[] = $query->sql;
        return 0;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
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
