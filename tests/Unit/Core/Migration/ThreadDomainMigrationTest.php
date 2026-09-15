<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateThreadDomainTables;
use PHPUnit\Framework\TestCase;

final class ThreadDomainMigrationTest extends TestCase
{
    public function testMigrationCreatesThreadSchemaPermissionsAndStarterRules(): void
    {
        $database = new ThreadDomainMigrationRecordingDatabase();
        $migration = new CreateThreadDomainTables();

        $migration->up(new MigrationContext($database));

        self::assertSame('20260915235945_thread_domain', $migration->id()->value());
        self::assertCount(27, $database->executedQueries);
        $sql = implode("\n", array_map(
            static fn (CompiledQuery $query): string => $query->sql,
            $database->executedQueries,
        ));
        self::assertStringContainsString('forwext_thread_types', $sql);
        self::assertStringContainsString('forwext_threads', $sql);
        self::assertStringContainsString('fk_forwext_threads_forum', $sql);
        self::assertStringContainsString('ON DELETE RESTRICT', $sql);
        self::assertStringContainsString('fk_forwext_threads_author', $sql);
        self::assertStringContainsString('ON DELETE SET NULL', $sql);
        self::assertStringContainsString('fk_forwext_threads_type', $sql);

        $permissionKeys = [];
        $templateEffects = [];
        foreach ($database->executedQueries as $query) {
            if (isset($query->parameters['description'], $query->parameters['permission_key'])) {
                $permissionKeys[] = (string) $query->parameters['permission_key'];
            }
            if (isset($query->parameters['template_key'], $query->parameters['permission_key'])) {
                $templateEffects[
                    (string) $query->parameters['template_key'] . ':' . (string) $query->parameters['permission_key']
                ] = (string) $query->parameters['effect'];
            }
        }

        self::assertSame([
            'forum.thread.lock',
            'forum.thread.sticky',
            'forum.thread.feature',
            'forum.thread.moderate',
        ], $permissionKeys);
        self::assertCount(20, $templateEffects);
        self::assertSame('deny', $templateEffects['member:forum.thread.lock']);
        self::assertSame('deny', $templateEffects['verified:forum.thread.moderate']);
        self::assertSame('allow', $templateEffects['moderator:forum.thread.sticky']);
        self::assertSame('allow', $templateEffects['administrator:forum.thread.feature']);
    }

    public function testVerificationPassesOnlyWithCoreTypePermissionsTemplatesAndForeignKeys(): void
    {
        $database = new ThreadDomainMigrationRecordingDatabase();
        $database->fetchValues = [2, 1, 4, 20, 3, 1];

        $result = (new CreateThreadDomainTables())->verify(new MigrationContext($database));

        self::assertTrue($result->isPassed());
        self::assertCount(6, $database->verificationQueries);
    }

    public function testVerificationFailsClosedWhenStarterTemplateCoverageIsIncomplete(): void
    {
        $database = new ThreadDomainMigrationRecordingDatabase();
        $database->fetchValues = [2, 1, 4, 19, 3, 1];

        $result = (new CreateThreadDomainTables())->verify(new MigrationContext($database));

        self::assertFalse($result->isPassed());
    }
}

final class ThreadDomainMigrationRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $executedQueries = [];
    /** @var list<CompiledQuery> */
    public array $verificationQueries = [];
    /** @var list<int> */
    public array $fetchValues = [];

    public function execute(CompiledQuery $query): int
    {
        $this->executedQueries[] = $query;
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
