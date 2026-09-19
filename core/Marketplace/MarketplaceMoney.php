<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use InvalidArgumentException;

final readonly class MarketplaceMoney
{
    public function __construct(
        public int $minorUnits,
        public string $currency,
    ){
        if($this->minorUnits<0||$this->minorUnits>9_000_000_000_000_000){
            throw new InvalidArgumentException('Marketplace price is outside the supported range.');
        }
        if(preg_match('/^[A-Z]{3}$/D',$this->currency)!==1){
            throw new InvalidArgumentException('Marketplace currency must be a 3-letter ISO-style code.');
        }
    }
}
