<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class DatabaseContentManagerOperationRepository implements ContentManagerOperationRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function create(
        EntityId $operationId,
        EntityId $actorUserId,
        ContentManagerFilter $filter,
        ContentManagerAction $action,
        ?EntityId $targetForumNodeId,
        array $targets,
        DateTimeImmutable $at,
    ): void {
        if ($targets === [] || count($targets) > 5000) {
            throw new InvalidArgumentException('Content manager operation must contain 1-5000 frozen targets.');
        }
        foreach ($targets as $target) {
            if (!$target instanceof ContentManagerTarget) {
                throw new InvalidArgumentException('Content manager operation targets are invalid.');
            }
        }

        $this->database->transaction(function () use (
            $operationId,
            $actorUserId,
            $filter,
            $action,
            $targetForumNodeId,
            $targets,
            $at,
        ): void {
            $timestamp = self::format($at);
            $affected = $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_content_manager_operations '
                . '(operation_id,actor_user_id,target_user_id,action,content_type,filter_forum_node_id,'
                . 'filter_moderation_state,filter_deleted,filter_query,target_forum_node_id,status,total_count,'
                . 'processed_count,succeeded_count,skipped_count,failed_count,created_at_utc,updated_at_utc) '
                . 'VALUES (:operation_id,:actor_user_id,:target_user_id,:action,:content_type,:filter_forum_node_id,'
                . ':filter_moderation_state,:filter_deleted,:filter_query,:target_forum_node_id,\'queued\',:total_count,'
                . '0,0,0,0,:created_at_utc,:updated_at_utc)',
                [
                    'operation_id'=>$operationId->value(),
                    'actor_user_id'=>$actorUserId->value(),
                    'target_user_id'=>$filter->targetUserId->value(),
                    'action'=>$action->value,
                    'content_type'=>$filter->contentType?->value,
                    'filter_forum_node_id'=>$filter->forumNodeId?->value(),
                    'filter_moderation_state'=>$filter->moderationState,
                    'filter_deleted'=>$filter->deleted === null ? null : ($filter->deleted ? 1 : 0),
                    'filter_query'=>$filter->normalizedQuery(),
                    'target_forum_node_id'=>$targetForumNodeId?->value(),
                    'total_count'=>count($targets),
                    'created_at_utc'=>$timestamp,
                    'updated_at_utc'=>$timestamp,
                ],
                requiresTransaction:true,
            ));
            if ($affected !== 1) {
                throw new ContentManagerOperationException('Content manager operation could not be created.');
            }

            foreach ($targets as $target) {
                $affected = $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_content_manager_operation_items '
                    . '(operation_id,content_type,content_id,forum_node_id,status,created_at_utc,updated_at_utc) '
                    . 'VALUES (:operation_id,:content_type,:content_id,:forum_node_id,\'pending\',:created_at_utc,:updated_at_utc)',
                    [
                        'operation_id'=>$operationId->value(),
                        'content_type'=>$target->type->value,
                        'content_id'=>$target->id->value(),
                        'forum_node_id'=>$target->forumNodeId->value(),
                        'created_at_utc'=>$timestamp,
                        'updated_at_utc'=>$timestamp,
                    ],
                    requiresTransaction:true,
                ));
                if ($affected !== 1) {
                    throw new ContentManagerOperationException('Content manager operation target could not be frozen.');
                }
            }
        });
    }

    public function find(EntityId $operationId): ?ContentManagerOperation
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_content_manager_operations WHERE operation_id=:operation_id LIMIT 1',
            ['operation_id'=>$operationId->value()],
        ));
        return $row === null ? null : $this->operation($row);
    }

    public function recentForActor(EntityId $actorUserId, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Content manager operation history limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_content_manager_operations WHERE actor_user_id=:actor_user_id '
            . 'ORDER BY created_at_utc DESC,operation_id DESC LIMIT ' . $limit,
            ['actor_user_id'=>$actorUserId->value()],
        ));
        return array_map(fn (array $row): ContentManagerOperation => $this->operation($row), $rows);
    }

    public function items(EntityId $operationId, int $limit = 200): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Content manager operation item limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT operation_id,content_type,content_id,forum_node_id,status,failure_code '
            . 'FROM forwext_content_manager_operation_items WHERE operation_id=:operation_id '
            . 'ORDER BY content_type,content_id LIMIT ' . $limit,
            ['operation_id'=>$operationId->value()],
        ));
        return array_map(fn (array $row): ContentManagerOperationItem => $this->item($row), $rows);
    }

    public function claim(EntityId $operationId, int $limit, DateTimeImmutable $at): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Content manager processing batch must be 1-100.');
        }

        return $this->database->transaction(function () use ($operationId, $limit, $at): array {
            $now = self::format($at);
            $stale = self::format($at->sub(new DateInterval('PT10M')));
            $this->database->execute(new CompiledQuery(
                'UPDATE forwext_content_manager_operation_items SET status=\'pending\',processing_started_at_utc=NULL,'
                . 'updated_at_utc=:updated_at_utc WHERE operation_id=:operation_id AND status=\'processing\' '
                . 'AND processing_started_at_utc<:stale_at_utc',
                [
                    'updated_at_utc'=>$now,
                    'operation_id'=>$operationId->value(),
                    'stale_at_utc'=>$stale,
                ],
                requiresTransaction:true,
            ));

            $rows = $this->database->fetchAll(new CompiledQuery(
                'SELECT operation_id,content_type,content_id,forum_node_id,status,failure_code '
                . 'FROM forwext_content_manager_operation_items '
                . 'WHERE operation_id=:operation_id AND status=\'pending\' '
                . 'ORDER BY content_type,content_id LIMIT ' . $limit . ' FOR UPDATE SKIP LOCKED',
                ['operation_id'=>$operationId->value()],
                requiresTransaction:true,
            ));

            foreach ($rows as $row) {
                $this->database->execute(new CompiledQuery(
                    'UPDATE forwext_content_manager_operation_items '
                    . 'SET status=\'processing\',processing_started_at_utc=:processing_started_at_utc,updated_at_utc=:updated_at_utc '
                    . 'WHERE operation_id=:operation_id AND content_type=:content_type AND content_id=:content_id '
                    . 'AND status=\'pending\'',
                    [
                        'processing_started_at_utc'=>$now,
                        'updated_at_utc'=>$now,
                        'operation_id'=>$operationId->value(),
                        'content_type'=>(string) $row['content_type'],
                        'content_id'=>(string) $row['content_id'],
                    ],
                    requiresTransaction:true,
                ));
            }

            if ($rows !== []) {
                $this->database->execute(new CompiledQuery(
                    'UPDATE forwext_content_manager_operations SET status=\'running\','
                    . 'started_at_utc=COALESCE(started_at_utc,:started_at_utc),updated_at_utc=:updated_at_utc '
                    . 'WHERE operation_id=:operation_id AND status IN (\'queued\',\'running\')',
                    [
                        'started_at_utc'=>$now,
                        'updated_at_utc'=>$now,
                        'operation_id'=>$operationId->value(),
                    ],
                    requiresTransaction:true,
                ));
            }

            return array_map(
                fn (array $row): ContentManagerOperationItem => new ContentManagerOperationItem(
                    EntityId::fromString((string) $row['operation_id']),
                    ContentManagerContentType::from((string) $row['content_type']),
                    EntityId::fromString((string) $row['content_id']),
                    EntityId::fromString((string) $row['forum_node_id']),
                    ContentManagerItemStatus::Processing,
                    null,
                ),
                $rows,
            );
        });
    }

    public function completeItem(
        EntityId $operationId,
        ContentManagerContentType $type,
        EntityId $contentId,
        ContentManagerItemStatus $status,
        ?string $failureCode,
        DateTimeImmutable $at,
    ): void {
        if (!$status->terminal()) {
            throw new InvalidArgumentException('Content manager completed item requires a terminal status.');
        }
        if ($failureCode !== null && preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $failureCode) !== 1) {
            throw new InvalidArgumentException('Content manager failure code is invalid.');
        }

        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE forwext_content_manager_operation_items SET status=:status,failure_code=:failure_code,'
            . 'processing_started_at_utc=NULL,updated_at_utc=:updated_at_utc '
            . 'WHERE operation_id=:operation_id AND content_type=:content_type AND content_id=:content_id '
            . 'AND status=\'processing\'',
            [
                'status'=>$status->value,
                'failure_code'=>$failureCode,
                'updated_at_utc'=>self::format($at),
                'operation_id'=>$operationId->value(),
                'content_type'=>$type->value,
                'content_id'=>$contentId->value(),
            ],
        ));
        if ($affected !== 1) {
            throw new ContentManagerOperationException('Content manager item completion lost processing ownership.');
        }
    }

    public function refreshProgress(EntityId $operationId, DateTimeImmutable $at): ContentManagerOperation
    {
        return $this->database->transaction(function () use ($operationId, $at): ContentManagerOperation {
            $counts = $this->database->fetchOne(new CompiledQuery(
                'SELECT COUNT(*) AS total_count,'
                . 'SUM(status IN (\'succeeded\',\'skipped\',\'failed\')) AS processed_count,'
                . 'SUM(status=\'succeeded\') AS succeeded_count,'
                . 'SUM(status=\'skipped\') AS skipped_count,'
                . 'SUM(status=\'failed\') AS failed_count,'
                . 'SUM(status IN (\'pending\',\'processing\')) AS remaining_count '
                . 'FROM forwext_content_manager_operation_items WHERE operation_id=:operation_id',
                ['operation_id'=>$operationId->value()],
                requiresTransaction:true,
            ));
            if ($counts === null) {
                throw new ContentManagerOperationException('Content manager operation progress is unavailable.');
            }

            $total = (int) ($counts['total_count'] ?? 0);
            $processed = (int) ($counts['processed_count'] ?? 0);
            $succeeded = (int) ($counts['succeeded_count'] ?? 0);
            $skipped = (int) ($counts['skipped_count'] ?? 0);
            $failed = (int) ($counts['failed_count'] ?? 0);
            $remaining = (int) ($counts['remaining_count'] ?? 0);
            $status = $remaining > 0
                ? ContentManagerOperationStatus::Running
                : ($failed === $total
                    ? ContentManagerOperationStatus::Failed
                    : ($failed > 0 ? ContentManagerOperationStatus::Partial : ContentManagerOperationStatus::Completed));
            $completedAt = $remaining === 0 ? self::format($at) : null;

            $this->database->execute(new CompiledQuery(
                'UPDATE forwext_content_manager_operations SET status=:status,processed_count=:processed_count,'
                . 'succeeded_count=:succeeded_count,skipped_count=:skipped_count,failed_count=:failed_count,'
                . 'completed_at_utc=:completed_at_utc,updated_at_utc=:updated_at_utc '
                . 'WHERE operation_id=:operation_id',
                [
                    'status'=>$status->value,
                    'processed_count'=>$processed,
                    'succeeded_count'=>$succeeded,
                    'skipped_count'=>$skipped,
                    'failed_count'=>$failed,
                    'completed_at_utc'=>$completedAt,
                    'updated_at_utc'=>self::format($at),
                    'operation_id'=>$operationId->value(),
                ],
                requiresTransaction:true,
            ));

            return $this->find($operationId)
                ?? throw new ContentManagerOperationException('Content manager operation disappeared during progress update.');
        });
    }

    /** @param array<string,mixed> $row */
    private function operation(array $row): ContentManagerOperation
    {
        foreach (['operation_id','target_user_id','action','status','created_at_utc'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new ContentManagerOperationException('Stored content manager operation is malformed.');
            }
        }
        return new ContentManagerOperation(
            EntityId::fromString((string) $row['operation_id']),
            is_string($row['actor_user_id'] ?? null) && $row['actor_user_id'] !== ''
                ? EntityId::fromString((string) $row['actor_user_id'])
                : null,
            EntityId::fromString((string) $row['target_user_id']),
            ContentManagerAction::from((string) $row['action']),
            is_string($row['content_type'] ?? null) && $row['content_type'] !== ''
                ? ContentManagerContentType::from((string) $row['content_type'])
                : null,
            is_string($row['target_forum_node_id'] ?? null) && $row['target_forum_node_id'] !== ''
                ? EntityId::fromString((string) $row['target_forum_node_id'])
                : null,
            ContentManagerOperationStatus::from((string) $row['status']),
            (int) ($row['total_count'] ?? 0),
            (int) ($row['processed_count'] ?? 0),
            (int) ($row['succeeded_count'] ?? 0),
            (int) ($row['skipped_count'] ?? 0),
            (int) ($row['failed_count'] ?? 0),
            self::date((string) $row['created_at_utc']),
            is_string($row['started_at_utc'] ?? null) ? self::date((string) $row['started_at_utc']) : null,
            is_string($row['completed_at_utc'] ?? null) ? self::date((string) $row['completed_at_utc']) : null,
        );
    }

    /** @param array<string,mixed> $row */
    private function item(array $row): ContentManagerOperationItem
    {
        foreach (['operation_id','content_type','content_id','forum_node_id','status'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new ContentManagerOperationException('Stored content manager operation item is malformed.');
            }
        }
        return new ContentManagerOperationItem(
            EntityId::fromString((string) $row['operation_id']),
            ContentManagerContentType::from((string) $row['content_type']),
            EntityId::fromString((string) $row['content_id']),
            EntityId::fromString((string) $row['forum_node_id']),
            ContentManagerItemStatus::from((string) $row['status']),
            is_string($row['failure_code'] ?? null) ? (string) $row['failure_code'] : null,
        );
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function date(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $date, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new ContentManagerOperationException('Stored content manager operation timestamp is invalid.');
        }
        return $parsed;
    }
}
