<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Marketplace\MarketplaceOrderItem;

interface MarketplaceDeliveryCoordinator
{
    public function snapshotForListing(EntityId $listingId):MarketplaceDeliverySnapshot;
    public function initializeOrderItem(MarketplaceOrderItem $item,DateTimeImmutable $at):void;
    public function cancelOrderReservations(EntityId $orderId,DateTimeImmutable $at):void;
}
