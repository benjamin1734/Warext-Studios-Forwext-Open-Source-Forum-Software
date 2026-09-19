<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface GiveawayParticipationRepository
{
    public function policy(EntityId $giveawayId): ?GiveawayEligibilityPolicy;

    public function savePolicy(GiveawayEligibilityPolicy $policy, DateTimeImmutable $at): void;

    public function lockGiveaway(EntityId $giveawayId): bool;

    public function entryForUser(EntityId $giveawayId, EntityId $userId): ?GiveawayEntry;

    public function participantCount(EntityId $giveawayId): int;

    /** @return list<GiveawayEntry> */
    public function entriesForDraw(EntityId $giveawayId): array;

    public function fingerprintParticipantCount(EntityId $giveawayId, string $kind, string $fingerprint): int;

    public function saveEntry(GiveawayEntry $entry): void;

    /** @return list<GiveawayEligibilityRoleOption> */
    public function availableRoles(): array;
}
