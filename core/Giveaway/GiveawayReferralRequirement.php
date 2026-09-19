<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

enum GiveawayReferralRequirement: string
{
    case None = 'none';
    case ReferredQualified = 'referred_qualified';
    case QualifiedReferrer = 'qualified_referrer';
}
