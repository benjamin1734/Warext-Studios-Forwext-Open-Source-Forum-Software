<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Storage\StoragePath;
use InvalidArgumentException;

final readonly class MarketplaceDeliveryAsset
{
    public DateTimeImmutable $createdAt;

    public function __construct(
        public EntityId $assetId,
        public EntityId $listingId,
        public string $storagePath,
        public string $filename,
        public string $mediaType,
        public int $sizeBytes,
        public string $sha256,
        public EntityId $createdByUserId,
        DateTimeImmutable $createdAt,
    ){
        UserId::assert($this->createdByUserId);
        StoragePath::fromString($this->storagePath);
        if(!str_starts_with($this->storagePath,'marketplace-delivery/'.$this->listingId->value().'/')){
            throw new InvalidArgumentException('Marketplace delivery asset does not belong to the listing.');
        }
        if(trim($this->filename)===''||strlen($this->filename)>191
            ||preg_match('/[\\\/\x00-\x1F\x7F]/',$this->filename)===1
        ){
            throw new InvalidArgumentException('Marketplace delivery filename is invalid.');
        }
        if(!in_array($this->mediaType,[
            'application/zip','application/pdf','text/plain',
            'image/jpeg','image/png','image/gif','image/webp',
        ],true)){
            throw new InvalidArgumentException('Marketplace delivery media type is not allowed.');
        }
        if($this->sizeBytes<1||preg_match('/^[a-f0-9]{64}$/D',$this->sha256)!==1){
            throw new InvalidArgumentException('Marketplace delivery asset metadata is invalid.');
        }
        $this->createdAt=$createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}
}
