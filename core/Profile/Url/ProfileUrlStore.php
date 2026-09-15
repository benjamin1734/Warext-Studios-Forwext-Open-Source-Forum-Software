<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Url;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface ProfileUrlStore
{
    public function findCurrent(EntityId $userId): ?ProfileUrlAssignment;

    public function resolve(ProfileSlug $slug): ?ProfileUrlResolution;

    public function claim(
        EntityId $userId,
        ProfileSlug $slug,
        DateTimeImmutable $now,
        int $minimumChangeIntervalSeconds,
        int $changeWindowSeconds,
        int $maximumChangesPerWindow,
    ): ProfileUrlAssignment;
}
