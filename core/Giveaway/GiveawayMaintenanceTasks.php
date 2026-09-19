<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class GiveawayMaintenanceTasks
{
    public const LIFECYCLE_JOB_TYPE = 'giveaway.lifecycle';

    public static function register(SchedulerRegistry $registry): void
    {
        $registry->register(new ScheduledTask(
            'giveaway.lifecycle',
            CronExpression::parse('* * * * *'),
            QueueName::fromString('maintenance'),
            self::LIFECYCLE_JOB_TYPE,
            '{"limit":100}',
            3,
        ));
    }
}
