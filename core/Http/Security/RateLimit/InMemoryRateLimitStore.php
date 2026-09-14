<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\RateLimit;

final class InMemoryRateLimitStore implements RateLimitStore
{
    /** @var array<string, array{start: int, count: int}> */
    private array $buckets = [];

    public function consume(string $key, RateLimitPolicy $policy, int $now): RateLimitResult
    {
        $bucketKey = hash('sha256', $policy->name . "\0" . $key);
        $bucket = $this->buckets[$bucketKey] ?? null;

        if ($bucket === null || $now >= $bucket['start'] + $policy->windowSeconds) {
            $bucket = ['start' => $now, 'count' => 0];
        }

        if ($bucket['count'] >= $policy->limit) {
            $this->buckets[$bucketKey] = $bucket;
            return new RateLimitResult(false, $policy->limit, 0, $bucket['start'] + $policy->windowSeconds);
        }

        ++$bucket['count'];
        $this->buckets[$bucketKey] = $bucket;

        return new RateLimitResult(
            true,
            $policy->limit,
            max(0, $policy->limit - $bucket['count']),
            $bucket['start'] + $policy->windowSeconds,
        );
    }
}
