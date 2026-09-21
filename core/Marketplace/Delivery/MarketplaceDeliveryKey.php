<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceDeliveryKey
{
    public DateTimeImmutable $createdAt;
    public ?DateTimeImmutable $assignedAt;

    public function __construct(
        public EntityId $keyId,
        public EntityId $listingId,
        public string $encryptedValue,
        public string $fingerprint,
        public MarketplaceDeliveryKeyState $state,
        public ?EntityId $assignedOrderItemId,
        public EntityId $createdByUserId,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $assignedAt=null,
    ){
        UserId::assert($this->createdByUserId);
        if($this->encryptedValue===''||strlen($this->encryptedValue)>32768
            ||preg_match('/^[a-f0-9]{64}$/D',$this->fingerprint)!==1
        ){
            throw new InvalidArgumentException('Marketplace delivery key payload is invalid.');
        }
        $held=in_array($this->state,[MarketplaceDeliveryKeyState::Reserved,MarketplaceDeliveryKeyState::Assigned],true);
        if($held&&$this->assignedOrderItemId===null){
            throw new InvalidArgumentException('Reserved or assigned delivery key requires an order item.');
        }
        if(!$held&&$this->assignedOrderItemId!==null){
            throw new InvalidArgumentException('Available/revoked delivery key cannot reference an order item.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->assignedAt=$assignedAt?->setTimezone($utc);
        if($held&&$this->assignedAt===null){
            throw new InvalidArgumentException('Reserved or assigned delivery key requires a hold timestamp.');
        }
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}
}
