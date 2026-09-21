<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use Throwable;

final readonly class MarketplacePurchaseService
{
    private const MAX_CART_ITEMS=50;

    public function __construct(
        private TransactionalQueryExecutor $database,
        private MarketplacePurchaseRepository $purchases,
        private MarketplaceService $marketplace,
        private AuditRecorder $audit,
        private ?MarketplacePurchaseNotifier $notifier=null,
    ){}

    public function internalSaleEnabled(EntityId $listingId,?EntityId $viewer):bool
    {
        $listing=$this->marketplace->listing($listingId,$viewer);
        if($listing->state!==MarketplaceListingState::Active)return false;
        if(!$this->marketplace->canUseInternalPurchase($listing->sellerUserId))return false;
        return $this->purchases->internalSaleEnabled($listingId);
    }

    public function managementInternalSaleEnabled(EntityId $actor,EntityId $listingId):bool
    {
        $this->marketplace->managementListing($actor,$listingId);
        $this->marketplace->requireInternalPurchaseUse($actor);
        return $this->purchases->internalSaleEnabled($listingId);
    }

    public function setInternalSale(
        EntityId $actor,
        EntityId $listingId,
        bool $enabled,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):void{
        $listing=$this->marketplace->managementListing($actor,$listingId);
        $this->marketplace->requireInternalPurchaseUse($actor);
        if($enabled&&!$listing->sellerUserId->equals($actor)&&!$this->marketplace->canManageAll($actor)){
            throw new InvalidArgumentException('Marketplace internal sale can only be enabled by the seller or marketplace staff.');
        }
        $before=$this->purchases->internalSaleEnabled($listingId);
        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString('marketplace.internal_sale.update'),
            'marketplace.internal_sale',$listingId->value(),null,'marketplace.internal_sale.update',
            $requestId??AuditRequestId::generate(),['enabled'=>$before],['enabled'=>$enabled],self::utc($now)
        );
        $this->audit->mutate($event,fn():mixed=>$this->purchases->saveInternalSaleSetting(
            $listingId,$enabled,$actor,self::utc($now)
        ));
    }

    public function canPurchaseListing(EntityId $buyer,MarketplaceListing $listing):bool
    {
        return $this->marketplace->canPurchase($buyer)&&$this->purchaseUnavailableReason($buyer,$listing)===null;
    }

    public function addToCart(EntityId $buyer,EntityId $listingId,DateTimeImmutable $now):void
    {
        $this->marketplace->requirePurchase($buyer);
        $this->database->transaction(function()use($buyer,$listingId,$now):void{
            $ids=$this->purchases->cartListingIds($buyer,true);
            foreach($ids as $id)if($id->equals($listingId))return;
            if(count($ids)>=self::MAX_CART_ITEMS)throw new InvalidArgumentException('Marketplace cart item limit exceeded.');
            $listing=$this->requirePurchasable($buyer,$listingId);
            if($listing->sellerUserId->equals($buyer))throw new InvalidArgumentException('Sellers cannot purchase their own listing.');
            $this->purchases->addCartItem($buyer,$listingId,self::utc($now));
        });
    }

    public function removeFromCart(EntityId $buyer,EntityId $listingId):void
    {
        $this->marketplace->requirePurchase($buyer);
        $this->purchases->removeCartItem($buyer,$listingId);
    }

    /** @return list<MarketplaceCartEntry> */
    public function cart(EntityId $buyer):array
    {
        $this->marketplace->requirePurchase($buyer);
        $result=[];
        foreach($this->purchases->cartListingIds($buyer) as $listingId){
            try{
                $listing=$this->marketplace->listing($listingId,$buyer);
            }catch(PermissionDeniedException|InvalidArgumentException){
                $result[]=new MarketplaceCartEntry($listingId,null,false,'İlan artık satın alınabilir değil.');
                continue;
            }
            $reason=$this->purchaseUnavailableReason($buyer,$listing);
            $result[]=new MarketplaceCartEntry($listingId,$listing,$reason===null,$reason);
        }
        return $result;
    }

    /** @return list<MarketplaceOrder> */
    public function checkout(
        EntityId $buyer,
        MarketplaceBillingSnapshot $billing,
        string $checkoutKey,
        DateTimeImmutable $now,
    ):array{
        $this->marketplace->requirePurchase($buyer);
        self::assertCheckoutKey($checkoutKey);
        $at=self::utc($now);

        $orders=$this->database->transaction(function()use($buyer,$billing,$checkoutKey,$at):array{
            $existing=$this->purchases->ordersForCheckout($buyer,$checkoutKey);
            if($existing!==[])return $existing;

            $ids=$this->purchases->cartListingIds($buyer,true);
            $existing=$this->purchases->ordersForCheckout($buyer,$checkoutKey);
            if($existing!==[])return $existing;
            if($ids===[])throw new InvalidArgumentException('Marketplace cart is empty.');

            /** @var array<string,array{seller:EntityId,currency:string,listings:list<MarketplaceListing>}> $groups */
            $groups=[];
            foreach($ids as $listingId){
                $listing=$this->requirePurchasable($buyer,$listingId);
                $key=$listing->sellerUserId->value().':'.$listing->price->currency;
                if(!isset($groups[$key])){
                    $groups[$key]=[
                        'seller'=>$listing->sellerUserId,
                        'currency'=>$listing->price->currency,
                        'listings'=>[],
                    ];
                }
                $groups[$key]['listings'][]=$listing;
            }

            $created=[];
            foreach($groups as $group){
                $subtotal=0;
                foreach($group['listings'] as $listing){
                    if($subtotal>PHP_INT_MAX-$listing->price->minorUnits){
                        throw new InvalidArgumentException('Marketplace checkout total is too large.');
                    }
                    $subtotal+=$listing->price->minorUnits;
                }

                $orderId=MarketplaceOrder::generateId();
                $order=new MarketplaceOrder(
                    $orderId,MarketplaceOrder::generateNumber($at),$checkoutKey,$buyer,$group['seller'],
                    $group['currency'],$subtotal,$subtotal,
                    MarketplaceOrderState::Pending,MarketplacePaymentState::Pending,MarketplaceDeliveryState::Pending,
                    $billing,
                    ['checkout_mode'=>'internal','item_count'=>count($group['listings'])],
                    $at,$at
                );
                $items=[];
                foreach($group['listings'] as $listing){
                    $items[]=new MarketplaceOrderItem(
                        MarketplaceOrderItem::generateId(),$orderId,$listing->listingId,$listing->title,1,
                        $listing->price->minorUnits,$listing->price->currency
                    );
                }
                $this->purchases->createOrder($order,$items);
                $this->purchases->recordOrderHistory(
                    $orderId,$buyer,'order.created',
                    $order->state,$order->state,$order->paymentState,$order->paymentState,
                    $order->deliveryState,$order->deliveryState,$at
                );
                $created[]=$order;
            }

            $this->purchases->clearCart($buyer);
            return $created;
        });

        foreach($orders as $order)$this->notifyCreated($order);
        return $orders;
    }

    public function canManageOrders(EntityId $actor):bool
    {
        return $this->marketplace->canManageOrders($actor);
    }

    /** @return list<MarketplaceOrder> */
    public function orders(EntityId $actor,int $limit=100):array
    {
        if(!$this->marketplace->canPurchase($actor)
            &&!$this->marketplace->canUseInternalPurchase($actor)
            &&!$this->marketplace->canManageOrders($actor)
        ){
            $this->marketplace->requirePurchase($actor);
        }
        return $this->marketplace->canManageOrders($actor)
            ?$this->purchases->orders($limit)
            :$this->purchases->ordersForUser($actor,$limit);
    }

    /** @return array{order:MarketplaceOrder,items:list<MarketplaceOrderItem>} */
    public function order(EntityId $actor,EntityId $orderId):array
    {
        $order=$this->purchases->order($orderId)
            ?? throw new InvalidArgumentException('Marketplace order was not found.');
        if(!$order->buyerUserId->equals($actor)&&!$order->sellerUserId->equals($actor)&&!$this->marketplace->canManageOrders($actor)){
            throw new InvalidArgumentException('Marketplace order was not found.');
        }
        return ['order'=>$order,'items'=>$this->purchases->orderItems($orderId)];
    }

    public function cancelPending(
        EntityId $actor,
        EntityId $orderId,
        DateTimeImmutable $now,
        ?AuditRequestId $requestId=null,
    ):MarketplaceOrder{
        $before=$this->purchases->order($orderId)
            ?? throw new InvalidArgumentException('Marketplace order was not found.');
        $staff=$this->marketplace->canManageOrders($actor);
        if(!$before->buyerUserId->equals($actor)&&!$staff){
            throw new InvalidArgumentException('Marketplace order was not found.');
        }
        if($before->state!==MarketplaceOrderState::Pending||$before->paymentState!==MarketplacePaymentState::Pending){
            throw new InvalidArgumentException('Only unpaid pending orders can be cancelled.');
        }
        $at=self::utc($now);
        $after=new MarketplaceOrder(
            $before->orderId,$before->orderNumber,$before->checkoutKey,$before->buyerUserId,$before->sellerUserId,
            $before->currency,$before->subtotalMinor,$before->totalMinor,MarketplaceOrderState::Cancelled,
            MarketplacePaymentState::Cancelled,MarketplaceDeliveryState::Cancelled,$before->billing,
            $before->receiptMetadata,$before->createdAt,$at
        );

        $event=new AuditEvent(
            AuditEvent::generateId(),AuditScope::Administration,$actor,
            AuditAction::fromString('marketplace.order.cancel'),
            'marketplace.order',$orderId->value(),null,'marketplace.order.cancel',
            $requestId??AuditRequestId::generate(),
            ['order_state'=>$before->state->value,'payment_state'=>$before->paymentState->value,'delivery_state'=>$before->deliveryState->value],
            ['order_state'=>$after->state->value,'payment_state'=>$after->paymentState->value,'delivery_state'=>$after->deliveryState->value],
            $at
        );
        $this->audit->mutate($event,function()use($after,$before,$actor,$at):void{
            $this->purchases->saveOrderStates($after);
            $this->purchases->recordOrderHistory(
                $after->orderId,$actor,'order.cancel',
                $before->state,$after->state,$before->paymentState,$after->paymentState,
                $before->deliveryState,$after->deliveryState,$at
            );
        });
        return $after;
    }

    private function requirePurchasable(EntityId $buyer,EntityId $listingId):MarketplaceListing
    {
        try{
            $listing=$this->marketplace->listing($listingId,$buyer);
        }catch(PermissionDeniedException|InvalidArgumentException){
            throw new InvalidArgumentException('Marketplace listing is unavailable.');
        }
        $reason=$this->purchaseUnavailableReason($buyer,$listing);
        if($reason!==null)throw new InvalidArgumentException($reason);
        return $listing;
    }

    private function purchaseUnavailableReason(EntityId $buyer,MarketplaceListing $listing):?string
    {
        if($listing->state!==MarketplaceListingState::Active)return 'Marketplace listing is not available for native purchase.';
        if($listing->sellerUserId->equals($buyer))return 'Sellers cannot purchase their own listing.';
        if(!$this->marketplace->canUseInternalPurchase($listing->sellerUserId)){
            return 'Marketplace seller is not permitted to use native purchasing.';
        }
        if(!$this->purchases->internalSaleEnabled($listing->listingId)){
            return 'Marketplace listing does not have native purchasing enabled.';
        }
        return null;
    }

    private function notifyCreated(MarketplaceOrder $order):void
    {
        if($this->notifier===null)return;
        try{$this->notifier->created($order);}
        catch(Throwable){
            // Durable order creation must not roll back because a notification channel failed.
        }
    }

    private static function assertCheckoutKey(string $checkoutKey):void
    {
        if(preg_match('/^[a-f0-9]{32}$/D',$checkoutKey)!==1){
            throw new InvalidArgumentException('Marketplace checkout key is invalid.');
        }
    }

    private static function utc(DateTimeImmutable $at):DateTimeImmutable
    {
        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
