<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceExternalSaleLink
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $listingId,
        public string $targetUrl,
        public string $targetHost,
        public bool $enabled,
        public EntityId $updatedByUserId,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->listingId->value())!==1){
            throw new InvalidArgumentException('Marketplace listing identifier is invalid.');
        }
        UserId::assert($this->updatedByUserId);
        if($this->targetUrl===''||strlen($this->targetUrl)>2048||preg_match('/[\x00-\x20\x7F]/',$this->targetUrl)===1){
            throw new InvalidArgumentException('Marketplace external-sale URL is invalid.');
        }
        if(strlen($this->targetHost)>253||filter_var($this->targetHost,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false){
            throw new InvalidArgumentException('Marketplace external-sale host is invalid.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->updatedAt<$this->createdAt)throw new InvalidArgumentException('Marketplace external-sale timestamps are invalid.');
    }
}
