<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Dashboard;

use DateTimeImmutable;
use Forwext\Core\Analytics\Access\AnalyticsAccessService;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class ForumAnalyticsService
{
    public function __construct(
        private DatabaseForumAnalyticsRepository $repository,
        private AnalyticsAccessService $access,
    ){}

    public function dashboard(
        EntityId $actor,
        int $days=30,
        ?DateTimeImmutable $now=null,
    ):ForumAnalyticsSnapshot{
        $this->access->require($actor, 'analytics.view_forum');

        return $this->repository->snapshot($days,$now);
    }
}
