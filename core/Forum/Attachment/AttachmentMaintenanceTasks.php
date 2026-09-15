<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use Forwext\Core\Queue\QueueName;
use Forwext\Core\Scheduler\CronExpression;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;

final class AttachmentMaintenanceTasks
{
    public const CLEANUP_JOB_TYPE = 'forum.attachment.cleanup';

    public static function register(SchedulerRegistry $registry): void
    {
        $registry->register(new ScheduledTask(
            'forum.attachments.cleanup',
            CronExpression::parse('17 * * * *'),
            QueueName::fromString('maintenance'),
            self::CLEANUP_JOB_TYPE,
            '{"limit":250}',
            3,
        ));
    }
}
