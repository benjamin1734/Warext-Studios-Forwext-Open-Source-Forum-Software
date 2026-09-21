<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MarketplaceDeliverySnapshot
{
    public function __construct(
        public MarketplaceDeliveryType $type,
        public ?EntityId $assetId=null,
    ){
        if($this->type===MarketplaceDeliveryType::Download&&$this->assetId===null){
            throw new InvalidArgumentException('Download delivery snapshot requires an asset.');
        }
        if($this->type!==MarketplaceDeliveryType::Download&&$this->assetId!==null){
            throw new InvalidArgumentException('Only download delivery snapshots may reference an asset.');
        }
    }

    public static function manual():self{return new self(MarketplaceDeliveryType::Manual);}
}
