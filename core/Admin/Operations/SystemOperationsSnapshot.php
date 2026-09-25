<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Operations;

use Forwext\Core\Capability\CapabilityEntry;
use Forwext\Core\Health\HealthReport;
use Forwext\Core\Scheduler\ScheduledTask;

final readonly class SystemOperationsSnapshot
{
    /**
     * @param list<CapabilityEntry> $capabilities
     * @param list<SystemLogEntry> $logs
     * @param list<SystemFailedJob> $failedJobs
     * @param list<array{queue:string,pending:int,reserved:int,failed:int}> $queues
     * @param list<ScheduledTask> $scheduledTasks
     * @param list<SystemBackupEntry> $backups
     * @param array<string,bool> $permissions
     */
    public function __construct(
        public ?HealthReport $health,
        public array $capabilities,
        public array $logs,
        public array $failedJobs,
        public array $queues,
        public array $scheduledTasks,
        public array $backups,
        public ?SystemIntegrityReport $integrity,
        public ?bool $maintenanceEnabled,
        public bool $maintenanceEnvironmentOverride,
        public array $permissions,
    ) {
    }
}
