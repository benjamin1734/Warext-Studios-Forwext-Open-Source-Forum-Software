<?php

declare(strict_types=1);

namespace Forwext\Core\Presence;

use DateTimeImmutable;

final readonly class OnlineUser
{
    public function __construct(
        public string $username,
        public DateTimeImmutable $lastSeenAt,
    ) {
    }
}
