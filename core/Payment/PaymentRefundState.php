<?php

declare(strict_types=1);

namespace Forwext\Core\Payment;

enum PaymentRefundState:string
{
    case Pending='pending';
    case Succeeded='succeeded';
    case Failed='failed';
    case Cancelled='cancelled';

    public function terminal():bool{return $this!==self::Pending;}
}
