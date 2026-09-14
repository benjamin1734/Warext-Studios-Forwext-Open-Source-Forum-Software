<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Database;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\OptimisticLockException;
use Forwext\Core\Database\OptimisticLockingUpdater;
use Forwext\Core\Database\Pagination\PageRequest;
use Forwext\Core\Database\Pagination\Paginator;
use Forwext\Core\Database\Query\SelectQueryBuilder;
use Forwext\Core\Database\QueryExecutor;
use PHPUnit\Framework\TestCase;

final class DatabaseServicesTest extends TestCase
{
    public function testPaginatorUsesSeparateCountAndPagedQuery(): void
    {
        $executor = new RecordingQueryExecutor();
        $executor->countValue = '25';
        $executor->rows = [
            ['id' => 11],
            ['id' => 12],
        ];

        $query = (new SelectQueryBuilder('threads', ['id']))->whereEquals('forum_id', 3);
        $page = (new Paginator($executor))->paginate($query, new PageRequest(page: 2, perPage: 10));

        self::assertSame(25, $page->total);
        self::assertSame(3, $page->lastPage);
        self::assertTrue($page->hasNextPage());
        self::assertTrue($page->hasPreviousPage());
        self::assertSame([['id' => 11], ['id' => 12]], $page->items);
        self::assertCount(1, $executor->valueQueries);
        self::assertCount(1, $executor->allQueries);
        self::assertStringContainsString('COUNT(*)', $executor->valueQueries[0]->sql);
        self::assertStringContainsString('LIMIT 10 OFFSET 10', $executor->allQueries[0]->sql);
    }

    public function testOptimisticUpdaterIncrementsVersionOnExactlyOneRow(): void
    {
        $executor = new RecordingQueryExecutor();
        $executor->affectedRows = 1;
        $updater = new OptimisticLockingUpdater($executor);

        $newVersion = $updater->update(
            'threads',
            'id',
            42,
            'version',
            7,
            ['title' => 'Updated title'],
        );

        self::assertSame(8, $newVersion);
        self::assertCount(1, $executor->executeQueries);
        $compiled = $executor->executeQueries[0];
        self::assertStringContainsString('`version` = :s2', $compiled->sql);
        self::assertStringContainsString('`id` = :w1', $compiled->sql);
        self::assertStringContainsString('`version` = :w2', $compiled->sql);
        self::assertSame(8, $compiled->parameters['s2']);
        self::assertSame(42, $compiled->parameters['w1']);
        self::assertSame(7, $compiled->parameters['w2']);
    }

    public function testOptimisticUpdaterThrowsWhenVersionNoLongerMatches(): void
    {
        $executor = new RecordingQueryExecutor();
        $executor->affectedRows = 0;

        $this->expectException(OptimisticLockException::class);
        (new OptimisticLockingUpdater($executor))->update(
            'threads',
            'id',
            42,
            'version',
            7,
            ['title' => 'Stale update'],
        );
    }
}

final class RecordingQueryExecutor implements QueryExecutor
{
    public int $affectedRows = 0;
    public int|string $countValue = 0;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<CompiledQuery> */
    public array $executeQueries = [];

    /** @var list<CompiledQuery> */
    public array $valueQueries = [];

    /** @var list<CompiledQuery> */
    public array $allQueries = [];

    public function execute(CompiledQuery $query): int
    {
        $this->executeQueries[] = $query;
        return $this->affectedRows;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        unset($query);
        return $this->rows[0] ?? null;
    }

    public function fetchAll(CompiledQuery $query): array
    {
        $this->allQueries[] = $query;
        return $this->rows;
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        $this->valueQueries[] = $query;
        return $this->countValue;
    }
}
