<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

enum TrophyRuleType: string
{
    case Manual = 'manual';
    case AccountAgeDays = 'account_age_days';
    case VisiblePostCount = 'visible_post_count';
    case QualifiedReferralCount = 'qualified_referral_count';
    case GiveawayWinCount = 'giveaway_win_count';
}
