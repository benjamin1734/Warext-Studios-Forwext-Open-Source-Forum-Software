<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface RewardGrantGateway
{
    public function grant(RewardGrantRequest $request, DateTimeImmutable $now): RewardGrant;

    /** @return list<RewardGrant> */
    public function fulfillBindings(
        string $sourceType,
        EntityId $sourceDefinitionId,
        string $sourceEventId,
        EntityId $recipientUserId,
        DateTimeImmutable $now,
    ): array;

    /** @return list<RewardGrant> */
    public function revokeSource(
        string $sourceType,
        string $sourceEventId,
        EntityId $recipientUserId,
        DateTimeImmutable $now,
    ): array;
}
