<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Marketplace\MarketplaceDeliveryState;
use InvalidArgumentException;

final readonly class MarketplaceDeliveryRecord
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
    public ?DateTimeImmutable $readyAt;
    public ?DateTimeImmutable $deliveredAt;
    public ?DateTimeImmutable $lastDownloadAt;

    public function __construct(
        public EntityId $orderItemId,
        public EntityId $orderId,
        public MarketplaceDeliveryType $type,
        public MarketplaceDeliveryState $state,
        public ?EntityId $assetId,
        public ?EntityId $keyId,
        public ?string $encryptedManualValue,
        public ?EntityId $fulfilledByUserId,
        public int $downloadCount,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $readyAt=null,
        ?DateTimeImmutable $deliveredAt=null,
        ?DateTimeImmutable $lastDownloadAt=null,
    ){
        if($this->type===MarketplaceDeliveryType::Download&&$this->assetId===null){
            throw new InvalidArgumentException('Download delivery record requires an asset.');
        }
        if($this->type!==MarketplaceDeliveryType::Download&&$this->assetId!==null){
            throw new InvalidArgumentException('Only download delivery records may reference an asset.');
        }
        $requiresPayload=in_array($this->state,[MarketplaceDeliveryState::Ready,MarketplaceDeliveryState::Delivered],true);
        if(in_array($this->type,[MarketplaceDeliveryType::License,MarketplaceDeliveryType::Key],true)
            &&$requiresPayload&&$this->keyId===null
        ){
            throw new InvalidArgumentException('Ready key/license delivery requires an assigned key.');
        }
        if($this->type===MarketplaceDeliveryType::Manual&&$requiresPayload&&$this->encryptedManualValue===null){
            throw new InvalidArgumentException('Ready manual delivery requires a payload.');
        }
        if($this->fulfilledByUserId!==null)UserId::assert($this->fulfilledByUserId);
        if($this->downloadCount<0)throw new InvalidArgumentException('Marketplace download count is invalid.');

        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->updatedAt=$updatedAt->setTimezone($utc);
        $this->readyAt=$readyAt?->setTimezone($utc);
        $this->deliveredAt=$deliveredAt?->setTimezone($utc);
        $this->lastDownloadAt=$lastDownloadAt?->setTimezone($utc);
        if($this->updatedAt<$this->createdAt)throw new InvalidArgumentException('Marketplace delivery timestamps are invalid.');
        if(in_array($this->state,[MarketplaceDeliveryState::Ready,MarketplaceDeliveryState::Delivered],true)
            &&$this->readyAt===null
        ){
            throw new InvalidArgumentException('Ready delivery state requires a ready timestamp.');
        }
        if($this->state===MarketplaceDeliveryState::Delivered&&$this->deliveredAt===null){
            throw new InvalidArgumentException('Delivered state requires a delivered timestamp.');
        }
    }
}
