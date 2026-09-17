<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Search;

use Closure;
use DateTimeImmutable;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Search\Lifecycle\DatabaseSearchIndexChangeStore;
use Forwext\Database\Migrations\Core\CreateSearchIndexLifecycleTables;
use PHPUnit\Framework\TestCase;

final class SearchIndexPersistenceTest extends TestCase
{
    public function testChangeStoreClaimsDueWorkWithTransactionAndLease(): void
    {
        $database = new LifecycleRecordingDatabase();
        $database->allRows = [[
            'document_type' => 'post',
            'document_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'revision' => '3',
            'attempts' => '1',
        ]];
        $store = new DatabaseSearchIndexChangeStore($database);

        $changes = $store->claimDue(new DateTimeImmutable('2026-09-17 12:00:00+00:00'), 25, 90);

        self::assertCount(1, $changes);
        self::assertSame(3, $changes[0]->revision);
        self::assertSame(1, $database->transactions);
        self::assertStringContainsString('FOR UPDATE', $database->queries[0]->sql);
        self::assertStringContainsString('`locked_until_utc`', $database->queries[1]->sql);
        self::assertSame('2026-09-17 12:01:30.000000', $database->queries[1]->parameters['locked_until_utc']);
    }

    public function testLifecycleMigrationContainsDependencyInvalidationAndCascadeCleanup(): void
    {
        $database = new LifecycleRecordingDatabase();
        // ensureLeaseColumn, then verify(table, lease column, triggers, permission, rules)
        $database->values = [1, 1, 1, 13, 1, 5];
        $migration = new CreateSearchIndexLifecycleTables();
        $context = new MigrationContext($database);

        $migration->up($context);
        $verification = $migration->verify($context);
        $sql = implode("\n", array_map(static fn (CompiledQuery $query): string => $query->sql, $database->queries));

        self::assertTrue($verification->isPassed());
        self::assertStringContainsString('`locked_until_utc` DATETIME(6) NULL', $sql);
        self::assertStringContainsString('trg_forwext_search_thread_before_delete', $sql);
        self::assertStringContainsString('BEFORE DELETE ON `forwext_threads`', $sql);
        self::assertStringContainsString("SELECT 'post', p.`post_id`", $sql);
        self::assertStringContainsString('OLD.`forum_node_id` <=> NEW.`forum_node_id`', $sql);
        self::assertStringContainsString('OLD.`title` <=> NEW.`title`', $sql);
        self::assertStringContainsString('OLD.`moderation_state` <=> NEW.`moderation_state`', $sql);
        self::assertStringContainsString('OLD.`deleted` <=> NEW.`deleted`', $sql);
        self::assertStringContainsString('OLD.`merged_into_thread_id` <=> NEW.`merged_into_thread_id`', $sql);
    }
}

final class LifecycleRecordingDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */
    public array $queries = [];
    /** @var list<array<string, mixed>> */
    public array $allRows = [];
    /** @var list<mixed> */
    public array $values = [];
    public int $transactions = 0;
    private int $depth = 0;

    public function execute(CompiledQuery $query): int
    {
        $this->queries[] = $query;
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $this->queries[] = $query;
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->queries[] = $query;
        $rows = $this->allRows;
        $this->allRows = [];
        return $rows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->queries[] = $query;
        return array_shift($this->values);
    }

    public function inTransaction(): bool
    {
        return $this->depth > 0;
    }

    public function transaction(Closure $callback): mixed
    {
        ++$this->transactions;
        ++$this->depth;
        try {
            return $callback($this);
        } finally {
            --$this->depth;
        }
    }
}
