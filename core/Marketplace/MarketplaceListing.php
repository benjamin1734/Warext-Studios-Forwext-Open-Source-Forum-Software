<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceListing
{
    /** @var list<string> */
    public array $tags;
    /** @var list<MarketplaceMedia> */
    public array $media;
    /** @var array<string,string|int|bool> */
    public array $customValues;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $tags
     * @param list<MarketplaceMedia> $media
     * @param array<string,string|int|bool> $customValues
     */
    public function __construct(
        public EntityId $listingId,
        public EntityId $sellerUserId,
        public EntityId $categoryId,
        public string $slug,
        public string $title,
        public string $description,
        public MarketplaceMoney $price,
        array $tags,
        array $media,
        array $customValues,
        public MarketplaceListingState $state,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ){
        UserId::assert($this->sellerUserId);
        foreach([$this->listingId,$this->categoryId] as $id){
            if(preg_match('/^[a-f0-9]{32}$/D',$id->value())!==1)throw new InvalidArgumentException('Marketplace listing identifier is invalid.');
        }
        if(preg_match('/^[a-z0-9][a-z0-9-]{1,159}$/D',$this->slug)!==1){
            throw new InvalidArgumentException('Marketplace listing slug is invalid.');
        }
        self::text($this->title,180,'title',false);
        self::text($this->description,120000,'description',false);

        $tagMap=[];
        foreach($tags as $tag){
            if(!is_string($tag))throw new InvalidArgumentException('Marketplace tags must be strings.');
            $tag=strtolower(trim($tag));
            if(preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$tag)!==1)throw new InvalidArgumentException('Marketplace tag is invalid.');
            $tagMap[$tag]=true;
        }
        if(count($tagMap)>32)throw new InvalidArgumentException('Marketplace tag limit exceeded.');
        $this->tags=array_keys($tagMap);

        if(count($media)>20)throw new InvalidArgumentException('Marketplace media limit exceeded.');
        $prefix='marketplace/'.$this->listingId->value().'/';
        foreach($media as $item){
            if(!$item instanceof MarketplaceMedia||!str_starts_with($item->storagePath,$prefix)){
                throw new InvalidArgumentException('Marketplace media does not belong to the listing.');
            }
        }
        $this->media=array_values($media);

        if(count($customValues)>64)throw new InvalidArgumentException('Marketplace custom value limit exceeded.');
        $values=[];
        foreach($customValues as $key=>$value){
            if(!is_string($key)||preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D',$key)!==1
                ||(!is_string($value)&&!is_int($value)&&!is_bool($value))
            ){
                throw new InvalidArgumentException('Marketplace custom value is invalid.');
            }
            $values[$key]=$value;
        }
        $this->customValues=$values;

        $utc=new DateTimeZone('UTC');
        $this->createdAt=$createdAt->setTimezone($utc);
        $this->updatedAt=$updatedAt->setTimezone($utc);
        if($this->updatedAt<$this->createdAt)throw new InvalidArgumentException('Marketplace listing timestamps are invalid.');
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}

    private static function text(string $value,int $max,string $label,bool $allowEmpty):void
    {
        if(preg_match('//u',$value)!==1||strlen($value)>$max||(!$allowEmpty&&trim($value)==='')
            ||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)===1
        ){
            throw new InvalidArgumentException('Marketplace '.$label.' is invalid.');
        }
    }
}
