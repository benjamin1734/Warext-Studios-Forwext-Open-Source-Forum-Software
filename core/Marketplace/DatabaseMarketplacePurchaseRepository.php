<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use JsonException;

final readonly class DatabaseMarketplacePurchaseRepository implements MarketplacePurchaseRepository
{
    public function __construct(private TransactionalQueryExecutor $database){}

    public function internalSaleEnabled(EntityId $listingId):bool
    {
        return (int)$this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_marketplace_internal_sale_settings WHERE listing_id=:listing AND enabled=1',
            ['listing'=>$listingId->value()]
        ))===1;
    }

    public function saveInternalSaleSetting(EntityId $listingId,bool $enabled,EntityId $actor,DateTimeImmutable $at):void
    {
        UserId::assert($actor);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_internal_sale_settings(listing_id,enabled,updated_by_user_id,updated_at_utc) '
            . 'VALUES (:listing,:enabled,:actor,:updated) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),'
            . 'updated_by_user_id=VALUES(updated_by_user_id),updated_at_utc=VALUES(updated_at_utc)',
            ['listing'=>$listingId->value(),'enabled'=>$enabled,'actor'=>$actor->value(),'updated'=>self::format($at)]
        ));
    }

    public function cartListingIds(EntityId $buyer,bool $forUpdate=false):array
    {
        UserId::assert($buyer);
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT listing_id FROM forwext_marketplace_cart_items WHERE buyer_user_id=:buyer '
            . 'ORDER BY created_at_utc,listing_id'.($forUpdate?' FOR UPDATE':''),
            ['buyer'=>$buyer->value()]
        ));
        return array_map(static fn(array $row):EntityId=>EntityId::fromString((string)$row['listing_id']),$rows);
    }

    public function cartCount(EntityId $buyer):int
    {
        UserId::assert($buyer);
        return (int)$this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_marketplace_cart_items WHERE buyer_user_id=:buyer',
            ['buyer'=>$buyer->value()]
        ));
    }

    public function addCartItem(EntityId $buyer,EntityId $listingId,DateTimeImmutable $at):void
    {
        UserId::assert($buyer);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_cart_items(buyer_user_id,listing_id,created_at_utc) '
            . 'VALUES (:buyer,:listing,:created) ON DUPLICATE KEY UPDATE created_at_utc=created_at_utc',
            ['buyer'=>$buyer->value(),'listing'=>$listingId->value(),'created'=>self::format($at)]
        ));
    }

    public function removeCartItem(EntityId $buyer,EntityId $listingId):void
    {
        UserId::assert($buyer);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_marketplace_cart_items WHERE buyer_user_id=:buyer AND listing_id=:listing',
            ['buyer'=>$buyer->value(),'listing'=>$listingId->value()]
        ));
    }

    public function clearCart(EntityId $buyer):void
    {
        UserId::assert($buyer);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_marketplace_cart_items WHERE buyer_user_id=:buyer',
            ['buyer'=>$buyer->value()]
        ));
    }

    public function ordersForCheckout(EntityId $buyer,string $checkoutKey):array
    {
        UserId::assert($buyer);
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_orders WHERE buyer_user_id=:buyer AND checkout_key=:checkout '
            . 'ORDER BY created_at_utc,order_id',
            ['buyer'=>$buyer->value(),'checkout'=>$checkoutKey]
        ));
        return array_map($this->hydrateOrder(...),$rows);
    }

    public function createOrder(MarketplaceOrder $order,array $items):void
    {
        if($items===[])throw new InvalidArgumentException('Marketplace order must contain at least one item.');
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_orders '
            . '(order_id,order_number,checkout_key,buyer_user_id,seller_user_id,currency,subtotal_minor,total_minor,'
            . 'order_state,payment_state,delivery_state,billing_json,receipt_json,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:number,:checkout,:buyer,:seller,:currency,:subtotal,:total,:order_state,:payment_state,'
            . ':delivery_state,:billing,:receipt,:created,:updated)',
            [
                'id'=>$order->orderId->value(),'number'=>$order->orderNumber,'checkout'=>$order->checkoutKey,
                'buyer'=>$order->buyerUserId->value(),'seller'=>$order->sellerUserId->value(),'currency'=>$order->currency,
                'subtotal'=>$order->subtotalMinor,'total'=>$order->totalMinor,'order_state'=>$order->state->value,
                'payment_state'=>$order->paymentState->value,'delivery_state'=>$order->deliveryState->value,
                'billing'=>self::json($order->billing->toArray()),'receipt'=>self::json($order->receiptMetadata),
                'created'=>self::format($order->createdAt),'updated'=>self::format($order->updatedAt),
            ]
        ));
        foreach($items as $item){
            if(!$item instanceof MarketplaceOrderItem||!$item->orderId->equals($order->orderId)){
                throw new InvalidArgumentException('Marketplace order item does not belong to the order.');
            }
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_marketplace_order_items '
                . '(item_id,order_id,listing_id,title,quantity,unit_minor,currency,line_total_minor) '
                . 'VALUES (:id,:order_id,:listing,:title,:quantity,:unit,:currency,:line_total)',
                [
                    'id'=>$item->itemId->value(),'order_id'=>$order->orderId->value(),'listing'=>$item->listingId->value(),
                    'title'=>$item->title,'quantity'=>$item->quantity,'unit'=>$item->unitMinor,
                    'currency'=>$item->currency,'line_total'=>$item->lineTotalMinor(),
                ]
            ));
        }
    }

    public function order(EntityId $orderId):?MarketplaceOrder
    {
        $row=$this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_orders WHERE order_id=:id LIMIT 1',
            ['id'=>$orderId->value()]
        ));
        return $row===null?null:$this->hydrateOrder($row);
    }

    public function orderItems(EntityId $orderId):array
    {
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_order_items WHERE order_id=:order_id ORDER BY item_id',
            ['order_id'=>$orderId->value()]
        ));
        return array_map(static fn(array $row):MarketplaceOrderItem=>new MarketplaceOrderItem(
            EntityId::fromString((string)$row['item_id']),
            EntityId::fromString((string)$row['order_id']),
            EntityId::fromString((string)$row['listing_id']),
            (string)$row['title'],(int)$row['quantity'],(int)$row['unit_minor'],(string)$row['currency']
        ),$rows);
    }

    public function ordersForUser(EntityId $userId,int $limit=100):array
    {
        UserId::assert($userId);
        if($limit<1||$limit>200)throw new InvalidArgumentException('Marketplace order list limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_orders WHERE buyer_user_id=:buyer_user OR seller_user_id=:seller_user '
            . 'ORDER BY created_at_utc DESC,order_id DESC LIMIT '.$limit,
            ['buyer_user'=>$userId->value(),'seller_user'=>$userId->value()]
        ));
        return array_map($this->hydrateOrder(...),$rows);
    }

    public function orders(int $limit=100):array
    {
        if($limit<1||$limit>200)throw new InvalidArgumentException('Marketplace order list limit is invalid.');
        $rows=$this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_marketplace_orders ORDER BY created_at_utc DESC,order_id DESC LIMIT '.$limit
        ));
        return array_map($this->hydrateOrder(...),$rows);
    }

    public function saveOrderStates(MarketplaceOrder $order):void
    {
        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_marketplace_orders SET order_state=:order_state,payment_state=:payment_state,'
            . 'delivery_state=:delivery_state,receipt_json=:receipt,updated_at_utc=:updated WHERE order_id=:id',
            [
                'order_state'=>$order->state->value,'payment_state'=>$order->paymentState->value,
                'delivery_state'=>$order->deliveryState->value,'receipt'=>self::json($order->receiptMetadata),
                'updated'=>self::format($order->updatedAt),'id'=>$order->orderId->value(),
            ]
        ));
    }

    public function recordOrderHistory(
        EntityId $orderId,EntityId $actor,string $action,
        MarketplaceOrderState $fromOrderState,MarketplaceOrderState $toOrderState,
        MarketplacePaymentState $fromPaymentState,MarketplacePaymentState $toPaymentState,
        MarketplaceDeliveryState $fromDeliveryState,MarketplaceDeliveryState $toDeliveryState,
        DateTimeImmutable $at,
    ):void{
        UserId::assert($actor);
        if(preg_match('/^[a-z][a-z0-9._-]{1,63}$/D',$action)!==1)throw new InvalidArgumentException('Marketplace order history action is invalid.');
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_marketplace_order_history '
            . '(history_id,order_id,actor_user_id,action,from_order_state,to_order_state,from_payment_state,to_payment_state,'
            . 'from_delivery_state,to_delivery_state,created_at_utc) '
            . 'VALUES (:id,:order_id,:actor,:action,:from_order,:to_order,:from_payment,:to_payment,:from_delivery,:to_delivery,:created)',
            [
                'id'=>bin2hex(random_bytes(16)),'order_id'=>$orderId->value(),'actor'=>$actor->value(),'action'=>$action,
                'from_order'=>$fromOrderState->value,'to_order'=>$toOrderState->value,
                'from_payment'=>$fromPaymentState->value,'to_payment'=>$toPaymentState->value,
                'from_delivery'=>$fromDeliveryState->value,'to_delivery'=>$toDeliveryState->value,
                'created'=>self::format($at),
            ]
        ));
    }

    /** @param array<string,mixed> $row */
    private function hydrateOrder(array $row):MarketplaceOrder
    {
        $billing=self::decode((string)$row['billing_json']);
        $receipt=self::decode((string)$row['receipt_json']);
        $receiptScalars=[];
        foreach($receipt as $key=>$value){
            if(is_string($key)&&(is_scalar($value)||$value===null))$receiptScalars[$key]=$value;
        }
        return new MarketplaceOrder(
            EntityId::fromString((string)$row['order_id']),
            (string)$row['order_number'],(string)$row['checkout_key'],
            EntityId::fromString((string)$row['buyer_user_id']),EntityId::fromString((string)$row['seller_user_id']),
            (string)$row['currency'],(int)$row['subtotal_minor'],(int)$row['total_minor'],
            MarketplaceOrderState::from((string)$row['order_state']),
            MarketplacePaymentState::from((string)$row['payment_state']),
            MarketplaceDeliveryState::from((string)$row['delivery_state']),
            MarketplaceBillingSnapshot::fromArray($billing),$receiptScalars,
            self::at((string)$row['created_at_utc']),self::at((string)$row['updated_at_utc'])
        );
    }

    /** @return array<string,mixed> */
    private static function decode(string $json):array
    {
        try{$decoded=json_decode($json,true,512,JSON_THROW_ON_ERROR);}
        catch(JsonException $exception){throw new InvalidArgumentException('Marketplace order JSON is invalid.',previous:$exception);}
        if(!is_array($decoded))throw new InvalidArgumentException('Marketplace order JSON is invalid.');
        return $decoded;
    }

    /** @param array<string,mixed> $value */
    private static function json(array $value):string
    {
        try{return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);}
        catch(JsonException $exception){throw new InvalidArgumentException('Marketplace order JSON cannot be encoded.',previous:$exception);}
    }

    private static function at(string $value):DateTimeImmutable{return new DateTimeImmutable($value,new DateTimeZone('UTC'));}
    private static function format(DateTimeImmutable $value):string{return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');}
}
