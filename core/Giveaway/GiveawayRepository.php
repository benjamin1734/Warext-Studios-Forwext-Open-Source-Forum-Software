<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface GiveawayRepository
{
    public function find(EntityId $giveawayId): ?Giveaway;

    /** @return list<Giveaway> */
    public function list(?EntityId $ownerUserId = null, bool $publicOnly = true, int $limit = 100): array;

    public function save(Giveaway $giveaway): void;

    /** @return list<Giveaway> */
    public function dueTransitions(DateTimeImmutable $at, int $limit = 100): array;
}
