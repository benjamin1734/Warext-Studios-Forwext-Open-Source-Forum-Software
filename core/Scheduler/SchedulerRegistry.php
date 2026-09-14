<?php

declare(strict_types=1);

namespace Forwext\Core\Scheduler;

use DateTimeImmutable;
use LogicException;

final class SchedulerRegistry
{
    /** @var array<string, ScheduledTask> */
    private array $tasks = [];

    public function register(ScheduledTask $task): void
    {
        if (isset($this->tasks[$task->name])) {
            throw new LogicException(sprintf('Scheduled task "%s" is already registered.', $task->name));
        }
        $this->tasks[$task->name] = $task;
    }

    /** @return list<ScheduledTask> */
    public function all(): array
    {
        return array_values($this->tasks);
    }

    /** @return list<ScheduledTask> */
    public function dueAt(DateTimeImmutable $time): array
    {
        return array_values(array_filter(
            $this->tasks,
            static fn (ScheduledTask $task): bool => $task->schedule->isDue($time),
        ));
    }
}
