<?php

declare(strict_types=1);

namespace Forwext\Core\Cache;

use DateTimeImmutable;

final readonly class CacheEntry
{
    /** @param list<string> $tags */
    public function __construct(
        public string $value,
        public ?DateTimeImmutable $expiresAt = null,
        public array $tags = [],
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
