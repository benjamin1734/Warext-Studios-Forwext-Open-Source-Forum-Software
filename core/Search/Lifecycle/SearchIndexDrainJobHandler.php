<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final readonly class SearchIndexDrainJobHandler
{
    public function __construct(private SearchIndexLifecycleService $lifecycle)
    {
    }

    public function jobType(): string
    {
        return SearchIndexMaintenanceTasks::DRAIN_JOB_TYPE;
    }

    public function handle(string $payload, DateTimeImmutable $now): int
    {
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Search index drain job payload is invalid JSON.', previous: $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Search index drain job payload must be an object.');
        }

        $limit = $decoded['limit'] ?? 100;
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            throw new RuntimeException('Search index drain job limit is invalid.');
        }

        return $this->lifecycle->drain($now, $limit)->processed;
    }
}
