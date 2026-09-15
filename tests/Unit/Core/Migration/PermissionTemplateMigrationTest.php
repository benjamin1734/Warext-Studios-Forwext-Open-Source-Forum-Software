<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreatePermissionTemplateTables;
use PHPUnit\Framework\TestCase;

final class PermissionTemplateMigrationTest extends TestCase
{
    public function testMigrationCreatesTemplateSchemaAndSeedsFiveProfiles(): void
    {
        $database = new PermissionTemplateMigrationRecordingDatabase();
        (new CreatePermissionTemplateTables())->up(new MigrationContext($database));

        $sql = implode("\n", $database->executedSql);
        self::assertStringContainsString('forwext_permission_templates', $sql);
        self::assertStringContainsString('forwext_permission_template_rules', $sql);
        self::assertStringContainsString("'new_user'", $sql);
        self::assertStringContainsString("'member'", $sql);
        self::assertStringContainsString("'verified'", $sql);
        self::assertStringContainsString("'moderator'", $sql);
        self::assertStringContainsString("'administrator'", $sql);
        self::assertStringContainsString("'forum.content.daily_limit', 'numeric'", $sql);
        self::assertCount(44, $database->executedSql);
    }

    public function testVerificationRequiresAllProfilesAndFortySeedRules(): void
    {
        $database = new PermissionTemplateMigrationRecordingDatabase();
        $database->fetchValues = [2, 5, 39];

        $result = (new CreatePermissionTemplateTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class PermissionTemplateMigrationRecordingDatabase implements TransactionalQueryExecutor
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
