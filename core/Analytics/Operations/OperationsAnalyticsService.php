<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Operations;

use DateTimeImmutable;
use Forwext\Core\Analytics\Access\AnalyticsAccessService;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class OperationsAnalyticsService
{
    public function __construct(
        private DatabaseOperationsAnalyticsRepository $repository,
        private AnalyticsAccessService $access,
    ) {
    }

    public function dashboard(
        EntityId $actor,
        int $days = 30,
        ?DateTimeImmutable $now = null,
    ): OperationsAnalyticsSnapshot {
        $this->access->require($actor, 'analytics.view_operations');

        return $this->repository->snapshot($days, $now);
    }
}
