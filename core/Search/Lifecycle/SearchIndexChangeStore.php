<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use DateTimeImmutable;

interface SearchIndexChangeStore
{
    public function record(string $documentType, string $documentId): void;

    /** @return list<SearchIndexChange> */
    public function claimDue(DateTimeImmutable $now, int $limit, int $leaseSeconds = 120): array;

    public function acknowledge(SearchIndexChange $change): bool;

    public function retry(
        SearchIndexChange $change,
        int $attempts,
        DateTimeImmutable $availableAt,
        string $errorCode,
    ): bool;
}
