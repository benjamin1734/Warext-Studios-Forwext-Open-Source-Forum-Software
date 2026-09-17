<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Closure;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Database\Migrations\Core\CreateSearchAdvancedFilterTables;
use PHPUnit\Framework\TestCase;

final class SearchAdvancedFilterMigrationTest extends TestCase
{
    public function testMigrationCreatesAttributeIndexAndMetadataInvalidationTriggers(): void
    {
        $database = new SearchAdvancedFilterMigrationDatabase();
        $database->values = [1, 1, 1, 8];
        $migration = new CreateSearchAdvancedFilterTables();
        $context = new MigrationContext($database);

        $migration->up($context);
        $verification = $migration->verify($context);
        $sql = implode("\n", array_map(static fn (CompiledQuery $q): string => $q->sql, $database->queries));

        self::assertTrue($verification->isPassed());
        self::assertStringContainsString('forwext_search_document_attributes', $sql);
        self::assertStringContainsString('idx_forwext_search_attribute_lookup', $sql);
        self::assertStringContainsString('trg_forwext_filtermeta_prefix_insert', $sql);
        self::assertStringContainsString('trg_forwext_filtermeta_tag_delete', $sql);
        self::assertStringContainsString('trg_forwext_filtermeta_thread_type_update', $sql);
        self::assertStringContainsString('trg_forwext_filtermeta_node_visibility_update', $sql);
        self::assertStringContainsString("SELECT 'post',p.`post_id`", $sql);
        self::assertStringContainsString('forwext_search_index_changes', $sql);
    }
}

final class SearchAdvancedFilterMigrationDatabase implements TransactionalQueryExecutor
{
    /** @var list<CompiledQuery> */ public array $queries = [];
    /** @var list<mixed> */ public array $values = [];
    private int $transactionDepth = 0;

    public function execute(CompiledQuery $query): int { $this->queries[] = $query; return 1; }
    public function fetchOne(CompiledQuery $query): ?array { $this->queries[] = $query; return null; }
    public function fetchAll(CompiledQuery $query): array { $this->queries[] = $query; return []; }
    public function fetchValue(CompiledQuery $query): mixed { $this->queries[] = $query; return array_shift($this->values); }
    public function inTransaction(): bool { return $this->transactionDepth > 0; }

    public function transaction(Closure $callback): mixed
    {
        ++$this->transactionDepth;
        try {
            return $callback($this);
        } finally {
            --$this->transactionDepth;
        }
    }
}
