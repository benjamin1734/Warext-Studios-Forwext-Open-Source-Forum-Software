<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceListingPromotion
{
    public ?DateTimeImmutable $featuredUntil;
    public ?DateTimeImmutable $pinnedUntil;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $listingId,
        ?DateTimeImmutable $featuredUntil,
        ?DateTimeImmutable $pinnedUntil,
        public EntityId $updatedByUserId,
        DateTimeImmutable $updatedAt,
    ){
        UserId::assert($this->updatedByUserId);
        if(preg_match('/^[a-f0-9]{32}$/D',$this->listingId->value())!==1)throw new InvalidArgumentException('Marketplace promotion listing id is invalid.');
        $utc=new DateTimeZone('UTC');
        $this->featuredUntil=$featuredUntil?->setTimezone($utc);
        $this->pinnedUntil=$pinnedUntil?->setTimezone($utc);
        $this->updatedAt=$updatedAt->setTimezone($utc);
    }

    public function featuredAt(DateTimeImmutable $at):bool{return $this->featuredUntil!==null&&$this->featuredUntil>$at;}
    public function pinnedAt(DateTimeImmutable $at):bool{return $this->pinnedUntil!==null&&$this->pinnedUntil>$at;}
}
