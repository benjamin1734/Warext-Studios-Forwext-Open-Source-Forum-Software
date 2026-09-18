<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class MarketplaceSupportContextResolver implements SupportContextResolver
{
    public function type(): SupportContextType
    {
        return SupportContextType::MarketplaceListing;
    }

    public function resolve(EntityId $actorUserId, EntityId $targetId): SupportContextLink
    {
        return new SupportContextLink(
            SupportContextType::MarketplaceListing,
            $targetId,
            'Marketplace listing ' . substr($targetId->value(), 0, 8),
        );
    }
}
