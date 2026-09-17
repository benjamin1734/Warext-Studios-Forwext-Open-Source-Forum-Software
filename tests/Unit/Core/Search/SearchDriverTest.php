<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Search;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Search\External\ExternalSearchClient;
use Forwext\Core\Search\External\ExternalSearchDriver;
use Forwext\Core\Search\NativeDatabaseSearchDriver;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchHit;
use Forwext\Core\Search\SearchQuery;
use Forwext\Database\Migrations\Core\CreateSearchIndexTables;
use PHPUnit\Framework\TestCase;

final class SearchDriverTest extends TestCase
{
    public function testSearchQueryNormalizesTextAndRequiresAccessScopes(): void
    {
        $query = new SearchQuery('  hello world  ', ['public'], ['thread'], 'tr-TR', 25, 10);

        self::assertSame('hello world', $query->text);
        self::assertSame(['public'], $query->accessScopes);
        self::assertSame(['thread'], $query->documentTypes);
    }

    public function testNativeDriverIndexesScopesAndKeepsQueryTextParameterized(): void
    {
        $database = new RecordingSearchDatabase();
        $database->searchRows = [
            ['document_type' => 'thread', 'document_id' => '42', 'score' => '1.75'],
        ];
        $driver = new NativeDatabaseSearchDriver($database);
        $document = new SearchDocument(
            'thread',
            '42',
            'Hello',
            'A searchable body',
            ['public', 'node:7'],
            new DateTimeImmutable('2026-09-14 20:00:00', new DateTimeZone('UTC')),
            'tr-TR',
        );

        $driver->upsert($document);
        $hits = $driver->search(new SearchQuery('hello', ['public'], ['thread'], 'tr-TR'));

        self::assertSame(1, $database->transactions);
        self::assertSame(2, $database->scopeInsertions);
        self::assertSame('42', $hits[0]->documentId);
        self::assertSame(1.75, $hits[0]->score);
        self::assertNotNull($database->lastSearchQuery);
        self::assertStringNotContainsString('hello', $database->lastSearchQuery->sql);
        self::assertSame('hello', $database->lastSearchQuery->parameters['match_query']);
        self::assertSame('public', $database->lastSearchQuery->parameters['scope_0']);
    }

    public function testExternalAdapterPreservesContractAndCapsReturnedHits(): void
    {
        $client = new FakeExternalSearchClient();
        $driver = new ExternalSearchDriver($client);
        $query = new SearchQuery('hello', ['public'], limit: 2);

        $hits = $driver->search($query);

        self::assertCount(2, $hits);
        self::assertSame('1', $hits[0]->documentId);
        self::assertSame($query, $client->lastQuery);
    }

    public function testSearchMigrationCreatesTablesAndFulltextIndex(): void
    {
        $database = new RecordingSearchDatabase();
        $database->fetchValues = [2, 2];
        $migration = new CreateSearchIndexTables();
        $context = new MigrationContext($database);

        $migration->up($context);
        $verification = $migration->verify($context);

        self::assertTrue($verification->isPassed());
        self::assertSame(2, $database->createTableQueries);
    }
}

final class RecordingSearchDatabase implements TransactionalQueryExecutor
{
    public int $transactions = 0;
    public int $scopeInsertions = 0;
    public int $createTableQueries = 0;
    /** @var list<array<string, mixed>> */
    public array $searchRows = [];
    /** @var list<int> */
    public array $fetchValues = [];
    public ?CompiledQuery $lastSearchQuery = null;
    private int $transactionDepth = 0;

    public function execute(CompiledQuery $query): int
    {
        if (str_starts_with($query->sql, 'CREATE TABLE IF NOT EXISTS')) {
            ++$this->createTableQueries;
        }
        if (str_starts_with($query->sql, 'INSERT INTO `forwext_search_document_scopes`')) {
            ++$this->scopeInsertions;
        }
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        return null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        if (str_contains($query->sql, 'MATCH(`d`.`title`,`d`.`body`)')) {
            $this->lastSearchQuery = $query;
            return $this->searchRows;
        }
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        return array_shift($this->fetchValues);
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    public function transaction(Closure $callback): mixed
    {
        ++$this->transactions;
        ++$this->transactionDepth;
        try {
            return $callback($this);
        } finally {
            --$this->transactionDepth;
        }
    }
}

final class FakeExternalSearchClient implements ExternalSearchClient
{
    public ?SearchQuery $lastQuery = null;

    public function upsert(SearchDocument $document): void
    {
    }

    public function delete(string $documentType, string $documentId): bool
    {
        return true;
    }

    public function search(SearchQuery $query): array
    {
        $this->lastQuery = $query;
        return [
            new SearchHit('thread', '1', 3.0),
            new SearchHit('thread', '2', 2.0),
            new SearchHit('thread', '3', 1.0),
        ];
    }
}
