<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceReview
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $reviewId,
        public EntityId $listingId,
        public EntityId $reviewerUserId,
        public int $rating,
        public string $body,
        public MarketplaceReviewState $state,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ){
        UserId::assert($this->reviewerUserId);
        foreach([$this->reviewId,$this->listingId] as $id){
            if(preg_match('/^[a-f0-9]{32}$/D',$id->value())!==1)throw new InvalidArgumentException('Marketplace review id is invalid.');
        }
        if($this->rating<1||$this->rating>5||trim($this->body)===''||strlen($this->body)>5000||preg_match('//u',$this->body)!==1
            ||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$this->body)===1
        )throw new InvalidArgumentException('Marketplace review is invalid.');
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);$this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->updatedAt<$this->createdAt)throw new InvalidArgumentException('Marketplace review timestamps are invalid.');
    }
    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}
}
