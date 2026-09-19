<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

enum MarketplaceListingState:string
{
    case Draft='draft';
    case Pending='pending';
    case Active='active';
    case Paused='paused';
    case Sold='sold';
    case Closed='closed';
    case Archived='archived';

    public function publicVisible():bool
    {
        return in_array($this,[self::Active,self::Sold],true);
    }
}
