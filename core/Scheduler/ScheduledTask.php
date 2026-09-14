<?php

declare(strict_types=1);

namespace Forwext\Core\Scheduler;

use Forwext\Core\Queue\QueueName;
use InvalidArgumentException;

final readonly class ScheduledTask
{
    public function __construct(
        public string $name,
        public CronExpression $schedule,
        public QueueName $queue,
        public string $jobType,
        public string $payload = '',
        public int $maxAttempts = 3,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Scheduled task name is invalid.');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $jobType) !== 1) {
            throw new InvalidArgumentException('Scheduled task job type is invalid.');
        }
        if ($maxAttempts < 1 || $maxAttempts > 100) {
            throw new InvalidArgumentException('Scheduled task max attempts must be between 1 and 100.');
        }
    }
}
