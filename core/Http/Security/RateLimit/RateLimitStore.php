<?php

declare(strict_types=1);

namespace Forwext\Core\Http\Security\RateLimit;

interface RateLimitStore
{
    public function consume(string $key, RateLimitPolicy $policy, int $now): RateLimitResult;
}
