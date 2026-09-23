<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Report;

enum AnalyticsReportDataset: string
{
    case ForumActivity = 'forum_activity';
    case ContentActivity = 'content_activity';
    case OperationsActivity = 'operations_activity';
    case CommerceOrders = 'commerce_orders';
    case ReferralFunnel = 'referral_funnel';
    case GiveawayParticipation = 'giveaway_participation';

    public function label(): string
    {
        return match ($this) {
            self::ForumActivity => 'Forum / kullanıcı aktivitesi',
            self::ContentActivity => 'İçerik aktivitesi',
            self::OperationsActivity => 'Moderasyon / destek / bug operasyonları',
            self::CommerceOrders => 'Marketplace order',
            self::ReferralFunnel => 'Referral funnel',
            self::GiveawayParticipation => 'Giveaway participation',
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::ForumActivity => 'analytics.view_forum',
            self::ContentActivity => 'analytics.view_content',
            self::OperationsActivity => 'analytics.view_operations',
            self::CommerceOrders,
            self::ReferralFunnel,
            self::GiveawayParticipation => 'analytics.view_commerce',
        };
    }

    /** @return list<string> */
    public function allowedFilters(): array
    {
        return match ($this) {
            self::ForumActivity => ['event_key'],
            self::ContentActivity => ['event_key', 'content_type'],
            self::OperationsActivity => ['scope', 'action'],
            self::CommerceOrders => ['currency', 'order_state', 'payment_state'],
            self::ReferralFunnel => ['campaign', 'state'],
            self::GiveawayParticipation => ['state'],
        };
    }
}
