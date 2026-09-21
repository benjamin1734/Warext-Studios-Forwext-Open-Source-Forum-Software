<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

enum PaymentAttemptState:string
{
    case Pending='pending';
    case RequiresAction='requires_action';
    case Authorized='authorized';
    case Paid='paid';
    case Failed='failed';
    case Cancelled='cancelled';
    case Refunded='refunded';

    public function terminal():bool
    {
        return in_array($this,[self::Paid,self::Failed,self::Cancelled,self::Refunded],true);
    }
}
