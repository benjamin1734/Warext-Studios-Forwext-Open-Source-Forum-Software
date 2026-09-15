<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\State;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Node\ForumNodeId;
use Forwext\Core\Forum\Thread\ThreadId;
use InvalidArgumentException;

final readonly class ContentDraft
{
    public function __construct(
        private EntityId $userId,
        private DraftTargetType $targetType,
        private EntityId $targetId,
        private ?string $titleSource,
        private string $bodySource,
        private int $revision,
        private DateTimeImmutable $updatedAt,
    ) {
        UserId::assert($this->userId);
        match ($this->targetType) {
            DraftTargetType::NewThread => ForumNodeId::assert($this->targetId),
            DraftTargetType::Reply => ThreadId::assert($this->targetId),
        };
        if ($this->targetType === DraftTargetType::Reply && $this->titleSource !== null) {
            throw new InvalidArgumentException('Reply drafts cannot contain a thread title.');
        }
        if ($this->titleSource !== null) {
            $title = trim($this->titleSource);
            if (strlen($title) > 200 || self::containsUnsafeControl($title)) {
                throw new InvalidArgumentException('Draft title must contain at most 200 safe UTF-8 bytes.');
            }
        }
        if (strlen($this->bodySource) > 100000 || self::containsUnsafeControl($this->bodySource)) {
            throw new InvalidArgumentException('Draft body must contain at most 100000 safe UTF-8 bytes.');
        }
        if ($this->revision < 1) {
            throw new InvalidArgumentException('Persisted draft revision must be positive.');
        }
    }

    public function userId(): EntityId { return $this->userId; }
    public function targetType(): DraftTargetType { return $this->targetType; }
    public function targetId(): EntityId { return $this->targetId; }
    public function titleSource(): ?string { return $this->titleSource === null ? null : trim($this->titleSource); }
    public function bodySource(): string { return $this->bodySource; }
    public function revision(): int { return $this->revision; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt->setTimezone(new DateTimeZone('UTC')); }

    private static function containsUnsafeControl(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
    }
}
