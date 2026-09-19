<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class ThreadFreshnessMaintenanceTasks
{
    public const JOB_TYPE = 'thread.freshness.maintain';

    public static function register(SchedulerRegistry $registry): void
    {
        $registry->register(new ScheduledTask(
            'thread.freshness.maintain',
            CronExpression::parse('*/15 * * * *'),
            QueueName::fromString('maintenance'),
            self::JOB_TYPE,
            '{"limit":100}',
            3,
        ));
    }
}
