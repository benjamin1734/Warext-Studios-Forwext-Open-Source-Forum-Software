<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Resilience;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Content\Ai\AiModerationForumPolicy;
use Forwext\Core\Content\Ai\AiModerationProviderRegistry;
use Forwext\Core\Content\Ai\AiModerationRequest;
use Forwext\Core\Content\Ai\AiModerationService;
use Forwext\Core\Content\Pipeline\ContentPipelineContext;
use Forwext\Core\Content\Pipeline\ContentPipelinePersisted;
use Forwext\Core\Content\Pipeline\ForumContentPipelineFactory;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\SearchContentSourceRegistry;
use Forwext\Core\Search\Lifecycle\SearchIndexChange;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use Forwext\Core\Search\Lifecycle\SearchIndexLifecycleService;
use Forwext\Core\Search\ResilientSearchDriver;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchDriver;
use Forwext\Core\Search\SearchException;
use Forwext\Core\Search\SearchHit;
use Forwext\Core\Search\SearchQuery;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class OptionalServiceGracefulDegradationTest extends TestCase
{
    public function testCoreContentPipelinePersistsWithAiDisabledAndNoWorkerRuntime(): void
    {
        $database = new DegradationDatabase();
        $changes = new DegradationChangeStore();
        $pipeline = ForumContentPipelineFactory::create($database, $changes);
        $targetId = $this->id('2');
        $persistedContext = null;

        $value = $pipeline->execute(
            new ContentPipelineContext(
                $this->id('1'),
                'forum.post',
                'Temel forum içeriği worker veya AI olmadan kaydedilmelidir.',
                100000,
            ),
            $this->time(),
            function (ContentPipelineContext $context) use ($targetId, &$persistedContext): ContentPipelinePersisted {
                $persistedContext = $context;
                return new ContentPipelinePersisted((object) ['saved'=>true], 'forum.post', $targetId);
            },
        );

        self::assertTrue($value->saved);
        self::assertInstanceOf(ContentPipelineContext::class, $persistedContext);
        self::assertFalse($persistedContext->requiresReview);
        self::assertSame(['post:' . $targetId->value()], $changes->recorded);
        self::assertSame(1, $database->transactions);
    }

    public function testMissingAiProviderQueuesForHumanReviewInsteadOfBreakingPersistence(): void
    {
        $database = new DegradationDatabase();
        $changes = new DegradationChangeStore();
        $ai = new AiModerationService(new AiModerationProviderRegistry(), 'missing_provider');
        $pipeline = ForumContentPipelineFactory::create(
            $database,
            $changes,
            aiModeration: $ai,
        );
        $targetId = $this->id('3');
        $persistedContext = null;

        $value = $pipeline->execute(
            new ContentPipelineContext(
                $this->id('1'),
                'forum.post',
                'AI sağlayıcısı olmasa da bu içerik güvenli şekilde inceleme kuyruğuna gidebilir.',
                100000,
            ),
            $this->time(),
            function (ContentPipelineContext $context) use ($targetId, &$persistedContext): ContentPipelinePersisted {
                $persistedContext = $context;
                return new ContentPipelinePersisted((object) ['saved'=>true], 'forum.post', $targetId);
            },
        );

        self::assertTrue($value->saved);
        self::assertInstanceOf(ContentPipelineContext::class, $persistedContext);
        self::assertTrue($persistedContext->requiresReview);
        self::assertSame('queue', $persistedContext->attributes['ai.action'] ?? null);
        self::assertSame('provider_unavailable', $persistedContext->attributes['ai.fallback_reason'] ?? null);
        self::assertSame(['post:' . $targetId->value()], $changes->recorded);
    }

    public function testMissingAiPromptVersionFallsBackToHumanReviewAssessment(): void
    {
        $forumId = $this->id('a');
        $ai = new AiModerationService(new AiModerationProviderRegistry(), 'missing_provider');
        $policy = new AiModerationForumPolicy(
            $forumId,
            true,
            'missing_provider',
            'removed.v2',
            true,
            0.25,
            0.50,
            0.85,
            0,
            0,
        );

        $assessment = $ai->evaluateForPolicy(
            new AiModerationRequest('forum.post', 'Normal content'),
            $policy,
        );

        self::assertSame('configuration_error', $assessment->fallbackReason);
        self::assertSame('unavailable', $assessment->model);
        self::assertSame('removed.v2', $assessment->promptVersion);
    }

    public function testSearchQueriesFallBackWhenOptionalPrimaryIsUnavailable(): void
    {
        $fallback = new DegradationSearchDriver();
        $fallback->hits = [new SearchHit('thread', 'native-1', 1.0, 'Native result')];
        $primary = new DegradationSearchDriver();
        $primary->failSearch = true;
        $driver = new ResilientSearchDriver($fallback, $primary);

        $hits = $driver->search(new SearchQuery('forwext', ['public']));

        self::assertCount(1, $hits);
        self::assertSame('native-1', $hits[0]->documentId);
        self::assertSame(1, $primary->searchCalls);
        self::assertSame(1, $fallback->searchCalls);
    }

    public function testSearchLifecycleRetriesPrimaryFailureWhileFallbackIndexStaysCurrent(): void
    {
        $fallback = new DegradationSearchDriver();
        $primary = new DegradationSearchDriver();
        $primary->failUpsert = true;
        $driver = new ResilientSearchDriver($fallback, $primary);

        $source = new DegradationSearchSource();
        $source->documents['thread-1'] = self::document('thread', 'thread-1');
        $sources = new SearchContentSourceRegistry();
        $sources->register($source);

        $changes = new DegradationChangeStore();
        $changes->claimed = [new SearchIndexChange('thread', 'thread-1', 1, 0)];
        $service = new SearchIndexLifecycleService($driver, $sources, $changes);

        $result = $service->drain($this->time(), 10);

        self::assertSame(1, $result->processed);
        self::assertSame(1, $result->failed);
        self::assertSame(['thread:thread-1'], $fallback->upserts);
        self::assertSame(['thread:thread-1'], $primary->upserts);
        self::assertSame(1, $changes->retryCalls);
        self::assertSame('index_sync_failed', $changes->lastErrorCode);
    }

    private function id(string $seed): EntityId
    {
        return EntityId::fromString(str_repeat($seed, 32));
    }

    private function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-19 10:15:00', new DateTimeZone('UTC'));
    }

    private static function document(string $type, string $id): SearchDocument
    {
        return new SearchDocument(
            $type,
            $id,
            'Title ' . $id,
            'Body',
            ['public'],
            new DateTimeImmutable('2026-09-19 10:15:00+00:00'),
        );
    }
}

