<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use DateTimeImmutable;

interface SupportSubmissionRateLimiter
{
    public function consume(
        string $scope,
        string $fingerprint,
        int $limit,
        int $windowSeconds,
        DateTimeImmutable $now,
    ): bool;
}
