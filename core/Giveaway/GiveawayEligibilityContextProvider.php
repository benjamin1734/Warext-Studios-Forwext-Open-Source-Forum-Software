<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Domain\Entity\EntityId;

interface GiveawayEligibilityContextProvider
{
    public function context(EntityId $userId): GiveawayEligibilityContext;
}
