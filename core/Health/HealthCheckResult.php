<?php

declare(strict_types=1);

namespace Forwext\Core\Health;

use InvalidArgumentException;

final readonly class HealthCheckResult
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message = '',
        public array $details = [],
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Health check result name is invalid.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message) === 1) {
            throw new InvalidArgumentException('Health check message contains unsupported control characters.');
        }
    }
}
