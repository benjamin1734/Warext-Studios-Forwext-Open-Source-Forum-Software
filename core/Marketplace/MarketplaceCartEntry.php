<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class MarketplaceCartEntry
{
    public function __construct(
        public EntityId $listingId,
        public ?MarketplaceListing $listing,
        public bool $purchasable,
        public ?string $unavailableReason=null,
    ){}
}
