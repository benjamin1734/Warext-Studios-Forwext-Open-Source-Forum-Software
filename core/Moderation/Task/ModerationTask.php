<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Task;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class ModerationTask
{
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;
    public ?DateTimeImmutable $dueAt;

    public function __construct(
        public EntityId $id,
        public string $title,
        public string $description,
        public ModerationTaskPriority $priority,
        public ModerationTaskStatus $status,
        public EntityId $createdByUserId,
        public ?EntityId $assignedUserId,
        ?DateTimeImmutable $dueAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($this->createdByUserId);
        if ($this->assignedUserId !== null) {
            UserId::assert($this->assignedUserId);
        }
        if (trim($this->title) === '' || strlen($this->title) > 200) {
            throw new InvalidArgumentException('Moderation task title must contain 1-200 UTF-8 bytes.');
        }
        if (strlen($this->description) > 5000) {
            throw new InvalidArgumentException('Moderation task description cannot exceed 5000 UTF-8 bytes.');
        }
        $utc = new DateTimeZone('UTC');
        $this->dueAt = $dueAt?->setTimezone($utc);
        $this->createdAt = $createdAt->setTimezone($utc);
        $this->updatedAt = $updatedAt->setTimezone($utc);
    }

    public static function generateId(): EntityId
    {
        return EntityId::fromString(bin2hex(random_bytes(16)));
    }
}
