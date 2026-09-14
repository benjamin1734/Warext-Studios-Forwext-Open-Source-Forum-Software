<?php

declare(strict_types=1);

namespace Forwext\Core\Scheduler;

use DateTimeImmutable;
use Forwext\Core\Queue\JobId;
use Forwext\Core\Queue\QueueDriver;
use Throwable;

final readonly class SchedulerDispatcher
{
    public function __construct(
        private SchedulerRegistry $registry,
        private SchedulerClaimStore $claims,
        private QueueDriver $queue,
    ) {
    }

    /** @return list<JobId> */
    public function dispatchDue(DateTimeImmutable $time): array
    {
        $dispatched = [];

        foreach ($this->registry->dueAt($time) as $task) {
            $claim = $this->claims->claim($task->name, $time);
            if ($claim === null) {
                continue;
            }

            try {
                $dispatched[] = $this->queue->push(
                    $task->queue,
                    $task->jobType,
                    $task->payload,
                    $task->maxAttempts,
                    $time,
                );
            } catch (Throwable $failure) {
                $this->claims->release($claim);
                throw $failure;
            }
        }

        return $dispatched;
    }
}
