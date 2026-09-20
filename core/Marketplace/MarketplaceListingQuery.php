<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class MarketplaceListingQuery
{
    public function __construct(
        public ?string $text=null,
        public ?EntityId $categoryId=null,
        public ?EntityId $sellerUserId=null,
        public ?string $currency=null,
        public ?int $minPriceMinor=null,
        public ?int $maxPriceMinor=null,
        public ?string $tag=null,
        public MarketplaceListingSort $sort=MarketplaceListingSort::Featured,
        public bool $featuredOnly=false,
        public bool $pinnedOnly=false,
    ){
        if($this->text!==null&&(trim($this->text)===''||strlen($this->text)>200||preg_match('//u',$this->text)!==1)){
            throw new InvalidArgumentException('Marketplace search text is invalid.');
        }
        if($this->sellerUserId!==null)UserId::assert($this->sellerUserId);
        if($this->currency!==null&&preg_match('/^[A-Z]{3}$/D',$this->currency)!==1){
            throw new InvalidArgumentException('Marketplace query currency is invalid.');
        }
        foreach([$this->minPriceMinor,$this->maxPriceMinor] as $value){
            if($value!==null&&($value<0||$value>9_000_000_000_000_000))throw new InvalidArgumentException('Marketplace query price is invalid.');
        }
        if($this->minPriceMinor!==null&&$this->maxPriceMinor!==null&&$this->minPriceMinor>$this->maxPriceMinor){
            throw new InvalidArgumentException('Marketplace minimum price exceeds maximum price.');
        }
        if($this->tag!==null&&preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$this->tag)!==1){
            throw new InvalidArgumentException('Marketplace query tag is invalid.');
        }
    }
}
