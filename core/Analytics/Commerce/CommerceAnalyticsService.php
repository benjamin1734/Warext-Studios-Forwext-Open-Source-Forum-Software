<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Commerce;

use DateTimeImmutable;
use Forwext\Core\Analytics\Access\AnalyticsAccessService;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class CommerceAnalyticsService
{
    public function __construct(
        private DatabaseCommerceAnalyticsRepository $repository,
        private AnalyticsAccessService $access,
    ) {
    }

    public function dashboard(
        EntityId $actor,
        int $days = 30,
        ?DateTimeImmutable $now = null,
    ): CommerceAnalyticsSnapshot {
        $this->access->require($actor, 'analytics.view_commerce');

        return $this->repository->snapshot($days, $now);
    }
}
