<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateRoleAppearanceTable;
use PHPUnit\Framework\TestCase;

final class RoleAppearanceMigrationTest extends TestCase
{
    public function testMigrationCreatesAppearanceTableWithCascadingRoleReference(): void
    {
        $database = new RoleAppearanceMigrationRecordingDatabase();
        $migration = new CreateRoleAppearanceTable();

        $migration->up(new MigrationContext($database));

        self::assertCount(1, $database->executedSql);
        $sql = $database->executedSql[0];
        self::assertStringContainsString('forwext_role_appearances', $sql);
        self::assertStringContainsString('gradient_from', $sql);
        self::assertStringContainsString('show_mobile', $sql);
        self::assertStringContainsString('show_profile', $sql);
        self::assertStringContainsString('show_posts', $sql);
        self::assertStringContainsString('REFERENCES `forwext_roles` (`role_id`) ON DELETE CASCADE', $sql);
    }

    public function testVerificationRequiresTableAndCascadingForeignKey(): void
    {
        $database = new RoleAppearanceMigrationRecordingDatabase();
        $database->fetchValues = [1, 1];

        $result = (new CreateRoleAppearanceTable())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
    }

    public function testVerificationFailsClosedWhenForeignKeyIsMissing(): void
    {
        $database = new RoleAppearanceMigrationRecordingDatabase();
        $database->fetchValues = [1, 0];

        $result = (new CreateRoleAppearanceTable())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class RoleAppearanceMigrationRecordingDatabase implements TransactionalQueryExecutor
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
