<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class AnalyticsMaintenanceTasks
{
    public const RETENTION_JOB_TYPE='analytics.retention.prune';

    public static function register(SchedulerRegistry $registry):void
    {
        $registry->register(new ScheduledTask(
            'analytics.retention.prune',
            CronExpression::parse('37 3 * * *'),
            QueueName::fromString('maintenance'),
            self::RETENTION_JOB_TYPE,
            '{"limit_per_event":1000}',
            3,
        ));
    }
}
