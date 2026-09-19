<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class RewardMaintenanceTasks
{
    public const RETRY_JOB_TYPE='reward.retry';

    public static function register(SchedulerRegistry $registry):void
    {
        $registry->register(new ScheduledTask(
            'reward.retry',CronExpression::parse('*/15 * * * *'),
            QueueName::fromString('maintenance'),self::RETRY_JOB_TYPE,'{"limit":100}',3
        ));
    }
}
