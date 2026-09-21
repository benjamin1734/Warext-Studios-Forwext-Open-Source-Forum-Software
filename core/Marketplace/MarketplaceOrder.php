<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceOrder
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    /** @param array<string,scalar|null> $receiptMetadata */
    public function __construct(
        public EntityId $orderId,
        public string $orderNumber,
        public string $checkoutKey,
        public EntityId $buyerUserId,
        public EntityId $sellerUserId,
        public string $currency,
        public int $subtotalMinor,
        public int $totalMinor,
        public MarketplaceOrderState $state,
        public MarketplacePaymentState $paymentState,
        public MarketplaceDeliveryState $deliveryState,
        public MarketplaceBillingSnapshot $billing,
        public array $receiptMetadata,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->orderId->value())!==1)throw new InvalidArgumentException('Marketplace order id is invalid.');
        UserId::assert($this->buyerUserId);UserId::assert($this->sellerUserId);
        if($this->buyerUserId->equals($this->sellerUserId))throw new InvalidArgumentException('Marketplace buyer and seller must differ.');
        if(preg_match('/^FWX-[0-9]{8}-[A-F0-9]{12}$/D',$this->orderNumber)!==1)throw new InvalidArgumentException('Marketplace order number is invalid.');
        if(preg_match('/^[a-f0-9]{32}$/D',$this->checkoutKey)!==1)throw new InvalidArgumentException('Marketplace checkout key is invalid.');
        if(preg_match('/^[A-Z]{3}$/D',$this->currency)!==1)throw new InvalidArgumentException('Marketplace order currency is invalid.');
        if($this->subtotalMinor<0||$this->totalMinor<$this->subtotalMinor)throw new InvalidArgumentException('Marketplace order totals are invalid.');
        if(count($this->receiptMetadata)>32)throw new InvalidArgumentException('Marketplace receipt metadata is too large.');
        foreach($this->receiptMetadata as $key=>$value){
            if(!is_string($key)||preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$key)!==1||(!is_scalar($value)&&$value!==null)){
                throw new InvalidArgumentException('Marketplace receipt metadata is invalid.');
            }
            if(is_string($value)&&strlen($value)>1000)throw new InvalidArgumentException('Marketplace receipt metadata value is too large.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);$this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->updatedAt<$this->createdAt)throw new InvalidArgumentException('Marketplace order timestamps are invalid.');
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}

    public static function generateNumber(DateTimeImmutable $at):string
    {
        return 'FWX-'.$at->setTimezone(new DateTimeZone('UTC'))->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(6)));
    }
}
