<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Marketplace\Delivery\MarketplaceDeliveryType;
use InvalidArgumentException;

final readonly class MarketplaceOrderItem
{
    public function __construct(
        public EntityId $itemId,
        public EntityId $orderId,
        public EntityId $listingId,
        public string $title,
        public int $quantity,
        public int $unitMinor,
        public string $currency,
        public MarketplaceDeliveryType $deliveryType=MarketplaceDeliveryType::Manual,
        public ?EntityId $deliveryAssetId=null,
    ){
        foreach([$this->itemId,$this->orderId,$this->listingId] as $id){
            if(preg_match('/^[a-f0-9]{32}$/D',$id->value())!==1)throw new InvalidArgumentException('Marketplace order item id is invalid.');
        }
        if(trim($this->title)===''||strlen($this->title)>180||preg_match('/[\x00-\x1F\x7F]/u',$this->title)===1){
            throw new InvalidArgumentException('Marketplace order item title is invalid.');
        }
        if($this->quantity<1||$this->quantity>999||$this->unitMinor<0){
            throw new InvalidArgumentException('Marketplace order item quantity or price is invalid.');
        }
        if(preg_match('/^[A-Z]{3}$/D',$this->currency)!==1)throw new InvalidArgumentException('Marketplace order item currency is invalid.');
        if($this->deliveryType===MarketplaceDeliveryType::Download&&$this->deliveryAssetId===null){
            throw new InvalidArgumentException('Marketplace download order item requires an asset snapshot.');
        }
        if($this->deliveryType!==MarketplaceDeliveryType::Download&&$this->deliveryAssetId!==null){
            throw new InvalidArgumentException('Only marketplace download order items may reference a delivery asset.');
        }
    }

    public function lineTotalMinor():int
    {
        if($this->unitMinor>intdiv(PHP_INT_MAX,$this->quantity)){
            throw new InvalidArgumentException('Marketplace order item total is too large.');
        }
        return $this->unitMinor*$this->quantity;
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}
}
