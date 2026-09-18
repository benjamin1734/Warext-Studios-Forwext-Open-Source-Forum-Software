<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Abuse;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class AbuseMaintenanceTasks
{
    public const CLEANUP_JOB_TYPE = 'moderation.abuse.retention_cleanup';

    public static function register(SchedulerRegistry $registry): void
    {
        $registry->register(new ScheduledTask(
            'moderation.abuse.retention_cleanup',
            CronExpression::parse('37 3 * * *'),
            QueueName::fromString('maintenance'),
            self::CLEANUP_JOB_TYPE,
            '{"counter_retention_days":8,"event_retention_days":180,"counter_limit":1000,"event_limit":500}',
            3,
        ));
    }
}
