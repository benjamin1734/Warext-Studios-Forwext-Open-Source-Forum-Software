<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateForumNodeTables;
use PHPUnit\Framework\TestCase;

final class ForumNodeMigrationTest extends TestCase
{
    public function testMigrationCreatesHierarchyAndForumSettingsIntegrity(): void
    {
        $database = new ForumNodeMigrationRecordingDatabase();
        $migration = new CreateForumNodeTables();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260915235930_forum_nodes', $migration->id()->value());
        self::assertCount(2, $database->executedSql);
        $sql = implode("\n", $database->executedSql);
        self::assertStringContainsString('forwext_nodes', $sql);
        self::assertStringContainsString('forwext_forum_settings', $sql);
        self::assertStringContainsString('uq_forwext_nodes_slug', $sql);
        self::assertStringContainsString('fk_forwext_nodes_parent', $sql);
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);
        self::assertStringContainsString('fk_forwext_forum_settings_node', $sql);
        self::assertStringContainsString('ON DELETE CASCADE', $sql);
    }

    public function testVerificationRequiresBothTablesAndIntegrityConstraints(): void
    {
        $database = new ForumNodeMigrationRecordingDatabase();
        $database->fetchValues = [2, 1, 1, 1];

        $result = (new CreateForumNodeTables())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(4, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenUniqueSlugIndexIsMissing(): void
    {
        $database = new ForumNodeMigrationRecordingDatabase();
        $database->fetchValues = [2, 1, 0, 1];

        $result = (new CreateForumNodeTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class ForumNodeMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<string> */
    public array $executedSql = [];

    /** @var list<int> */
    public array $fetchValues = [];

    /** @var list<CompiledQuery> */
    public array $verificationQueries = [];

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
        $this->verificationQueries[] = $query;
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
