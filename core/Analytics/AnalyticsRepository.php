<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use DateTimeImmutable;

interface AnalyticsRepository
{
    public function append(AnalyticsStoredEvent $event):void;

    public function prune(
        string $eventKey,
        DateTimeImmutable $before,
        int $limit=5000,
    ):int;
}
