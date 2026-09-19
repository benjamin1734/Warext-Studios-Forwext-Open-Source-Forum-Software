<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Referral\ReferralMaintenanceTasks;
use Forwext\Core\Scheduler\SchedulerRegistry;
use PHPUnit\Framework\TestCase;

final class ReferralMaintenanceTest extends TestCase
{
    public function testQualificationTaskUsesBoundedTenMinuteMaintenanceSchedule(): void
    {
        $registry = new SchedulerRegistry();
        ReferralMaintenanceTasks::register($registry);

        $tasks = $registry->all();
        self::assertCount(1, $tasks);
        self::assertSame('referral.qualify', $tasks[0]->name);
        self::assertSame(ReferralMaintenanceTasks::QUALIFY_JOB_TYPE, $tasks[0]->jobType);
        self::assertSame('maintenance', $tasks[0]->queue->value());
        self::assertSame('{"limit":100}', $tasks[0]->payload);
        self::assertTrue($tasks[0]->schedule->isDue(
            new DateTimeImmutable('2026-09-19 12:20:00', new DateTimeZone('UTC')),
        ));
        self::assertFalse($tasks[0]->schedule->isDue(
            new DateTimeImmutable('2026-09-19 12:21:00', new DateTimeZone('UTC')),
        ));
    }
}
