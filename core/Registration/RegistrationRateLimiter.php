<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use DateTimeImmutable;

interface RegistrationRateLimiter
{
    public function consume(
        string $scope,
        string $fingerprint,
        int $limit,
        int $windowSeconds,
        DateTimeImmutable $now,
    ): bool;
}
