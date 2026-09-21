<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;

interface MarketplacePurchaseRepository
{
    public function internalSaleEnabled(EntityId $listingId):bool;
    public function saveInternalSaleSetting(EntityId $listingId,bool $enabled,EntityId $actor,DateTimeImmutable $at):void;

    /** @return list<EntityId> */
    public function cartListingIds(EntityId $buyer,bool $forUpdate=false):array;
    public function cartCount(EntityId $buyer):int;
    public function addCartItem(EntityId $buyer,EntityId $listingId,DateTimeImmutable $at):void;
    public function removeCartItem(EntityId $buyer,EntityId $listingId):void;
    public function clearCart(EntityId $buyer):void;

    /** @return list<MarketplaceOrder> */
    public function ordersForCheckout(EntityId $buyer,string $checkoutKey):array;

    /** @param list<MarketplaceOrderItem> $items */
    public function createOrder(MarketplaceOrder $order,array $items):void;
    public function order(EntityId $orderId):?MarketplaceOrder;

    /** @return list<MarketplaceOrderItem> */
    public function orderItems(EntityId $orderId):array;

    /** @return list<MarketplaceOrder> */
    public function ordersForUser(EntityId $userId,int $limit=100):array;

    public function saveOrderStates(MarketplaceOrder $order):void;
    public function recordOrderHistory(
        EntityId $orderId,
        EntityId $actor,
        string $action,
        MarketplaceOrderState $fromOrderState,
        MarketplaceOrderState $toOrderState,
        MarketplacePaymentState $fromPaymentState,
        MarketplacePaymentState $toPaymentState,
        MarketplaceDeliveryState $fromDeliveryState,
        MarketplaceDeliveryState $toDeliveryState,
        DateTimeImmutable $at,
    ):void;
}
