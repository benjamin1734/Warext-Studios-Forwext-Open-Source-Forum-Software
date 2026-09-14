<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Login;

use DateTimeImmutable;

interface AuthenticationRateLimiter
{
    public function consume(string $fingerprint, int $limit, int $windowSeconds, DateTimeImmutable $now): bool;
}
