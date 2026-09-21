<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

enum MarketplacePaymentState:string
{
    case Pending='pending';
    case Authorized='authorized';
    case Paid='paid';
    case Failed='failed';
    case Cancelled='cancelled';
    case Refunded='refunded';
}
