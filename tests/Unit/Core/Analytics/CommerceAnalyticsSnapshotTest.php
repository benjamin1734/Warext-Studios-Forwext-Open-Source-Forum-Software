<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\Commerce\CommerceAnalyticsSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CommerceAnalyticsSnapshotTest extends TestCase
{
    public function testSnapshotKeepsCurrenciesSeparateAndNormalizesUtc(): void
    {
        $snapshot = new CommerceAnalyticsSnapshot(
            30,
            5,
            20,
            100,
            12,
            12.0,
            9,
            7,
            6,
            1,
            [[
                'currency'=>'TRY',
                'paid_orders'=>7,
                'gmv_minor'=>250000,
                'refund_minor'=>50000,
                'net_payment_flow_minor'=>200000,
            ]],
            [[
                'currency'=>'TRY',
                'impressions'=>1000,
                'clicks'=>50,
                'estimated_revenue_minor'=>2000,
                'ctr'=>5.0,
            ]],
            80,
            20,
            2,
            10,
            8,
            10,
            25.0,
            50.0,
            [[
                'campaign_key'=>'community',
                'name'=>'Topluluk',
                'clicks'=>80,
                'attributed'=>20,
                'review'=>2,
                'qualified'=>10,
                'rejected'=>8,
                'reward_units'=>10,
                'click_to_attribution'=>25.0,
                'attribution_to_qualified'=>50.0,
            ]],
            2,
            25,
            40,
            2,
            [[
                'giveaway_id'=>str_repeat('a', 32),
                'title'=>'Test',
                'state'=>'active',
                'participants'=>25,
                'entries'=>40,
                'draws'=>2,
            ]],
            new DateTimeImmutable('2026-09-23 22:00:00', new DateTimeZone('Europe/Istanbul')),
        );

        self::assertSame('UTC', $snapshot->generatedAt->getTimezone()->getName());
        self::assertSame('TRY', $snapshot->marketplaceMoney[0]['currency']);
        self::assertSame(200000, $snapshot->marketplaceMoney[0]['net_payment_flow_minor']);
        self::assertSame(12.0, $snapshot->externalCtr);
    }

    public function testRatioIsUnavailableWithoutDenominator(): void
    {
        self::assertNull(CommerceAnalyticsSnapshot::ratioPercent(0, 0));
        self::assertSame(25.0, CommerceAnalyticsSnapshot::ratioPercent(1, 4));
    }

    public function testSnapshotRejectsUnsupportedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CommerceAnalyticsSnapshot(
            14,
            0, 0, 0, 0, null,
            0, 0, 0, 0,
            [], [],
            0, 0, 0, 0, 0, 0, null, null,
            [],
            0, 0, 0, 0,
            [],
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }
}
