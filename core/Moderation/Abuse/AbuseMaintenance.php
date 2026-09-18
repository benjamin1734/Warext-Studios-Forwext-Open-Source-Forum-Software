<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use DateTimeImmutable;

interface AbuseMaintenance
{
    public function cleanupCounters(DateTimeImmutable $before, int $limit = 1000): int;

    public function cleanupResolvedEvents(DateTimeImmutable $before, int $limit = 500): int;
}
