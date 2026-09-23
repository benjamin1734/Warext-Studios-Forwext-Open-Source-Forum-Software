<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Commerce;

use DateTimeImmutable;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class CommerceAnalyticsService
{
    public function __construct(
        private DatabaseCommerceAnalyticsRepository $repository,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function dashboard(
        EntityId $actor,
        int $days = 30,
        ?DateTimeImmutable $now = null,
    ): CommerceAnalyticsSnapshot {
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
