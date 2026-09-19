<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class TrophyMaintenanceTasks
{
    public const EVALUATE_JOB_TYPE='trophy.evaluate';

    public static function register(SchedulerRegistry $registry):void
    {
        $registry->register(new ScheduledTask(
            'trophy.evaluate',
            CronExpression::parse('17 * * * *'),
            QueueName::fromString('maintenance'),
            self::EVALUATE_JOB_TYPE,
            '{"limit":200}',
            3,
        ));
    }
}
