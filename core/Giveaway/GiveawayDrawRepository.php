<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Domain\Entity\EntityId;

interface GiveawayDrawRepository
{
    public function latest(EntityId $giveawayId): ?GiveawayDraw;

    /** @return list<GiveawayDraw> */
    public function history(EntityId $giveawayId): array;

    /** @param list<GiveawayDrawCandidate> $population */
    public function save(GiveawayDraw $draw, array $population): void;

    /** @return list<GiveawayDrawCandidate> */
    public function population(EntityId $drawId): array;
}
