<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\Dashboard\ForumAnalyticsDailyRow;
use Forwext\Core\Analytics\Dashboard\ForumAnalyticsSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ForumAnalyticsDashboardTest extends TestCase
{
    public function testGrowthPercentHandlesNormalZeroAndNewBaseCases(): void
    {
        self::assertSame(50.0, ForumAnalyticsSnapshot::growthPercent(150, 100));
        self::assertSame(-25.0, ForumAnalyticsSnapshot::growthPercent(75, 100));
        self::assertSame(0.0, ForumAnalyticsSnapshot::growthPercent(0, 0));
        self::assertNull(ForumAnalyticsSnapshot::growthPercent(10, 0));
    }

    public function testSnapshotAcceptsSupportedRangeAndPrivacySafeRetentionRates(): void
    {
        $day = new ForumAnalyticsDailyRow(
            new DateTimeImmutable('2026-09-23', new DateTimeZone('UTC')),
            3, 5, 20, 12,
        );
        $snapshot = new ForumAnalyticsSnapshot(
            30, 12, 44, 100, 3, 25, 20, 800, 35, 30, 6400, 240, 200,
            9, 14, 18, 25.0, 10.0, [$day],
            new DateTimeImmutable('2026-09-23 15:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame(30, $snapshot->windowDays);
        self::assertSame(12, $snapshot->dau);
        self::assertSame(44, $snapshot->mau);
        self::assertSame(25.0, $snapshot->retention7);
        self::assertCount(1, $snapshot->daily);
    }

    public function testSnapshotRejectsUnsupportedDashboardRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ForumAnalyticsSnapshot(
            6, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, null, null, [],
            new DateTimeImmutable('2026-09-23 15:00:00', new DateTimeZone('UTC')),
        );
    }
}
