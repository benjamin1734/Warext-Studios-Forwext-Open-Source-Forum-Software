<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use DateInterval;
use DateTimeImmutable;
use Forwext\Core\Search\SearchDriver;
use Forwext\Core\Search\SearchException;
use Throwable;

final readonly class SearchIndexLifecycleService
{
    public function __construct(
        private SearchDriver $driver,
        private SearchContentSourceRegistry $sources,
        private SearchIndexChangeStore $changes,
    ) {
    }

    public function synchronize(string $documentType, string $documentId): void
    {
        $source = $this->sources->require($documentType);
        $document = $source->document($documentId);
        if ($document === null) {
            $this->driver->delete($documentType, $documentId);
            return;
        }
        if ($document->documentType !== $documentType || $document->documentId !== $documentId) {
            throw new SearchException('Search content source returned a mismatched document identity.');
        }
        $this->driver->upsert($document);
    }

    public function drain(DateTimeImmutable $now, int $limit = 100): SearchIndexDrainResult
    {
        $processed = $succeeded = $failed = $superseded = 0;
        foreach ($this->changes->claimDue($now, $limit) as $change) {
            ++$processed;
            try {
                $this->synchronize($change->documentType, $change->documentId);
                if ($this->changes->acknowledge($change)) {
                    ++$succeeded;
                } else {
                    ++$superseded;
                }
            } catch (Throwable) {
                $attempts = $change->attempts + 1;
                $delay = min(3600, 15 * (2 ** min(8, max(0, $attempts - 1))));
                $retryAt = $now->add(new DateInterval('PT' . $delay . 'S'));
                if ($this->changes->retry($change, min(20, $attempts), $retryAt, 'index_sync_failed')) {
                    ++$failed;
                } else {
                    ++$superseded;
                }
            }
        }
        return new SearchIndexDrainResult($processed, $succeeded, $failed, $superseded);
    }

    public function rebuild(
        string $documentType,
        ?string $afterId = null,
        int $limit = 100,
    ): SearchRebuildResult {
        if ($limit < 1 || $limit > 500) {
            throw new SearchException('Search rebuild batch size must be between 1 and 500.');
        }
        $source = $this->sources->require($documentType);
        $page = $source->scan($afterId, $limit);
        foreach ($page->documentIds as $documentId) {
            $this->synchronize($documentType, $documentId);
        }
        return new SearchRebuildResult($documentType, count($page->documentIds), $page->nextCursor);
    }
}
