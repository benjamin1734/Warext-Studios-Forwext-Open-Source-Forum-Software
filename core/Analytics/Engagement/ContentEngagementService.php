<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Engagement;

use DateTimeImmutable;
use Forwext\Core\Analytics\Access\AnalyticsAccessService;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ContentEngagementService
{
    public function __construct(
        private DatabaseContentEngagementRepository $repository,
        private AnalyticsAccessService $access,
    ){}

    public function dashboard(
        EntityId $actor,
        int $days=30,
        ?DateTimeImmutable $now=null,
    ):ContentEngagementSnapshot{
        $this->access->require($actor, 'analytics.view_content');

        return $this->repository->snapshot($days,$now);
    }
}
