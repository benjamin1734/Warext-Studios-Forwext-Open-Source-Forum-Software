<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\IntegrateContentGovernancePermissions;
use PHPUnit\Framework\TestCase;

final class ContentGovernancePermissionMigrationTest extends TestCase
{
    public function testMigrationNormalizesLegacyFreshnessRulesWithoutOverwritingCanonicalRules(): void
    {
        $database = new ContentGovernanceMigrationRecordingDatabase();
        $migration = new IntegrateContentGovernancePermissions();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260919110000_content_governance_integration', $migration->id()->value());
        self::assertTrue($migration->isIdempotent());
        self::assertTrue($migration->isTransactional());
        self::assertCount(25, $database->queries);

        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->queries,
        ));
        self::assertStringContainsString('INSERT IGNORE INTO forwext_permission_global_rules', $sql);
        self::assertStringContainsString('INSERT IGNORE INTO forwext_permission_node_rules', $sql);
        self::assertStringContainsString('INSERT IGNORE INTO forwext_permission_template_rules', $sql);
        self::assertStringContainsString('DELETE FROM forwext_permissions WHERE permission_key=:legacy', $sql);

        $mappings = [];
        foreach ($database->queries as $query) {
            if (isset($query->parameters['legacy'], $query->parameters['canonical'])) {
                $mappings[(string) $query->parameters['legacy']] = (string) $query->parameters['canonical'];
            }
        }
        self::assertSame([
            'freshness.renew_own'=>'forum.thread.freshness.renew_own',
            'freshness.review'=>'forum.thread.freshness.review',
            'freshness.manage'=>'forum.thread.freshness.manage_policy',
        ], $mappings);
    }

    public function testVerificationRequiresCanonicalKeysAndZeroLegacyDefinitionsOrRules(): void
    {
        $database = new ContentGovernanceMigrationRecordingDatabase();
        $database->fetchValues = [4, 0, 0, 0, 0];

        $result = (new IntegrateContentGovernancePermissions())
            ->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(5, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenLegacyDefinitionRemains(): void
    {
        $database = new ContentGovernanceMigrationRecordingDatabase();
        $database->fetchValues = [4, 1, 0, 0, 0];

        $result = (new IntegrateContentGovernancePermissions())
            ->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class ContentGovernanceMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $queries = [];

    /** @var list<int> */
    public array $fetchValues = [];

    /** @var list<CompiledQuery> */
    public array $verificationQueries = [];

    public function execute(CompiledQuery $query): int
    {
        $this->queries[] = $query;
        return 1;
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
