<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreatePermissionEngineTables;
use PHPUnit\Framework\TestCase;

final class PermissionEngineMigrationTest extends TestCase
{
    public function testMigrationCreatesDefinitionGlobalAndNodeTables(): void
    {
        $database = new PermissionEngineMigrationRecordingDatabase();
        (new CreatePermissionEngineTables())->up(new MigrationContext($database));

        self::assertCount(3, $database->executedSql);
        $sql = implode("\n", $database->executedSql);
        self::assertStringContainsString('forwext_permissions', $sql);
        self::assertStringContainsString('forwext_permission_global_rules', $sql);
        self::assertStringContainsString('forwext_permission_node_rules', $sql);
        self::assertStringContainsString('PRIMARY KEY (`node_id`, `subject_type`, `subject_id`, `permission_key`)', $sql);
        self::assertStringContainsString('BIGINT UNSIGNED NULL', $sql);
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);
    }

    public function testVerificationFailsClosedWhenNodeUniquenessIsIncomplete(): void
    {
        $database = new PermissionEngineMigrationRecordingDatabase();
        $database->fetchValues = [3, 3];

        $result = (new CreatePermissionEngineTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class PermissionEngineMigrationRecordingDatabase implements TransactionalQueryExecutor
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
