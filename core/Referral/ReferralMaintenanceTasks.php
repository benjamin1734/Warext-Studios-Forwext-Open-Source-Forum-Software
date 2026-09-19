<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class ReferralMaintenanceTasks
{
    public const QUALIFY_JOB_TYPE = 'referral.qualify';

    public static function register(SchedulerRegistry $registry): void
    {
        $registry->register(new ScheduledTask(
            'referral.qualify',
            CronExpression::parse('*/10 * * * *'),
            QueueName::fromString('maintenance'),
            self::QUALIFY_JOB_TYPE,
            '{"limit":100}',
            3,
        ));
    }
}
