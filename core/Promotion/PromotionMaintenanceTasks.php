<?php

declare(strict_types=1);

namespace Forwext\Core\Promotion;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class PromotionMaintenanceTasks
{
    public const EVALUATE_JOB_TYPE='promotion.evaluate';

    public static function register(SchedulerRegistry $registry):void
    {
        $registry->register(new ScheduledTask(
            'promotion.evaluate',
            CronExpression::parse('23 * * * *'),
            QueueName::fromString('maintenance'),
            self::EVALUATE_JOB_TYPE,
            '{"limit":200}',
            3
        ));
    }
}
