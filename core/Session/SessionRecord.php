<?php

declare(strict_types=1);

namespace Forwext\Core\Session;

use DateTimeImmutable;

final readonly class SessionRecord
{
    public function __construct(
        public string $payload,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
