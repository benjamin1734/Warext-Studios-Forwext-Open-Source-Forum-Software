<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\RateLimit;

final readonly class RateLimitPolicy
{
    public function __construct(
        public string $name,
        public int $limit,
        public int $windowSeconds,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $name) !== 1) {
            throw new RateLimitException('Rate-limit policy name is invalid.');
        }
        if ($limit < 1 || $limit > 1_000_000) {
            throw new RateLimitException('Rate-limit request limit is out of range.');
        }
        if ($windowSeconds < 1 || $windowSeconds > 86_400) {
            throw new RateLimitException('Rate-limit window is out of range.');
        }
    }
}
