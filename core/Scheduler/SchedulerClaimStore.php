<?php

declare(strict_types=1);

namespace Forwext\Core\Scheduler;

use DateTimeImmutable;

interface SchedulerClaimStore
{
    public function claim(string $taskName, DateTimeImmutable $minuteBucket): ?SchedulerClaim;

    public function release(SchedulerClaim $claim): void;

    public function pruneBefore(DateTimeImmutable $cutoff, int $limit = 1000): int;
}
