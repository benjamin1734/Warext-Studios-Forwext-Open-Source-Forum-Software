<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

enum MarketplaceDeliveryKeyState:string
{
    case Available='available';
    case Assigned='assigned';
    case Revoked='revoked';
}
