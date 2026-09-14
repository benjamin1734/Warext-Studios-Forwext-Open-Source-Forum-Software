<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Queue\JobId;
use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Queue\QueueName;
use Forwext\Core\Queue\QueueReservation;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerClaim;
use Forwext\Core\Scheduler\SchedulerClaimStore;
use Forwext\Core\Scheduler\SchedulerDispatcher;
use Forwext\Core\Scheduler\SchedulerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchedulerTest extends TestCase
{
    public function testCronExpressionSupportsRangesStepsAndWeekdaysInUtc(): void
    {
        $cron = CronExpression::parse('*/15 8-18 * * 1-5');

        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-09-14 09:30:00', new DateTimeZone('UTC'))));
        self::assertFalse($cron->isDue(new DateTimeImmutable('2026-09-14 09:31:00', new DateTimeZone('UTC'))));
        self::assertFalse($cron->isDue(new DateTimeImmutable('2026-09-13 09:30:00', new DateTimeZone('UTC'))));
    }

    public function testDayOfMonthAndDayOfWeekUseCronOrSemanticsWhenBothRestricted(): void
    {
        $cron = CronExpression::parse('0 0 1 * 1');

        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-09-01 00:00:00', new DateTimeZone('UTC'))));
        self::assertTrue($cron->isDue(new DateTimeImmutable('2026-09-07 00:00:00', new DateTimeZone('UTC'))));
        self::assertFalse($cron->isDue(new DateTimeImmutable('2026-09-08 00:00:00', new DateTimeZone('UTC'))));
    }

    public function testDispatcherClaimsOneMinuteOnlyOnce(): void
    {
        $registry = new SchedulerRegistry();
        $registry->register(new ScheduledTask(
            'maintenance.cleanup',
            CronExpression::parse('* * * * *'),
            QueueName::fromString('maintenance'),
            'maintenance.cleanup',
        ));
        $claims = new MemorySchedulerClaimStore();
        $queue = new MemoryQueueDriver();
        $dispatcher = new SchedulerDispatcher($registry, $claims, $queue);
        $time = new DateTimeImmutable('2026-09-14 20:45:42', new DateTimeZone('UTC'));

        self::assertCount(1, $dispatcher->dispatchDue($time));
        self::assertCount(0, $dispatcher->dispatchDue($time));
        self::assertSame(1, $queue->pushes);
    }

    public function testFailedEnqueueReleasesSchedulerClaimForRetry(): void
    {
        $registry = new SchedulerRegistry();
        $registry->register(new ScheduledTask(
            'search.rebuild',
            CronExpression::parse('* * * * *'),
            QueueName::fromString('search'),
            'search.rebuild',
        ));
        $claims = new MemorySchedulerClaimStore();
        $queue = new MemoryQueueDriver(failFirstPush: true);
        $dispatcher = new SchedulerDispatcher($registry, $claims, $queue);
        $time = new DateTimeImmutable('2026-09-14 20:46:00', new DateTimeZone('UTC'));

        try {
            $dispatcher->dispatchDue($time);
            self::fail('Expected first queue push to fail.');
        } catch (RuntimeException) {
            self::assertCount(1, $dispatcher->dispatchDue($time));
            self::assertSame(2, $queue->pushes);
        }
    }
}

final class MemorySchedulerClaimStore implements SchedulerClaimStore
{
    /** @var array<string, string> */
    private array $claims = [];

    public function claim(string $taskName, DateTimeImmutable $minuteBucket): ?SchedulerClaim
    {
        $minute = $minuteBucket->setTimezone(new DateTimeZone('UTC'))->format('YmdHi');
        $key = $taskName . ':' . $minute;
        if (isset($this->claims[$key])) {
            return null;
        }

        $claim = new SchedulerClaim($taskName, $minuteBucket, bin2hex(random_bytes(16)));
        $this->claims[$key] = $claim->token;
        return $claim;
    }

    public function release(SchedulerClaim $claim): void
    {
        $key = $claim->taskName . ':' . $claim->minuteBucket->setTimezone(new DateTimeZone('UTC'))->format('YmdHi');
        if (($this->claims[$key] ?? null) === $claim->token) {
            unset($this->claims[$key]);
        }
    }

    public function pruneBefore(DateTimeImmutable $cutoff, int $limit = 1000): int
    {
        return 0;
    }
}

final class MemoryQueueDriver implements QueueDriver
{
    public int $pushes = 0;

    public function __construct(private bool $failFirstPush = false)
    {
    }

    public function push(
        QueueName $queue,
        string $type,
        string $payload,
        int $maxAttempts = 3,
        ?DateTimeImmutable $availableAt = null,
    ): JobId {
        ++$this->pushes;
        if ($this->failFirstPush && $this->pushes === 1) {
            throw new RuntimeException('fixture queue failure');
        }
        return JobId::generate();
    }

    public function reserve(QueueName $queue, int $visibilityTimeoutSeconds = 60): ?QueueReservation
    {
        return null;
    }

    public function acknowledge(QueueReservation $reservation): void
    {
    }

    public function retry(QueueReservation $reservation, int $delaySeconds = 0): void
    {
    }

    public function fail(QueueReservation $reservation, string $failureCode): void
    {
    }
}
