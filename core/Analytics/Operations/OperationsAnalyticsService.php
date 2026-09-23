<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Operations;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class OperationsAnalyticsService
{
    public function __construct(
        private DatabaseOperationsAnalyticsRepository $repository,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function dashboard(
        EntityId $actor,
        int $days = 30,
        ?DateTimeImmutable $now = null,
    ): OperationsAnalyticsSnapshot {
        $decision = $this->authorizer->resolve(
            $actor,
            PermissionKey::fromString('analytics.view_site'),
        );
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }

        return $this->repository->snapshot($days, $now);
    }
}
