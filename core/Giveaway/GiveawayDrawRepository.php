<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Domain\Entity\EntityId;

interface GiveawayDrawRepository
{
    public function latest(EntityId $giveawayId): ?GiveawayDraw;

    /** @return list<GiveawayDraw> */
    public function history(EntityId $giveawayId): array;

    public function save(GiveawayDraw $draw): void;
}
