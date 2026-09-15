<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Attachment;

use Forwext\Core\Forum\Attachment\AttachmentMaintenanceTasks;
use Forwext\Core\Scheduler\SchedulerRegistry;
use PHPUnit\Framework\TestCase;

final class AttachmentMaintenanceTaskTest extends TestCase
{
    public function testCleanupTaskIsBoundedMaintenanceQueueWork(): void
    {
        $registry = new SchedulerRegistry();
        AttachmentMaintenanceTasks::register($registry);

        self::assertCount(1, $registry->all());
        $task = $registry->all()[0];
        self::assertSame('forum.attachments.cleanup', $task->name);
        self::assertSame('maintenance', $task->queue->value());
        self::assertSame(AttachmentMaintenanceTasks::CLEANUP_JOB_TYPE, $task->jobType);
        self::assertSame('{"limit":250}', $task->payload);
        self::assertSame(3, $task->maxAttempts);
    }
}
