<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace\Delivery;

enum MarketplaceDeliveryType:string
{
    case Download='download';
    case License='license';
    case Key='key';
    case Manual='manual';

    public function automatic():bool
    {
        return $this!==self::Manual;
    }
}
