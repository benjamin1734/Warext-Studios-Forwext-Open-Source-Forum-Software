<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceListingCard
{
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $listingId,
        public EntityId $sellerUserId,
        public EntityId $categoryId,
        public string $slug,
        public string $title,
        public MarketplaceMoney $price,
        public MarketplaceListingState $state,
        public string $sellerUsername,
        public string $categoryName,
        public bool $featured,
        public bool $pinned,
        public int $reviewCount,
        public int $ratingTotal,
        DateTimeImmutable $updatedAt,
    ){
        UserId::assert($this->sellerUserId);
        if($this->reviewCount<0||$this->ratingTotal<0||$this->ratingTotal>$this->reviewCount*5){
            throw new InvalidArgumentException('Marketplace listing rating aggregate is invalid.');
        }
        $this->updatedAt=$updatedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function averageRating():?float
    {
        return $this->reviewCount===0?null:$this->ratingTotal/$this->reviewCount;
    }
}
