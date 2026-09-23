<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\Operations\OperationsAnalyticsSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OperationsAnalyticsSnapshotTest extends TestCase
{
    public function testSnapshotNormalizesUtcAndAcceptsBoundedRows(): void
    {
        $snapshot = new OperationsAnalyticsSnapshot(
            30,
            10, 5, 3, 1, 120.0,
            4, 2, 1, 1,
            8, 5, 3, 2, 1, 300.0, 7200.0,
            6, 2, 1, 1, 3600.0,
            [[
                'key' => 'general', 'label' => 'Genel', 'total' => 6,
                'active' => 2, 'resolved' => 2, 'rejected' => 1, 'duplicate' => 1,
            ]],
            [[
                'id' => str_repeat('a', 32), 'username' => 'staff', 'active_reports' => 1,
                'active_support' => 2, 'active_bugs' => 3, 'discipline_actions' => 4,
                'audit_actions' => 5, 'total' => 15,
            ]],
            new DateTimeImmutable('2026-09-23 21:00:00', new DateTimeZone('Europe/Istanbul')),
        );

        self::assertSame(30, $snapshot->windowDays);
        self::assertSame('UTC', $snapshot->generatedAt->getTimezone()->getName());
        self::assertSame(15, $snapshot->staffWorkload[0]['total']);
    }

    public function testSnapshotRejectsUnsupportedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OperationsAnalyticsSnapshot(
            14,
            0, 0, 0, 0, null,
            0, 0, 0, 0,
            0, 0, 0, 0, 0, null, null,
            0, 0, 0, 0, null,
            [], [],
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
    }
}
