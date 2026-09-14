<?php

declare(strict_types=1);

namespace Forwext\Core\Health;

final readonly class HealthReport
{
    /** @param non-empty-list<HealthCheckResult> $checks */
    public function __construct(
        public HealthStatus $status,
        public array $checks,
    ) {
    }
}
