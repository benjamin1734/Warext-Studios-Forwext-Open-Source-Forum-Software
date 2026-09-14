<?php

declare(strict_types=1);

namespace Forwext\Core\Queue;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class QueueReservation
{
    public function __construct(
        public QueueJob $job,
        public string $token,
        public DateTimeImmutable $reservedUntil,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Queue reservation token is invalid.');
        }
    }
}
