<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MarketplaceMedia
{
    public function __construct(
        public EntityId $mediaId,
        public string $storagePath,
        public string $mediaType,
        public string $altText,
        public int $sortOrder,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->mediaId->value())!==1
            ||preg_match('/^marketplace\/[a-f0-9]{32}\/[a-f0-9]{64}\.(?:jpg|png|webp)$/D',$this->storagePath)!==1
            ||!in_array($this->mediaType,['image/jpeg','image/png','image/webp'],true)
            ||strlen($this->altText)>500||preg_match('//u',$this->altText)!==1
            ||$this->sortOrder<0||$this->sortOrder>1000
        ){
            throw new InvalidArgumentException('Marketplace media metadata is invalid.');
        }
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}
}
