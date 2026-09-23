<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics\Commerce;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use InvalidArgumentException;

final readonly class DatabaseCommerceAnalyticsRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function snapshot(int $days, ?DateTimeImmutable $now = null): CommerceAnalyticsSnapshot
    {
        if (!in_array($days, [7, 30, 90], true)) {
            throw new InvalidArgumentException('Commerce analytics range must be 7, 30 or 90 days.');
        }

        $utc = new DateTimeZone('UTC');
        $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        $end = $now->setTime(0, 0)->add(new DateInterval('P1D'));
        $start = $end->sub(new DateInterval('P' . $days . 'D'));
        $window = ['start'=>self::format($start), 'end'=>self::format($end)];

        $listingsCreated = $this->count(
            'SELECT COUNT(*) FROM forwext_marketplace_listings '
            . 'WHERE created_at_utc>=:start AND created_at_utc<:end',
            $window,
        );
        $activeListings = $this->count(
            "SELECT COUNT(*) FROM forwext_marketplace_listings WHERE state='active'",
        );
        $listingViews = $this->count(
            "SELECT COUNT(*) FROM forwext_analytics_events WHERE event_key='marketplace.listing.view' "
            . "AND content_type='marketplace_listing' AND occurred_at_utc>=:start AND occurred_at_utc<:end",
            $window,
        );
        $externalClicks = $this->count(
            'SELECT COUNT(*) FROM forwext_marketplace_external_sale_clicks '
            . 'WHERE clicked_at_utc>=:start AND clicked_at_utc<:end',
            $window,
        );

        $ordersCreated = $this->count(
            'SELECT COUNT(*) FROM forwext_marketplace_orders '
            . 'WHERE created_at_utc>=:start AND created_at_utc<:end',
            $window,
        );
        $ordersPaid = $this->transitionCount('to_payment_state', 'from_payment_state', 'paid', $window);
        $ordersCompleted = $this->transitionCount('to_order_state', 'from_order_state', 'completed', $window);
        $ordersCancelled = $this->transitionCount('to_order_state', 'from_order_state', 'cancelled', $window);

        $referralClicks = $this->count(
            'SELECT COUNT(*) FROM forwext_referral_clicks '
            . 'WHERE clicked_at_utc>=:start AND clicked_at_utc<:end',
            $window,
        );
        $referralAttributed = $this->count(
            'SELECT COUNT(*) FROM forwext_referral_attributions '
            . 'WHERE attributed_at_utc>=:start AND attributed_at_utc<:end',
            $window,
        );
        $referralReview = $this->count(
            "SELECT COUNT(*) FROM forwext_referral_attributions WHERE state='review' "
            . 'AND attributed_at_utc>=:start AND attributed_at_utc<:end',
            $window,
        );
        $referralQualified = $this->count(
            "SELECT COUNT(*) FROM forwext_referral_attributions WHERE state='qualified' "
            . 'AND attributed_at_utc>=:start AND attributed_at_utc<:end',
            $window,
        );
        $referralRejected = $this->count(
            "SELECT COUNT(*) FROM forwext_referral_attributions WHERE state='rejected' "
            . 'AND attributed_at_utc>=:start AND attributed_at_utc<:end',
            $window,
        );
        $referralRewardUnits = $this->count(
            "SELECT COALESCE(SUM(units),0) FROM forwext_referral_rewards WHERE state='granted' "
            . 'AND granted_at_utc>=:start AND granted_at_utc<:end',
            $window,
        );

        $giveawaysCreated = $this->count(
            'SELECT COUNT(*) FROM forwext_giveaways '
            . 'WHERE created_at_utc>=:start AND created_at_utc<:end',
            $window,
        );
        $giveawayParticipants = $this->count(
            'SELECT COUNT(DISTINCT user_id) FROM forwext_giveaway_entries '
            . 'WHERE entered_at_utc>=:start AND entered_at_utc<:end',
            $window,
        );
        $giveawayEntries = $this->count(
            'SELECT COALESCE(SUM(entry_count),0) FROM forwext_giveaway_entries '
            . 'WHERE entered_at_utc>=:start AND entered_at_utc<:end',
            $window,
        );
        $giveawayDraws = $this->count(
            'SELECT COUNT(*) FROM forwext_giveaway_draws '
            . 'WHERE created_at_utc>=:start AND created_at_utc<:end',
            $window,
        );

        return new CommerceAnalyticsSnapshot(
            $days,
            $listingsCreated,
            $activeListings,
            $listingViews,
            $externalClicks,
            CommerceAnalyticsSnapshot::ratioPercent($externalClicks, $listingViews),
            $ordersCreated,
            $ordersPaid,
            $ordersCompleted,
            $ordersCancelled,
            $this->marketplaceMoney($window),
            $this->advertisingRevenue($window),
            $referralClicks,
            $referralAttributed,
            $referralReview,
            $referralQualified,
            $referralRejected,
            $referralRewardUnits,
            CommerceAnalyticsSnapshot::ratioPercent($referralAttributed, $referralClicks),
            CommerceAnalyticsSnapshot::ratioPercent($referralQualified, $referralAttributed),
            $this->referralCampaigns($window),
            $giveawaysCreated,
            $giveawayParticipants,
            $giveawayEntries,
            $giveawayDraws,
            $this->giveaways($window),
            $now,
        );
    }

    /**
     * @param array{start:string,end:string} $window
     * @return list<array{currency:string,paid_orders:int,gmv_minor:int,refund_minor:int,net_payment_flow_minor:int}>
     */
    private function marketplaceMoney(array $window): array
    {
        $paidRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT o.currency,COUNT(*) AS paid_orders,COALESCE(SUM(o.total_minor),0) AS gmv_minor '
            . 'FROM (SELECT order_id,MIN(created_at_utc) AS paid_at FROM forwext_marketplace_order_history '
            . "WHERE to_payment_state='paid' AND from_payment_state<>'paid' GROUP BY order_id "
            . 'HAVING paid_at>=:start AND paid_at<:end) paid '
            . 'INNER JOIN forwext_marketplace_orders o ON o.order_id=paid.order_id '
            . 'GROUP BY o.currency ORDER BY o.currency',
            $window,
        ));
        $refundRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT a.currency,COALESCE(SUM(r.amount_minor),0) AS refund_minor '
            . 'FROM forwext_payment_refunds r '
            . 'INNER JOIN forwext_payment_attempts a ON a.attempt_id=r.attempt_id '
            . "WHERE r.state='succeeded' AND r.updated_at_utc>=:start AND r.updated_at_utc<:end "
            . 'GROUP BY a.currency ORDER BY a.currency',
            $window,
        ));

        /** @var array<string,array{currency:string,paid_orders:int,gmv_minor:int,refund_minor:int,net_payment_flow_minor:int}> $money */
        $money = [];
        foreach ($paidRows as $row) {
            $currency = strtoupper((string) ($row['currency'] ?? ''));
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                continue;
            }
            $money[$currency] = [
                'currency'=>$currency,
                'paid_orders'=>max(0, (int) ($row['paid_orders'] ?? 0)),
                'gmv_minor'=>max(0, (int) ($row['gmv_minor'] ?? 0)),
                'refund_minor'=>0,
                'net_payment_flow_minor'=>max(0, (int) ($row['gmv_minor'] ?? 0)),
            ];
        }
        foreach ($refundRows as $row) {
            $currency = strtoupper((string) ($row['currency'] ?? ''));
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                continue;
            }
            $money[$currency] ??= [
                'currency'=>$currency,
                'paid_orders'=>0,
                'gmv_minor'=>0,
                'refund_minor'=>0,
                'net_payment_flow_minor'=>0,
            ];
            $refund = max(0, (int) ($row['refund_minor'] ?? 0));
            $money[$currency]['refund_minor'] = $refund;
            $money[$currency]['net_payment_flow_minor'] = $money[$currency]['gmv_minor'] - $refund;
        }

        ksort($money, SORT_STRING);
        return array_values($money);
    }

    /**
     * @param array{start:string,end:string} $window
     * @return list<array{currency:string,impressions:int,clicks:int,estimated_revenue_minor:int,ctr:?float}>
     */
    private function advertisingRevenue(array $window): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT c.currency,'
            . "COALESCE(SUM(e.event_type='impression'),0) AS impressions,"
            . "COALESCE(SUM(e.event_type='click'),0) AS clicks,"
            . "COALESCE(SUM(CASE WHEN e.event_type='impression' THEN c.impression_value_minor "
            . "WHEN e.event_type='click' THEN c.click_value_minor ELSE 0 END),0) AS estimated_revenue_minor "
            . 'FROM forwext_ad_campaigns c LEFT JOIN forwext_ad_events e '
            . 'ON e.campaign_id=c.campaign_id AND e.occurred_at_utc>=:start AND e.occurred_at_utc<:end '
            . "WHERE c.kind='advertisement' GROUP BY c.currency ORDER BY c.currency",
            $window,
        ));

        $result = [];
        foreach ($rows as $row) {
            $currency = strtoupper((string) ($row['currency'] ?? ''));
            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                continue;
            }
            $impressions = max(0, (int) ($row['impressions'] ?? 0));
            $clicks = max(0, (int) ($row['clicks'] ?? 0));
            $result[] = [
                'currency'=>$currency,
                'impressions'=>$impressions,
                'clicks'=>$clicks,
                'estimated_revenue_minor'=>max(0, (int) ($row['estimated_revenue_minor'] ?? 0)),
                'ctr'=>CommerceAnalyticsSnapshot::ratioPercent($clicks, $impressions),
            ];
        }

        return $result;
    }

    /**
     * @param array{start:string,end:string} $window
     * @return list<array{campaign_key:string,name:string,clicks:int,attributed:int,review:int,qualified:int,rejected:int,reward_units:int,click_to_attribution:?float,attribution_to_qualified:?float}>
     */
    private function referralCampaigns(array $window): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT c.campaign_key,c.name,'
            . 'COALESCE(clicks.total,0) AS clicks,COALESCE(attrs.total,0) AS attributed,'
            . 'COALESCE(attrs.review_count,0) AS review_count,COALESCE(attrs.qualified_count,0) AS qualified_count,'
            . 'COALESCE(attrs.rejected_count,0) AS rejected_count,COALESCE(rewards.reward_units,0) AS reward_units '
            . 'FROM forwext_referral_campaigns c '
            . 'LEFT JOIN (SELECT campaign_id,COUNT(*) AS total FROM forwext_referral_clicks '
            . 'WHERE clicked_at_utc>=:click_start AND clicked_at_utc<:click_end GROUP BY campaign_id) clicks '
            . 'ON clicks.campaign_id=c.campaign_id '
            . 'LEFT JOIN (SELECT campaign_id,COUNT(*) AS total,'
            . "SUM(state='review') AS review_count,SUM(state='qualified') AS qualified_count,"
            . "SUM(state='rejected') AS rejected_count FROM forwext_referral_attributions "
            . 'WHERE attributed_at_utc>=:attr_start AND attributed_at_utc<:attr_end GROUP BY campaign_id) attrs '
            . 'ON attrs.campaign_id=c.campaign_id '
            . 'LEFT JOIN (SELECT campaign_id,COALESCE(SUM(units),0) AS reward_units FROM forwext_referral_rewards '
            . "WHERE state='granted' AND granted_at_utc>=:reward_start AND granted_at_utc<:reward_end "
            . 'GROUP BY campaign_id) rewards ON rewards.campaign_id=c.campaign_id '
            . 'WHERE clicks.total IS NOT NULL OR attrs.total IS NOT NULL OR rewards.reward_units IS NOT NULL '
            . 'ORDER BY qualified_count DESC,attributed DESC,clicks DESC,c.campaign_key LIMIT 100',
            [
                'click_start'=>$window['start'],
                'click_end'=>$window['end'],
                'attr_start'=>$window['start'],
                'attr_end'=>$window['end'],
                'reward_start'=>$window['start'],
                'reward_end'=>$window['end'],
            ],
        ));

        return array_map(static function (array $row): array {
            $clicks = max(0, (int) ($row['clicks'] ?? 0));
            $attributed = max(0, (int) ($row['attributed'] ?? 0));
            $qualified = max(0, (int) ($row['qualified_count'] ?? 0));
            return [
                'campaign_key'=>(string) ($row['campaign_key'] ?? ''),
                'name'=>(string) ($row['name'] ?? ''),
                'clicks'=>$clicks,
                'attributed'=>$attributed,
                'review'=>max(0, (int) ($row['review_count'] ?? 0)),
                'qualified'=>$qualified,
                'rejected'=>max(0, (int) ($row['rejected_count'] ?? 0)),
                'reward_units'=>max(0, (int) ($row['reward_units'] ?? 0)),
                'click_to_attribution'=>CommerceAnalyticsSnapshot::ratioPercent($attributed, $clicks),
                'attribution_to_qualified'=>CommerceAnalyticsSnapshot::ratioPercent($qualified, $attributed),
            ];
        }, $rows);
    }

    /**
     * @param array{start:string,end:string} $window
     * @return list<array{giveaway_id:string,title:string,state:string,participants:int,entries:int,draws:int}>
     */
    private function giveaways(array $window): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT g.giveaway_id,g.title,g.state,'
            . 'COALESCE(entries.participants,0) AS participants,COALESCE(entries.entries,0) AS entries,'
            . 'COALESCE(draws.draws,0) AS draws '
            . 'FROM forwext_giveaways g '
            . 'LEFT JOIN (SELECT giveaway_id,COUNT(DISTINCT user_id) AS participants,'
            . 'COALESCE(SUM(entry_count),0) AS entries FROM forwext_giveaway_entries '
            . 'WHERE entered_at_utc>=:entry_start AND entered_at_utc<:entry_end GROUP BY giveaway_id) entries '
            . 'ON entries.giveaway_id=g.giveaway_id '
            . 'LEFT JOIN (SELECT giveaway_id,COUNT(*) AS draws FROM forwext_giveaway_draws '
            . 'WHERE created_at_utc>=:draw_start AND created_at_utc<:draw_end GROUP BY giveaway_id) draws '
            . 'ON draws.giveaway_id=g.giveaway_id '
            . 'WHERE (g.created_at_utc>=:created_start AND g.created_at_utc<:created_end) '
            . 'OR entries.giveaway_id IS NOT NULL OR draws.giveaway_id IS NOT NULL '
            . 'ORDER BY participants DESC,entries DESC,draws DESC,g.updated_at_utc DESC LIMIT 100',
            [
                'entry_start'=>$window['start'],
                'entry_end'=>$window['end'],
                'draw_start'=>$window['start'],
                'draw_end'=>$window['end'],
                'created_start'=>$window['start'],
                'created_end'=>$window['end'],
            ],
        ));

        return array_map(static fn (array $row): array => [
            'giveaway_id'=>(string) ($row['giveaway_id'] ?? ''),
            'title'=>(string) ($row['title'] ?? ''),
            'state'=>(string) ($row['state'] ?? ''),
            'participants'=>max(0, (int) ($row['participants'] ?? 0)),
            'entries'=>max(0, (int) ($row['entries'] ?? 0)),
            'draws'=>max(0, (int) ($row['draws'] ?? 0)),
        ], $rows);
    }

    /** @param array{start:string,end:string} $window */
    private function transitionCount(string $toColumn, string $fromColumn, string $state, array $window): int
    {
        $allowed = [
            'to_payment_state'=>'from_payment_state',
            'to_order_state'=>'from_order_state',
        ];
        if (($allowed[$toColumn] ?? null) !== $fromColumn || preg_match('/^[a-z_]{2,24}$/D', $state) !== 1) {
            throw new InvalidArgumentException('Commerce transition analytics query is invalid.');
        }

        return $this->count(
            'SELECT COUNT(*) FROM (SELECT order_id,MIN(created_at_utc) AS transitioned_at '
            . 'FROM forwext_marketplace_order_history '
            . 'WHERE '.$toColumn.'=:state AND '.$fromColumn.'<>:state_previous GROUP BY order_id '
            . 'HAVING transitioned_at>=:start AND transitioned_at<:end) transitions',
            [
                'state'=>$state,
                'state_previous'=>$state,
                'start'=>$window['start'],
                'end'=>$window['end'],
            ],
        );
    }

    /** @param array<string,mixed> $parameters */
    private function count(string $sql, array $parameters = []): int
    {
        return max(0, (int) $this->database->fetchValue(new CompiledQuery($sql, $parameters)));
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
