<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

final readonly class ThreadFreshnessMaintenanceJobHandler
{
    public function __construct(private ThreadFreshnessService $service)
    {
    }

    public function jobType(): string
    {
        return ThreadFreshnessMaintenanceTasks::JOB_TYPE;
    }

    public function handle(string $payload, DateTimeImmutable $now): int
    {
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Thread freshness maintenance payload is invalid JSON.', previous:$exception);
        }
        $limit = is_array($decoded) ? ($decoded['limit'] ?? 100) : 100;
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            throw new RuntimeException('Thread freshness maintenance limit is invalid.');
        }
        return $this->service->maintain($now, $limit)->scanned;
    }
}
