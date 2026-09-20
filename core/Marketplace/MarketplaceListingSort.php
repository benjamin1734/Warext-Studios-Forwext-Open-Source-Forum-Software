<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

enum MarketplaceListingSort:string
{
    case Featured='featured';
    case Newest='newest';
    case PriceAsc='price_asc';
    case PriceDesc='price_desc';
    case Rating='rating';
}
