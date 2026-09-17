<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Task;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabaseModerationTaskRepository implements ModerationTaskRepository
{
    public function __construct(private DatabaseConnection $database)
    {
    }

    public function save(ModerationTask $task): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_moderation_tasks` '
            . '(`task_id`, `title`, `description`, `priority`, `status`, `created_by_user_id`, `assigned_user_id`, '
            . '`due_at_utc`, `created_at_utc`, `updated_at_utc`) '
            . 'VALUES (:task_id, :title, :description, :priority, :status, :created_by, :assigned_user, '
            . ':due_at, :created_at, :updated_at)',
            [
                'task_id' => $task->id->value(),
                'title' => trim($task->title),
                'description' => $task->description,
                'priority' => $task->priority->value,
                'status' => $task->status->value,
                'created_by' => $task->createdByUserId->value(),
                'assigned_user' => $task->assignedUserId?->value(),
                'due_at' => $task->dueAt === null ? null : self::format($task->dueAt),
                'created_at' => self::format($task->createdAt),
                'updated_at' => self::format($task->updatedAt),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Moderation task was not persisted.');
        }
    }

    public function find(EntityId $taskId): ?ModerationTask
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `task_id`, `title`, `description`, `priority`, `status`, `created_by_user_id`, '
            . '`assigned_user_id`, `due_at_utc`, `created_at_utc`, `updated_at_utc` '
            . 'FROM `forwext_moderation_tasks` WHERE `task_id` = :task_id LIMIT 1',
            ['task_id' => $taskId->value()],
        ));
        return $row === null ? null : $this->hydrate($row);
    }

    public function updateStatus(EntityId $taskId, ModerationTaskStatus $status, DateTimeImmutable $updatedAt): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_moderation_tasks` SET `status` = :status, `updated_at_utc` = :updated_at '
            . 'WHERE `task_id` = :task_id',
            [
                'status' => $status->value,
                'updated_at' => self::format($updatedAt),
                'task_id' => $taskId->value(),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('Moderation task status update did not affect exactly one row.');
        }
    }

    public function countActive(): int
    {
        return (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM `forwext_moderation_tasks` WHERE `status` IN ('open', 'in_progress')",
        ));
    }

    public function latestActive(int $limit): array
    {
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Moderation task limit must be between 1 and 50.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `task_id`, `title`, `description`, `priority`, `status`, `created_by_user_id`, '
            . '`assigned_user_id`, `due_at_utc`, `created_at_utc`, `updated_at_utc` '
            . "FROM `forwext_moderation_tasks` WHERE `status` IN ('open', 'in_progress') "
            . "ORDER BY FIELD(`priority`, 'urgent', 'high', 'normal', 'low'), `updated_at_utc` DESC, `task_id` DESC "
            . 'LIMIT ' . $limit,
        ));

        return array_map($this->hydrate(...), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ModerationTask
    {
        return new ModerationTask(
            EntityId::fromString((string) $row['task_id']),
            (string) $row['title'],
            (string) $row['description'],
            ModerationTaskPriority::from((string) $row['priority']),
            ModerationTaskStatus::from((string) $row['status']),
            EntityId::fromString((string) $row['created_by_user_id']),
            ($row['assigned_user_id'] ?? null) === null ? null : EntityId::fromString((string) $row['assigned_user_id']),
            ($row['due_at_utc'] ?? null) === null ? null : self::parse((string) $row['due_at_utc']),
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored moderation task timestamp is invalid.');
        }
        return $time;
    }
}
