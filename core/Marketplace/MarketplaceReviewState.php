<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

enum MarketplaceReviewState:string
{
    case Pending='pending';
    case Visible='visible';
    case Hidden='hidden';
}
