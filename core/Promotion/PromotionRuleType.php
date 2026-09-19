<?php

declare(strict_types=1);

namespace Forwext\Core\Promotion;

enum PromotionRuleType: string
{
    case AccountAgeDays = 'account_age_days';
    case VisiblePostCount = 'visible_post_count';
    case QualifiedReferralCount = 'qualified_referral_count';
    case GiveawayWinCount = 'giveaway_win_count';
    case ActiveTrophyCount = 'active_trophy_count';
}
