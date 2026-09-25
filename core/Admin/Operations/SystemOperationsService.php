<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Operations;

use DateTimeImmutable;
use DateTimeZone;
use DirectoryIterator;
use Forwext\Core\Admin\Integration\GeneratedConfigStore;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Capability\CapabilityResolver;
use Forwext\Core\Config\ConfigRepository;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Health\HealthService;
use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Core\Queue\JobId;
use Forwext\Core\Queue\QueueDriver;
use Forwext\Core\Scheduler\DatabaseSchedulerClaimStore;
use Forwext\Core\Scheduler\ScheduledTask;
use Forwext\Core\Scheduler\SchedulerRegistry;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class SystemOperationsService
{
    public const HEALTH_PERMISSION = 'system.health.view';
    public const LOG_PERMISSION = 'system.logs.view';
    public const JOB_PERMISSION = 'system.jobs.manage';
    public const BACKUP_PERMISSION = 'system.backup.manage';
    public const MAINTENANCE_PERMISSION = 'system.maintenance.manage';
    public const REPAIR_PERMISSION = 'system.repair.manage';

    private const ACP_PERMISSION = 'acp.access';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private ConfigRepository $config,
        private GeneratedConfigStore $generated,
        private HealthService $health,
        private CapabilityResolver $capabilities,
        private SystemLogReader $logs,
        private SystemBackupService $backups,
        private SchedulerRegistry $scheduler,
        private QueueDriver $queue,
        private DatabaseSchedulerClaimStore $schedulerClaims,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
        private string $cacheDirectory,
    ) {
        if ($this->cacheDirectory === '' || str_contains($this->cacheDirectory, "\0")) {
            throw new InvalidArgumentException('System cache directory is invalid.');
        }
    }

    public function snapshot(EntityId $actor, int $logLimit = 100): SystemOperationsSnapshot
    {
        $this->requireAcp($actor);
        if ($logLimit < 1 || $logLimit > 250) {
            throw new InvalidArgumentException('System log limit is invalid.');
        }

        $permissions = [];
        foreach (self::permissions() as $permission) {
            $permissions[$permission] = $this->allows($actor, $permission);
        }

        $canHealth = $permissions[self::HEALTH_PERMISSION];
        $canLogs = $permissions[self::LOG_PERMISSION];
        $canJobs = $permissions[self::JOB_PERMISSION];
        $canBackups = $permissions[self::BACKUP_PERMISSION];
        $canMaintenance = $permissions[self::MAINTENANCE_PERMISSION];

        return new SystemOperationsSnapshot(
            $canHealth ? $this->health->report() : null,
            $canHealth ? $this->capabilities->resolve()->all() : [],
            $canLogs ? $this->logs->tail($logLimit) : [],
            $canJobs ? $this->failedJobs(100) : [],
            $canJobs ? $this->queueSummary() : [],
            $canJobs ? $this->scheduler->all() : [],
            $canBackups ? $this->backups->list() : [],
            $canHealth ? $this->integrity() : null,
            $canMaintenance ? $this->config->requireBool('app.maintenance') : null,
            $canMaintenance && getenv('FORWEXT_CONFIG__APP__MAINTENANCE') !== false,
            $permissions,
        );
    }

    public function setMaintenance(
        EntityId $actor,
        bool $enabled,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->require($actor, self::MAINTENANCE_PERMISSION);
        if (getenv('FORWEXT_CONFIG__APP__MAINTENANCE') !== false) {
            throw new RuntimeException('Maintenance mode is controlled by an environment override.');
        }

        $before = $this->config->requireBool('app.maintenance');
        $event = $this->event(
            $actor,
            'system.maintenance.update',
            'system.maintenance',
            'site',
            ['enabled'=>$before],
            ['enabled'=>$enabled],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, fn (): mixed => $this->generated->set('app.maintenance', $enabled));
    }

    public function runScheduledTask(
        EntityId $actor,
        string $taskName,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): JobId {
        $this->require($actor, self::JOB_PERMISSION);
        $task = $this->scheduledTask($taskName);
        $event = $this->event(
            $actor,
            'system.scheduler.run',
            'system.task',
            $task->name,
            [],
            ['queue'=>$task->queue->value(),'job_type'=>$task->jobType],
            $requestId,
            $at,
        );

        return $this->audit->mutate(
            $event,
            fn (): JobId => $this->queue->push(
                $task->queue,
                $task->jobType,
                $task->payload,
                $task->maxAttempts,
                $at->setTimezone(new DateTimeZone('UTC')),
            ),
        );
    }

    public function retryFailedJob(
        EntityId $actor,
        string $jobId,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->require($actor, self::JOB_PERMISSION);
        self::assertJobId($jobId);
        $metadata = $this->failedJobMetadata($jobId);
        if ($metadata === null) {
            throw new InvalidArgumentException('Failed job was not found.');
        }

        $event = $this->event(
            $actor,
            'system.failed_job.retry',
            'system.job',
            $jobId,
            $metadata,
            ['state'=>'queued','attempts'=>0],
            $requestId,
            $at,
        );

        $this->audit->mutate($event, function () use ($jobId, $at): void {
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT job_id,queue_name,job_type,payload,max_attempts FROM forwext_failed_jobs '
                . 'WHERE job_id=:job_id LIMIT 1 FOR UPDATE',
                ['job_id'=>$jobId],
                true,
            ));
            if ($row === null) {
                throw new InvalidArgumentException('Failed job was not found.');
            }
            foreach (['queue_name','job_type','payload'] as $key) {
                if (!is_string($row[$key] ?? null)) {
                    throw new RuntimeException('Failed job row is malformed.');
                }
            }
            $maxAttempts = (int) ($row['max_attempts'] ?? 0);
            if ($maxAttempts < 1 || $maxAttempts > 100) {
                throw new RuntimeException('Failed job max-attempt metadata is invalid.');
            }
            $utc = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $inserted = $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_jobs '
                . '(job_id,queue_name,job_type,payload,attempts,max_attempts,available_at_utc,reserved_until_utc,reservation_token,created_at_utc) '
                . 'VALUES (:job_id,:queue_name,:job_type,:payload,0,:max_attempts,:available_at,NULL,NULL,:created_at)',
                [
                    'job_id'=>$jobId,
                    'queue_name'=>$row['queue_name'],
                    'job_type'=>$row['job_type'],
                    'payload'=>$row['payload'],
                    'max_attempts'=>$maxAttempts,
                    'available_at'=>$utc,
                    'created_at'=>$utc,
                ],
                true,
            ));
            if ($inserted !== 1) {
                throw new RuntimeException('Failed job could not be returned to the queue.');
            }
            $deleted = $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_failed_jobs WHERE job_id=:job_id',
                ['job_id'=>$jobId],
                true,
            ));
            if ($deleted !== 1) {
                throw new RuntimeException('Failed job retry cleanup did not affect exactly one row.');
            }
        });
    }

    public function deleteFailedJob(
        EntityId $actor,
        string $jobId,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->require($actor, self::JOB_PERMISSION);
        self::assertJobId($jobId);
        $metadata = $this->failedJobMetadata($jobId);
        if ($metadata === null) {
            throw new InvalidArgumentException('Failed job was not found.');
        }
        $event = $this->event(
            $actor,
            'system.failed_job.delete',
            'system.job',
            $jobId,
            $metadata,
            ['deleted'=>true],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use ($jobId): void {
            $affected = $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_failed_jobs WHERE job_id=:job_id',
                ['job_id'=>$jobId],
                true,
            ));
            if ($affected !== 1) {
                throw new InvalidArgumentException('Failed job was not found.');
            }
        });
    }

    public function pruneSchedulerClaims(
        EntityId $actor,
        int $olderThanDays,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): int {
        $this->require($actor, self::REPAIR_PERMISSION);
        if ($olderThanDays < 1 || $olderThanDays > 3650) {
            throw new InvalidArgumentException('Scheduler retention days are invalid.');
        }
        $cutoff = $at->setTimezone(new DateTimeZone('UTC'))->modify('-' . $olderThanDays . ' days');
        $event = $this->event(
            $actor,
            'system.scheduler.prune',
            'system.repair',
            'scheduler-claims',
            [],
            ['older_than_days'=>$olderThanDays],
            $requestId,
            $at,
        );

        return $this->audit->mutate(
            $event,
            fn (): int => $this->schedulerClaims->pruneBefore($cutoff, 10000),
        );
    }

    public function createBackup(
        EntityId $actor,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): SystemBackupEntry {
        $this->require($actor, self::BACKUP_PERMISSION);
        $entry = $this->backups->create($at);
        try {
            $this->audit->append($this->event(
                $actor,
                'system.backup.create',
                'system.backup',
                $entry->name,
                [],
                ['size_bytes'=>$entry->sizeBytes,'tables'=>$entry->tableCount,'rows'=>$entry->rowCount],
                $requestId,
                $at,
            ));
        } catch (Throwable $failure) {
            try {
                $this->backups->delete($entry->name);
            } catch (Throwable) {
            }
            throw $failure;
        }

        return $entry;
    }

    public function verifyBackup(EntityId $actor, string $name): SystemBackupEntry
    {
        $this->require($actor, self::BACKUP_PERMISSION);

        return $this->backups->verify($name);
    }

    public function deleteBackup(
        EntityId $actor,
        string $name,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->require($actor, self::BACKUP_PERMISSION);
        $entry = $this->backups->verify($name);
        $event = $this->event(
            $actor,
            'system.backup.delete',
            'system.backup',
            $entry->name,
            ['size_bytes'=>$entry->sizeBytes,'sha256'=>$entry->sha256,'verified'=>$entry->verified],
            ['deleted'=>true],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, fn (): mixed => $this->backups->delete($entry->name));
    }

    public function clearCache(
        EntityId $actor,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): int {
        $this->require($actor, self::REPAIR_PERMISSION);
        $event = $this->event(
            $actor,
            'system.cache.clear',
            'system.repair',
            'cache',
            [],
            ['requested'=>true],
            $requestId,
            $at,
        );

        return $this->audit->mutate($event, fn (): int => $this->clearDirectory($this->cacheDirectory));
    }

    /** @return list<string> */
    public static function permissions(): array
    {
        return [
            self::HEALTH_PERMISSION,
            self::LOG_PERMISSION,
            self::JOB_PERMISSION,
            self::BACKUP_PERMISSION,
            self::MAINTENANCE_PERMISSION,
            self::REPAIR_PERMISSION,
        ];
    }

    private function integrity(): SystemIntegrityReport
    {
        $registered = [];
        foreach (CoreMigrationRegistry::all() as $migration) {
            $registered[$migration->id()->value()] = true;
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            "SELECT migration_id,status FROM forwext_migration_history "
            . "WHERE scope='core' AND owner_name='core' ORDER BY migration_id ASC"
        ));
        $applied = [];
        $failed = [];
        $running = [];
        $unknown = [];
        foreach ($rows as $row) {
            $id = $row['migration_id'] ?? null;
            $status = $row['status'] ?? null;
            if (!is_string($id) || !is_string($status)) {
                continue;
            }
            if (!isset($registered[$id])) {
                $unknown[] = $id;
            }
            if ($status === 'applied') {
                $applied[$id] = true;
            } elseif ($status === 'failed') {
                $failed[] = $id;
            } elseif ($status === 'running') {
                $running[] = $id;
            }
        }

        $missing = array_values(array_diff(array_keys($registered), array_keys($applied)));
        sort($missing, SORT_STRING);
        sort($failed, SORT_STRING);
        sort($running, SORT_STRING);
        sort($unknown, SORT_STRING);

        $nonInnoDb = (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' "
            . "AND TABLE_NAME LIKE 'forwext\\_%' ESCAPE '\\\\' AND ENGINE <> 'InnoDB'"
        ));

        return new SystemIntegrityReport(
            $missing === [] && $failed === [] && $running === [] && $unknown === [] && $nonInnoDb === 0,
            count($registered),
            count($applied),
            $missing,
            $failed,
            $running,
            $unknown,
            $nonInnoDb,
        );
    }

    /** @return list<SystemFailedJob> */
    private function failedJobs(int $limit): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT job_id,queue_name,job_type,attempts,max_attempts,failure_code,failed_at_utc '
            . 'FROM forwext_failed_jobs ORDER BY failed_at_utc DESC,job_id DESC LIMIT ' . $limit
        ));
        $result = [];
        foreach ($rows as $row) {
            foreach (['job_id','queue_name','job_type','failure_code','failed_at_utc'] as $key) {
                if (!is_string($row[$key] ?? null)) {
                    continue 2;
                }
            }
            $result[] = new SystemFailedJob(
                $row['job_id'],
                $row['queue_name'],
                $row['job_type'],
                max(0, (int) ($row['attempts'] ?? 0)),
                max(1, (int) ($row['max_attempts'] ?? 1)),
                $row['failure_code'],
                self::parseDatabaseTime($row['failed_at_utc']),
            );
        }

        return $result;
    }

    /** @return list<array{queue:string,pending:int,reserved:int,failed:int}> */
    private function queueSummary(): array
    {
        $queues = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT queue_name,COUNT(*) AS pending,'
            . 'SUM(CASE WHEN reserved_until_utc IS NOT NULL THEN 1 ELSE 0 END) AS reserved '
            . 'FROM forwext_jobs GROUP BY queue_name ORDER BY queue_name ASC'
        )) as $row) {
            $queue = $row['queue_name'] ?? null;
            if (!is_string($queue)) {
                continue;
            }
            $queues[$queue] = [
                'queue'=>$queue,
                'pending'=>max(0, (int) ($row['pending'] ?? 0)),
                'reserved'=>max(0, (int) ($row['reserved'] ?? 0)),
                'failed'=>0,
            ];
        }

        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT queue_name,COUNT(*) AS failed FROM forwext_failed_jobs GROUP BY queue_name ORDER BY queue_name ASC'
        )) as $row) {
            $queue = $row['queue_name'] ?? null;
            if (!is_string($queue)) {
                continue;
            }
            $queues[$queue] ??= ['queue'=>$queue,'pending'=>0,'reserved'=>0,'failed'=>0];
            $queues[$queue]['failed'] = max(0, (int) ($row['failed'] ?? 0));
        }

        ksort($queues, SORT_STRING);
        return array_values($queues);
    }

    /** @return array<string,mixed>|null */
    private function failedJobMetadata(string $jobId): ?array
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT queue_name,job_type,attempts,max_attempts,failure_code,failed_at_utc '
            . 'FROM forwext_failed_jobs WHERE job_id=:job_id LIMIT 1',
            ['job_id'=>$jobId],
        ));
        if ($row === null) {
            return null;
        }

        return [
            'queue'=>(string) ($row['queue_name'] ?? ''),
            'job_type'=>(string) ($row['job_type'] ?? ''),
            'attempts'=>(int) ($row['attempts'] ?? 0),
            'max_attempts'=>(int) ($row['max_attempts'] ?? 0),
            'failure_code'=>(string) ($row['failure_code'] ?? ''),
            'failed_at'=>(string) ($row['failed_at_utc'] ?? ''),
        ];
    }

    private function scheduledTask(string $taskName): ScheduledTask
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{0,190}$/D', $taskName) !== 1) {
            throw new InvalidArgumentException('Scheduled task name is invalid.');
        }
        foreach ($this->scheduler->all() as $task) {
            if ($task->name === $taskName) {
                return $task;
            }
        }

        throw new InvalidArgumentException('Scheduled task was not found.');
    }

    private function requireAcp(EntityId $actor): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString(self::ACP_PERMISSION));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    private function require(EntityId $actor, string $permission): void
    {
        $this->requireAcp($actor);
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    private function allows(EntityId $actor, string $permission): bool
    {
        return $this->authorizer->allows($actor, PermissionKey::fromString($permission));
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function event(
        EntityId $actor,
        string $action,
        string $targetType,
        string $targetId,
        array $before,
        array $after,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): AuditEvent {
        return new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($action),
            $targetType,
            $targetId,
            null,
            $action,
            $requestId,
            $before,
            $after,
            $at->setTimezone(new DateTimeZone('UTC')),
        );
    }

    private function clearDirectory(string $directory): int
    {
        if (!is_dir($directory)) {
            return 0;
        }
        if (is_link($directory)) {
            throw new RuntimeException('Cache directory may not be a symbolic link.');
        }

        $removed = 0;
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            $path = $entry->getPathname();
            if ($entry->isLink() || $entry->isFile()) {
                if (!@unlink($path)) {
                    throw new RuntimeException('Cache entry could not be removed.');
                }
                ++$removed;
                continue;
            }
            if ($entry->isDir()) {
                $removed += $this->clearDirectory($path);
                if (!@rmdir($path)) {
                    throw new RuntimeException('Cache directory could not be removed.');
                }
                ++$removed;
            }
        }

        return $removed;
    }

    private static function assertJobId(string $jobId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $jobId) !== 1) {
            throw new InvalidArgumentException('Job id is invalid.');
        }
    }

    private static function parseDatabaseTime(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));
        }
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored system timestamp is invalid.');
        }

        return $parsed;
    }
}
