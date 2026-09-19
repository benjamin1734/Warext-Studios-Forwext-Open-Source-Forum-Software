<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface RewardProvider
{
    public function key(): string;

    /** @return list<RewardTargetOption> */
    public function targets(): array;

    public function supportsTarget(EntityId $targetId): bool;

    public function apply(
        EntityId $recipientUserId,
        EntityId $targetId,
        int $units,
        DateTimeImmutable $now,
    ): RewardProviderResult;

    public function revoke(
        EntityId $recipientUserId,
        EntityId $targetId,
        int $units,
        DateTimeImmutable $now,
    ): void;
}
