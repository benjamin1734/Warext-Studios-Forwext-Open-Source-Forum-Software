<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Search;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\SearchContentSourceRegistry;
use Forwext\Core\Search\Lifecycle\SearchIndexChange;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use Forwext\Core\Search\Lifecycle\SearchIndexDrainJobHandler;
use Forwext\Core\Search\Lifecycle\SearchIndexLifecycleService;
use Forwext\Core\Search\Lifecycle\SearchIndexMaintenanceTasks;
use Forwext\Core\Search\Lifecycle\SearchRebuildResult;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchDriver;
use Forwext\Core\Search\SearchHit;
use Forwext\Core\Search\SearchQuery;
use Forwext\Core\Scheduler\SchedulerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SearchIndexLifecycleTest extends TestCase
{
    public function testSynchronizeUpsertsCurrentDocumentAndDeletesMissingDocument(): void
    {
        $driver = new LifecycleFakeDriver();
        $source = new LifecycleFakeSource('thread');
        $source->documents['a'] = self::document('thread', 'a');
        $sources = new SearchContentSourceRegistry();
        $sources->register($source);
        $service = new SearchIndexLifecycleService($driver, $sources, new LifecycleFakeChangeStore());

        $service->synchronize('thread', 'a');
        $service->synchronize('thread', 'missing');

        self::assertSame(['thread:a'], $driver->upserts);
        self::assertSame(['thread:missing'], $driver->deletes);
    }

    public function testDrainRetriesFailureAndDoesNotAcknowledgeSupersededRevision(): void
    {
        $driver = new LifecycleFakeDriver();
        $driver->failIds['fail'] = true;
        $source = new LifecycleFakeSource('thread');
        $source->documents['ok'] = self::document('thread', 'ok');
        $source->documents['fail'] = self::document('thread', 'fail');
        $sources = new SearchContentSourceRegistry();
        $sources->register($source);
        $changes = new LifecycleFakeChangeStore();
        $changes->claimed = [
            new SearchIndexChange('thread', 'ok', 3, 0),
            new SearchIndexChange('thread', 'fail', 7, 1),
        ];
        $changes->acknowledgeResult = false;
        $service = new SearchIndexLifecycleService($driver, $sources, $changes);
        $now = new DateTimeImmutable('2026-09-17 12:00:00', new DateTimeZone('UTC'));

        $result = $service->drain($now, 10);

        self::assertSame(2, $result->processed);
        self::assertSame(0, $result->succeeded);
        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->superseded);
        self::assertSame(2, $changes->retriedAttempts);
        self::assertSame('index_sync_failed', $changes->lastErrorCode);
        self::assertSame('2026-09-17 12:00:30', $changes->retryAt?->format('Y-m-d H:i:s'));
    }

    public function testRebuildUsesBoundedCursorPages(): void
    {
        $driver = new LifecycleFakeDriver();
        $source = new LifecycleFakeSource('user');
        $source->documents['1'] = self::document('user', '1');
        $source->documents['2'] = self::document('user', '2');
        $source->scanPage = new SearchContentPage(['1', '2'], '2');
        $sources = new SearchContentSourceRegistry();
        $sources->register($source);
        $service = new SearchIndexLifecycleService($driver, $sources, new LifecycleFakeChangeStore());

        $result = $service->rebuild('user', null, 2);

        self::assertInstanceOf(SearchRebuildResult::class, $result);
        self::assertSame(2, $result->processed);
        self::assertSame('2', $result->nextCursor);
        self::assertSame(['user:1', 'user:2'], $driver->upserts);
    }

    public function testMaintenanceTaskAndHandlerUseBoundedDatabaseDrain(): void
    {
        $driver = new LifecycleFakeDriver();
        $source = new LifecycleFakeSource('user');
        $sources = new SearchContentSourceRegistry();
        $sources->register($source);
        $changes = new LifecycleFakeChangeStore();
        $changes->claimed = [new SearchIndexChange('user', 'gone', 1, 0)];
        $service = new SearchIndexLifecycleService($driver, $sources, $changes);
        $handler = new SearchIndexDrainJobHandler($service);
        $registry = new SchedulerRegistry();
        SearchIndexMaintenanceTasks::register($registry);

        $processed = $handler->handle('{"limit":25}', new DateTimeImmutable('2026-09-17 12:00:00+00:00'));

        self::assertSame(SearchIndexMaintenanceTasks::DRAIN_JOB_TYPE, $handler->jobType());
        self::assertSame(1, $processed);
        self::assertSame(25, $changes->lastClaimLimit);
        self::assertCount(1, $registry->all());
        self::assertSame('maintenance', $registry->all()[0]->queue->value());
    }

    private static function document(string $type, string $id): SearchDocument
    {
        return new SearchDocument(
            $type,
            $id,
            'Title ' . $id,
            'Body',
            ['public'],
            new DateTimeImmutable('2026-09-17 12:00:00+00:00'),
        );
    }
}

final class LifecycleFakeDriver implements SearchDriver
{
    /** @var list<string> */
    public array $upserts = [];
    /** @var list<string> */
    public array $deletes = [];
    /** @var array<string, bool> */
    public array $failIds = [];

    public function upsert(SearchDocument $document): void
    {
        if (isset($this->failIds[$document->documentId])) {
            throw new RuntimeException('provider unavailable');
        }
        $this->upserts[] = $document->documentType . ':' . $document->documentId;
    }

    public function delete(string $documentType, string $documentId): bool
    {
        $this->deletes[] = $documentType . ':' . $documentId;
        return true;
    }

    public function search(SearchQuery $query): array
    {
        return [];
    }
}

final class LifecycleFakeSource implements SearchContentSource
{
    /** @var array<string, SearchDocument> */
    public array $documents = [];
    public ?SearchContentPage $scanPage = null;

    public function __construct(private readonly string $type)
    {
    }

    public function documentType(): string
    {
        return $this->type;
    }

    public function document(string $documentId): ?SearchDocument
    {
        return $this->documents[$documentId] ?? null;
    }

    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return $this->scanPage ?? new SearchContentPage([], null);
    }
}

final class LifecycleFakeChangeStore implements SearchIndexChangeStore
{
    /** @var list<SearchIndexChange> */
    public array $claimed = [];
    public bool $acknowledgeResult = true;
    public int $retriedAttempts = 0;
    public ?DateTimeImmutable $retryAt = null;
    public ?string $lastErrorCode = null;
    public int $lastClaimLimit = 0;

    public function record(string $documentType, string $documentId): void
    {
    }

    public function claimDue(DateTimeImmutable $now, int $limit, int $leaseSeconds = 120): array
    {
        $this->lastClaimLimit = $limit;
        return $this->claimed;
    }

    public function acknowledge(SearchIndexChange $change): bool
    {
        return $this->acknowledgeResult;
    }

    public function retry(SearchIndexChange $change, int $attempts, DateTimeImmutable $availableAt, string $errorCode): bool
    {
        $this->retriedAttempts = $attempts;
        $this->retryAt = $availableAt;
        $this->lastErrorCode = $errorCode;
        return true;
    }
}
