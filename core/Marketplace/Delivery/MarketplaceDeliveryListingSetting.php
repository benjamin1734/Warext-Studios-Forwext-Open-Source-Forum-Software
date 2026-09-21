<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceDeliveryListingSetting
{
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $listingId,
        public MarketplaceDeliveryType $type,
        public ?EntityId $assetId,
        public EntityId $updatedByUserId,
        DateTimeImmutable $updatedAt,
    ){
        UserId::assert($this->updatedByUserId);
        if($this->type===MarketplaceDeliveryType::Download&&$this->assetId===null){
            throw new InvalidArgumentException('Download delivery requires an asset.');
        }
        if($this->type!==MarketplaceDeliveryType::Download&&$this->assetId!==null){
            throw new InvalidArgumentException('Only download delivery may reference an asset.');
        }
        $this->updatedAt=$updatedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function manual(EntityId $listingId,EntityId $actor,DateTimeImmutable $at):self
    {
        return new self($listingId,MarketplaceDeliveryType::Manual,null,$actor,$at);
    }
}
