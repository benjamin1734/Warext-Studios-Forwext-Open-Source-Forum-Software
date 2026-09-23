<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\Engagement\ContentEngagementSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContentEngagementSnapshotTest extends TestCase
{
    public function testRatesAreExplicitViewToActionProxies():void
    {
        self::assertSame(25.0,ContentEngagementSnapshot::rate(5,20));
        self::assertSame(150.0,ContentEngagementSnapshot::rate(3,2));
        self::assertNull(ContentEngagementSnapshot::rate(3,0));
    }

    public function testOnlyDashboardWindowsAreAccepted():void
    {
        $this->expectException(InvalidArgumentException::class);
        new ContentEngagementSnapshot(
            14,0,0,0,0,0,0,0,0,[],[],[],[],
            new DateTimeImmutable('2026-09-23 16:00:00',new DateTimeZone('UTC')),
        );
    }
}
