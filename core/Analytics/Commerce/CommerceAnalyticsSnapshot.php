<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Commerce;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class CommerceAnalyticsSnapshot
{
    public DateTimeImmutable $generatedAt;

    /**
     * @param list<array{currency:string,paid_orders:int,gmv_minor:int,refund_minor:int,net_payment_flow_minor:int}> $marketplaceMoney
     * @param list<array{currency:string,impressions:int,clicks:int,estimated_revenue_minor:int,ctr:?float}> $advertisingRevenue
     * @param list<array{campaign_key:string,name:string,clicks:int,attributed:int,review:int,qualified:int,rejected:int,reward_units:int,click_to_attribution:?float,attribution_to_qualified:?float}> $referralCampaigns
     * @param list<array{giveaway_id:string,title:string,state:string,participants:int,entries:int,draws:int}> $giveaways
     */
    public function __construct(
        public int $windowDays,
        public int $listingsCreated,
        public int $activeListings,
        public int $listingViews,
        public int $externalClicks,
        public ?float $externalCtr,
        public int $ordersCreated,
        public int $ordersPaid,
        public int $ordersCompleted,
        public int $ordersCancelled,
        public array $marketplaceMoney,
        public array $advertisingRevenue,
        public int $referralClicks,
        public int $referralAttributed,
        public int $referralReview,
        public int $referralQualified,
        public int $referralRejected,
        public int $referralRewardUnits,
        public ?float $referralAttributionConversion,
        public ?float $referralQualifiedConversion,
        public array $referralCampaigns,
        public int $giveawaysCreated,
        public int $giveawayParticipants,
        public int $giveawayEntries,
        public int $giveawayDraws,
        public array $giveaways,
        DateTimeImmutable $generatedAt,
    ) {
        if (!in_array($this->windowDays, [7, 30, 90], true)) {
            throw new InvalidArgumentException('Commerce analytics range must be 7, 30 or 90 days.');
        }

        foreach ([
            $this->listingsCreated,
            $this->activeListings,
            $this->listingViews,
            $this->externalClicks,
            $this->ordersCreated,
            $this->ordersPaid,
            $this->ordersCompleted,
            $this->ordersCancelled,
            $this->referralClicks,
            $this->referralAttributed,
            $this->referralReview,
            $this->referralQualified,
            $this->referralRejected,
            $this->referralRewardUnits,
            $this->giveawaysCreated,
            $this->giveawayParticipants,
            $this->giveawayEntries,
            $this->giveawayDraws,
        ] as $value) {
            if ($value < 0) {
                throw new InvalidArgumentException('Commerce analytics count cannot be negative.');
            }
        }

        foreach ([
            $this->externalCtr,
            $this->referralAttributionConversion,
            $this->referralQualifiedConversion,
        ] as $rate) {
            self::assertRate($rate);
        }

        foreach ($this->marketplaceMoney as $row) {
            self::assertCurrency($row['currency']);
            foreach ([$row['paid_orders'], $row['gmv_minor'], $row['refund_minor']] as $value) {
                if ($value < 0) {
                    throw new InvalidArgumentException('Marketplace money metric cannot be negative.');
                }
            }
        }

        foreach ($this->advertisingRevenue as $row) {
            self::assertCurrency($row['currency']);
            foreach ([$row['impressions'], $row['clicks'], $row['estimated_revenue_minor']] as $value) {
                if ($value < 0) {
                    throw new InvalidArgumentException('Advertising analytics metric cannot be negative.');
                }
            }
            self::assertRate($row['ctr']);
        }

        foreach ($this->referralCampaigns as $row) {
            foreach ([
                $row['clicks'],
                $row['attributed'],
                $row['review'],
                $row['qualified'],
                $row['rejected'],
                $row['reward_units'],
            ] as $value) {
                if ($value < 0) {
                    throw new InvalidArgumentException('Referral analytics metric cannot be negative.');
                }
            }
            self::assertRate($row['click_to_attribution']);
            self::assertRate($row['attribution_to_qualified']);
        }

        foreach ($this->giveaways as $row) {
            foreach ([$row['participants'], $row['entries'], $row['draws']] as $value) {
                if ($value < 0) {
                    throw new InvalidArgumentException('Giveaway analytics metric cannot be negative.');
                }
            }
        }

        $this->generatedAt = $generatedAt->setTimezone(new DateTimeZone('UTC'));
    }

    public static function ratioPercent(int $numerator, int $denominator): ?float
    {
        if ($denominator === 0) {
            return null;
        }

        return ($numerator / $denominator) * 100.0;
    }

    private static function assertCurrency(string $currency): void
    {
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException('Commerce analytics currency is invalid.');
        }
    }

    private static function assertRate(?float $rate): void
    {
        if ($rate !== null && (!is_finite($rate) || $rate < 0.0)) {
            throw new InvalidArgumentException('Commerce analytics ratio must be finite and non-negative.');
        }
    }
}
