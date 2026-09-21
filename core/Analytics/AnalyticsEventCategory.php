<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

enum AnalyticsEventCategory:string
{
    case Forum='forum';
    case User='user';
    case Content='content';
    case Support='support';
    case Bug='bug';
    case Marketplace='marketplace';
    case Referral='referral';
    case Giveaway='giveaway';
    case Moderation='moderation';
}
