<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

enum ReferralRewardState: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';
}
