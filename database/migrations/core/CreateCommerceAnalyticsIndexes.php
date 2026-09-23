<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class CreateCommerceAnalyticsIndexes implements Migration
{
    /** @var array<string,array<string,list<string>>> */
    private const INDEXES = [
        'forwext_marketplace_listings' => [
            'idx_forwext_market_listing_created_analytics' => ['created_at_utc', 'state', 'currency', 'listing_id'],
        ],
        'forwext_marketplace_external_sale_clicks' => [
            'idx_forwext_market_external_click_time' => ['clicked_at_utc', 'listing_id'],
        ],
        'forwext_marketplace_orders' => [
            'idx_forwext_market_order_created_analytics' => ['created_at_utc', 'order_state', 'payment_state', 'currency', 'order_id'],
        ],
        'forwext_marketplace_order_history' => [
            'idx_forwext_market_order_history_payment_time' => ['to_payment_state', 'created_at_utc', 'order_id'],
            'idx_forwext_market_order_history_order_time' => ['to_order_state', 'created_at_utc', 'order_id'],
        ],
        'forwext_payment_refunds' => [
            'idx_forwext_payment_refund_updated_state' => ['updated_at_utc', 'state', 'attempt_id'],
        ],
        'forwext_referral_clicks' => [
            'idx_forwext_referral_click_time' => ['clicked_at_utc', 'campaign_id'],
        ],
        'forwext_referral_attributions' => [
            'idx_forwext_referral_attr_time_state' => ['attributed_at_utc', 'state', 'campaign_id'],
        ],
        'forwext_referral_rewards' => [
            'idx_forwext_referral_reward_time_state' => ['granted_at_utc', 'state', 'campaign_id'],
        ],
        'forwext_giveaway_entries' => [
            'idx_forwext_giveaway_entry_entered' => ['entered_at_utc', 'giveaway_id', 'user_id'],
        ],
        'forwext_giveaway_draws' => [
            'idx_forwext_giveaway_draw_time' => ['created_at_utc', 'giveaway_id'],
        ],
        'forwext_ad_events' => [
            'idx_forwext_ad_event_time' => ['occurred_at_utc', 'event_type', 'campaign_id'],
        ],
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260923195000_commerce_analytics_indexes');
    }

    public function owner(): MigrationOwner
    {
        return MigrationOwner::core();
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(MigrationContext $context): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $index => $columns) {
                if ($this->hasIndex($context, $table, $index)) {
                    continue;
                }

                $quoted = array_map(static fn (string $column): string => '`'.$column.'`', $columns);
                $context->execute(new CompiledQuery(
                    'ALTER TABLE `'.$table.'` ADD INDEX `'.$index.'` ('.implode(',', $quoted).')',
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $index) {
                if (!$this->hasIndex($context, $table, $index)) {
                    return MigrationVerification::failed('Commerce analytics query index is missing: '.$index);
                }
            }
        }

        return MigrationVerification::passed();
    }

    private function hasIndex(MigrationContext $context, string $table, string $index): bool
    {
        return (int) $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index',
            ['table'=>$table, 'index'=>$index],
        )) > 0;
    }
}
