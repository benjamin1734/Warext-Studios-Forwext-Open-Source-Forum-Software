<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\RateLimit;

final readonly class RateLimitResult
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $remaining,
        public int $resetAt,
    ) {
    }

    public function retryAfter(int $now): int
    {
        return max(1, $this->resetAt - $now);
    }
}
