<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

enum MarketplaceOrderState:string
{
    case Pending='pending';
    case Confirmed='confirmed';
    case Completed='completed';
    case Cancelled='cancelled';
}
