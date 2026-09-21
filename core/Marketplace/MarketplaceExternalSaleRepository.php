<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface MarketplaceExternalSaleRepository
{
    public function link(EntityId $listingId):?MarketplaceExternalSaleLink;
    public function save(MarketplaceExternalSaleLink $link):void;
    public function delete(EntityId $listingId):void;
    public function recordClick(EntityId $listingId,?EntityId $viewerUserId,string $targetHost,DateTimeImmutable $at):void;
    public function clickCount(EntityId $listingId):int;
}
