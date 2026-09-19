<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MarketplaceCategory
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function __construct(
        public EntityId $categoryId,
        public ?EntityId $parentCategoryId,
        public string $key,
        public string $slug,
        public string $name,
        public string $description,
        public bool $enabled,
        public int $sortOrder,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->categoryId->value())!==1
            ||($this->parentCategoryId!==null&&preg_match('/^[a-f0-9]{32}$/D',$this->parentCategoryId->value())!==1)
            ||preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D',$this->key)!==1
            ||preg_match('/^[a-z0-9][a-z0-9-]{1,95}$/D',$this->slug)!==1
            ||trim($this->name)===''||strlen($this->name)>120||preg_match('//u',$this->name)!==1
            ||strlen($this->description)>4000||preg_match('//u',$this->description)!==1
            ||$this->sortOrder<0||$this->sortOrder>65535
        ){
            throw new InvalidArgumentException('Marketplace category is invalid.');
        }
        if($this->parentCategoryId?->equals($this->categoryId)===true){
            throw new InvalidArgumentException('Marketplace category cannot parent itself.');
        }
        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->updatedAt<$this->createdAt)throw new InvalidArgumentException('Marketplace category timestamps are invalid.');
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}
}
