<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Task;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\ModerationAuditAction;
use Forwext\Core\Forum\Moderation\ModerationAuditEvent;
use Forwext\Core\Forum\Moderation\ModerationAuditStore;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;

final readonly class ModerationTaskService
{
    public function __construct(
        private DatabaseConnection $database,
        private ModerationTaskRepository $tasks,
        private PermissionGate $gate,
        private ModerationAuditStore $audit,
    ) {
    }

    public function create(
        string $title,
        string $description,
        ModerationTaskPriority $priority,
        ?EntityId $assignedUserId = null,
        ?DateTimeImmutable $dueAt = null,
        ?ModerationRequestId $requestId = null,
    ): ModerationTask {
        $this->gate->require(PermissionKey::fromString('moderation.manage'));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $task = new ModerationTask(
            ModerationTask::generateId(),
            trim($title),
            $description,
            $priority,
            ModerationTaskStatus::Open,
            $this->gate->actorId(),
            $assignedUserId,
            $dueAt,
            $now,
            $now,
        );
        $requestId ??= ModerationRequestId::generate();

        $this->database->transaction(function () use ($task, $requestId, $now): void {
            $this->tasks->save($task);
            $this->audit->append(new ModerationAuditEvent(
                ModerationAuditEvent::generateId(),
                $this->gate->actorId(),
                ModerationAuditAction::WorkspaceTaskCreate,
                'moderation.task',
                $task->id->value(),
                null,
                ModerationReasonCode::fromString('workspace.task_create'),
                $requestId,
                [],
                [
                    'status' => $task->status->value,
                    'priority' => $task->priority->value,
                    'assigned_user_id' => $task->assignedUserId?->value(),
                ],
                $now,
            ));
        });

        return $task;
    }

    public function updateStatus(
        EntityId $taskId,
        ModerationTaskStatus $status,
        ?ModerationRequestId $requestId = null,
    ): ModerationTask {
        $this->gate->require(PermissionKey::fromString('moderation.manage'));
        $requestId ??= ModerationRequestId::generate();

        return $this->database->transaction(function () use ($taskId, $status, $requestId): ModerationTask {
            $current = $this->tasks->find($taskId);
            if ($current === null) {
                throw new ModerationTaskNotFoundException('Moderation task was not found.');
            }
            if ($current->status === $status) {
                return $current;
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $this->tasks->updateStatus($taskId, $status, $now);
            $updated = new ModerationTask(
                $current->id,
                $current->title,
                $current->description,
                $current->priority,
                $status,
                $current->createdByUserId,
                $current->assignedUserId,
                $current->dueAt,
                $current->createdAt,
                $now,
            );
            $this->audit->append(new ModerationAuditEvent(
                ModerationAuditEvent::generateId(),
                $this->gate->actorId(),
                ModerationAuditAction::WorkspaceTaskStatus,
                'moderation.task',
                $taskId->value(),
                null,
                ModerationReasonCode::fromString('workspace.task_status'),
                $requestId,
                ['status' => $current->status->value],
                ['status' => $status->value],
                $now,
            ));
            return $updated;
        });
    }
}
