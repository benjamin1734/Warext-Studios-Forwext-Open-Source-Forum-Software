<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Marketplace\MarketplaceOrderItem;

interface MarketplaceDeliveryRepository
{
    public function listingSetting(EntityId $listingId):?MarketplaceDeliveryListingSetting;
    public function saveListingSetting(MarketplaceDeliveryListingSetting $setting):void;

    public function asset(EntityId $assetId):?MarketplaceDeliveryAsset;
    public function saveAsset(MarketplaceDeliveryAsset $asset):void;

    public function insertKey(MarketplaceDeliveryKey $key):void;
    public function availableKeyCount(EntityId $listingId):int;
    public function assignAvailableKey(
        EntityId $listingId,
        EntityId $orderItemId,
        DateTimeImmutable $at,
    ):?MarketplaceDeliveryKey;

    public function initializeOrderItem(MarketplaceOrderItem $item,DateTimeImmutable $at):void;
    public function delivery(EntityId $orderItemId,bool $forUpdate=false):?MarketplaceDeliveryRecord;

    /** @return list<MarketplaceDeliveryRecord> */
    public function deliveriesForOrder(EntityId $orderId,bool $forUpdate=false):array;

    public function saveDelivery(MarketplaceDeliveryRecord $record):void;
    public function recordDownload(EntityId $orderItemId,DateTimeImmutable $at):void;
}
