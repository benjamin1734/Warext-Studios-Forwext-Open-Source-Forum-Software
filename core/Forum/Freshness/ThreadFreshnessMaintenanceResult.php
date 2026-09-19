<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

final readonly class ThreadFreshnessMaintenanceResult
{
    public function __construct(
        public int $scanned,
        public int $notified,
        public int $locked,
        public int $archived,
        public int $unfeatured,
        public int $reviewsCreated,
    ) {
    }
}