final class DegradationDatabase implements TransactionalQueryExecutor
{
    public int $transactions = 0;
    private int $depth = 0;

    public function execute(CompiledQuery $query): int { return 1; }
    public function fetchOne(CompiledQuery $query): ?array { return null; }
    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return null; }
    public function inTransaction(): bool { return $this->depth > 0; }

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

final class DegradationChangeStore implements SearchIndexChangeStore
{
    /** @var list<string> */
    public array $recorded = [];
    /** @var list<SearchIndexChange> */
    public array $claimed = [];
    public int $retryCalls = 0;
    public ?string $lastErrorCode = null;

    public function record(string $documentType, string $documentId): void
    {
        $this->recorded[] = $documentType . ':' . $documentId;
    }

    public function claimDue(DateTimeImmutable $now, int $limit, int $leaseSeconds = 120): array
    {
        return $this->claimed;
    }

    public function acknowledge(SearchIndexChange $change): bool
    {
        return true;
    }

    public function retry(
        SearchIndexChange $change,
        int $attempts,
        DateTimeImmutable $availableAt,
        string $errorCode,
    ): bool {
        ++$this->retryCalls;
        $this->lastErrorCode = $errorCode;
        return true;
    }
}

final class DegradationSearchDriver implements SearchDriver
{
    /** @var list<SearchHit> */
    public array $hits = [];
    /** @var list<string> */
    public array $upserts = [];
    /** @var list<string> */
    public array $deletes = [];
    public bool $failSearch = false;
    public bool $failUpsert = false;
    public bool $failDelete = false;
    public int $searchCalls = 0;

    public function upsert(SearchDocument $document): void
    {
        $this->upserts[] = $document->documentType . ':' . $document->documentId;
        if ($this->failUpsert) {
            throw new RuntimeException('optional search unavailable');
        }
    }

    public function delete(string $documentType, string $documentId): bool
    {
        $this->deletes[] = $documentType . ':' . $documentId;
        if ($this->failDelete) {
            throw new RuntimeException('optional search unavailable');
        }
        return true;
    }

    public function search(SearchQuery $query): array
    {
        ++$this->searchCalls;
        if ($this->failSearch) {
            throw new RuntimeException('optional search unavailable');
        }
        return $this->hits;
    }
}

final class DegradationSearchSource implements SearchContentSource
{
    /** @var array<string,SearchDocument> */
    public array $documents = [];

    public function documentType(): string
    {
        return 'thread';
    }

    public function document(string $documentId): ?SearchDocument
    {
        return $this->documents[$documentId] ?? null;
    }

    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return new SearchContentPage([], null);
    }
}
