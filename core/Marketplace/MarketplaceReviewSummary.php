<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use InvalidArgumentException;

final readonly class MarketplaceReviewSummary
{
    public function __construct(public int $count,public int $ratingTotal)
    {
        if($this->count<0||$this->ratingTotal<0||$this->ratingTotal>$this->count*5){
            throw new InvalidArgumentException('Marketplace review summary is invalid.');
        }
    }
    public function average():?float{return $this->count===0?null:$this->ratingTotal/$this->count;}
}
