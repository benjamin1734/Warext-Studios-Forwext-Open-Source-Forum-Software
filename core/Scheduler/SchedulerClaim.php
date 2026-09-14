<?php

declare(strict_types=1);

namespace Forwext\Core\Scheduler;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class SchedulerClaim
{
    public function __construct(
        public string $taskName,
        public DateTimeImmutable $minuteBucket,
        public string $token,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $taskName) !== 1) {
            throw new InvalidArgumentException('Scheduler claim task name is invalid.');
        }
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            throw new InvalidArgumentException('Scheduler claim token is invalid.');
        }
    }
}
