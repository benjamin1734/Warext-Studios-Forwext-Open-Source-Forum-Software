<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Giveaway\GiveawayMaintenanceTasks;
use Forwext\Core\Scheduler\SchedulerRegistry;
use PHPUnit\Framework\TestCase;

final class GiveawayMaintenanceTest extends TestCase
{
    public function testLifecycleTaskRunsEveryMinuteWithBoundedPayload(): void
    {
        $registry = new SchedulerRegistry();
        GiveawayMaintenanceTasks::register($registry);

        $tasks = $registry->all();
        self::assertCount(1, $tasks);
        self::assertSame('giveaway.lifecycle', $tasks[0]->name);
        self::assertSame(GiveawayMaintenanceTasks::LIFECYCLE_JOB_TYPE, $tasks[0]->jobType);
        self::assertSame('maintenance', $tasks[0]->queue->value());
        self::assertSame('{"limit":100}', $tasks[0]->payload);
        self::assertTrue($tasks[0]->schedule->isDue(
            new DateTimeImmutable('2026-09-19 12:20:00', new DateTimeZone('UTC')),
        ));
        self::assertTrue($tasks[0]->schedule->isDue(
            new DateTimeImmutable('2026-09-19 12:21:00', new DateTimeZone('UTC')),
        ));
    }
}
