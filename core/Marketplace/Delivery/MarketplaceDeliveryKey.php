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
        if($this->state===MarketplaceDeliveryKeyState::Assigned&&$this->assignedOrderItemId===null){
            throw new InvalidArgumentException('Assigned delivery key requires an order item.');
        }
        if($this->state!==MarketplaceDeliveryKeyState::Assigned&&$this->assignedOrderItemId!==null){
            throw new InvalidArgumentException('Unassigned delivery key cannot reference an order item.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->assignedAt=$assignedAt?->setTimezone($utc);
        if($this->state===MarketplaceDeliveryKeyState::Assigned&&$this->assignedAt===null){
            throw new InvalidArgumentException('Assigned delivery key requires an assignment timestamp.');
        }
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}
}
