<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class SearchIndexMaintenanceTasks
{
    public const DRAIN_JOB_TYPE = 'search.index.drain';

    public static function register(SchedulerRegistry $registry): void
    {
        $registry->register(new ScheduledTask(
            'search.index.drain',
            CronExpression::parse('* * * * *'),
            QueueName::fromString('maintenance'),
            self::DRAIN_JOB_TYPE,
            '{"limit":100}',
            3,
        ));
    }
}
