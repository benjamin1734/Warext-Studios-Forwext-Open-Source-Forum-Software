<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

enum ReferralState: string
{
    case Attributed = 'attributed';
    case Review = 'review';
    case Qualified = 'qualified';
    case Rejected = 'rejected';
}
