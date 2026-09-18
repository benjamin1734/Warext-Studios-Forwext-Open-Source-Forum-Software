<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Moderation\Abuse;

use DateTimeImmutable;
use Forwext\Core\Moderation\Abuse\AbuseMaintenance;
use Forwext\Core\Moderation\Abuse\AbuseMaintenanceJobHandler;
use Forwext\Core\Moderation\Abuse\AbuseMaintenanceTasks;
use Forwext\Core\Scheduler\SchedulerRegistry;
use PHPUnit\Framework\TestCase;

final class AbuseMaintenanceTest extends TestCase
{
    public function testMaintenanceTaskUsesExistingDatabaseMaintenanceQueueWithoutDaemonSpecificDependency(): void
    {
        $registry = new SchedulerRegistry();
        AbuseMaintenanceTasks::register($registry);
        $tasks = $registry->all();

        self::assertCount(1, $tasks);
        self::assertSame('moderation.abuse.retention_cleanup', $tasks[0]->name);
        self::assertSame('maintenance', $tasks[0]->queue->value());
        self::assertSame(AbuseMaintenanceTasks::CLEANUP_JOB_TYPE, $tasks[0]->jobType);
    }

    public function testMaintenanceJobDeletesOnlyBoundedExpiredData(): void
    {
        $maintenance = new AbuseMemoryMaintenance();
        $handler = new AbuseMaintenanceJobHandler($maintenance);
        $now = new DateTimeImmutable('2026-09-18T12:00:00+00:00');

        $deleted = $handler->handle(
            '{"counter_retention_days":8,"event_retention_days":180,"counter_limit":1000,"event_limit":500}',
            $now,
        );

        self::assertSame(12, $deleted);
        self::assertSame('2026-09-10 12:00:00', $maintenance->counterBefore?->format('Y-m-d H:i:s'));
        self::assertSame(1000, $maintenance->counterLimit);
        self::assertSame('2026-03-22 12:00:00', $maintenance->eventBefore?->format('Y-m-d H:i:s'));
        self::assertSame(500, $maintenance->eventLimit);
    }
}

final class AbuseMemoryMaintenance implements AbuseMaintenance
{
    public ?DateTimeImmutable $counterBefore = null;
    public ?DateTimeImmutable $eventBefore = null;
    public int $counterLimit = 0;
    public int $eventLimit = 0;

    public function cleanupCounters(DateTimeImmutable $before, int $limit = 1000): int
    {
        $this->counterBefore = $before;
        $this->counterLimit = $limit;
        return 7;
    }

    public function cleanupResolvedEvents(DateTimeImmutable $before, int $limit = 500): int
    {
        $this->eventBefore = $before;
        $this->eventLimit = $limit;
        return 5;
    }
}
