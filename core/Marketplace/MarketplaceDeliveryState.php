<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

enum MarketplaceDeliveryState:string
{
    case Pending='pending';
    case Ready='ready';
    case Delivered='delivered';
    case Cancelled='cancelled';
}
