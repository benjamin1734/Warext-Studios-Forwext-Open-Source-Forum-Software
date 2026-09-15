<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Post;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\Entity;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Thread\ThreadId;
use InvalidArgumentException;

final class Post implements Entity
{
    /** @var list<PostHistoryEntry> */
    private array $pendingHistory = [];

    private function __construct(
        private readonly EntityId $postId,
        private readonly EntityId $threadId,
        private readonly ?EntityId $authorUserId,
        private readonly int $position,
        private PostBody $body,
        private PostModerationState $moderationState,
        private bool $deleted,
        private ?DateTimeImmutable $deletedAt,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private int $version,
    ) {
        PostId::assert($this->postId);
        ThreadId::assert($this->threadId);
        if ($this->authorUserId !== null) {
            UserId::assert($this->authorUserId);
        }
        if ($this->position < 1 || $this->position > 4294967295) {
            throw new InvalidArgumentException('Post position must be a positive unsigned 32-bit integer.');
        }
        if ($this->version < 0) {
            throw new InvalidArgumentException('Post aggregate version cannot be negative.');
        }
        if ($this->deleted !== ($this->deletedAt !== null)) {
            throw new InvalidArgumentException('Deleted post state and deletion timestamp must agree.');
        }
    }

    public static function create(
        EntityId $postId,
        EntityId $threadId,
        EntityId $authorUserId,
        int $position,
        PostBody $body,
        bool $requiresApproval,
        DateTimeImmutable $now,
    ): self {
        UserId::assert($authorUserId);
        $now = self::utc($now);
        return new self(
            $postId,
            $threadId,
            $authorUserId,
            $position,
            $body,
            $requiresApproval ? PostModerationState::Pending : PostModerationState::Visible,
            false,
            null,
            $now,
            $now,
            0,
        );
    }

    public static function hydrate(
        EntityId $postId,
        EntityId $threadId,
        ?EntityId $authorUserId,
        int $position,
        PostBody $body,
        PostModerationState $moderationState,
        bool $deleted,
        ?DateTimeImmutable $deletedAt,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        int $version,
    ): self {
        if ($version < 1) {
            throw new InvalidArgumentException('Hydrated posts require a persisted aggregate version.');
        }

        return new self(
            $postId,
            $threadId,
            $authorUserId,
            $position,
            $body,
            $moderationState,
            $deleted,
            $deletedAt === null ? null : self::utc($deletedAt),
            self::utc($createdAt),
            self::utc($updatedAt),
            $version,
        );
    }

    public function id(): EntityId
    {
        return $this->postId;
    }

    public function threadId(): EntityId
    {
        return $this->threadId;
    }

    public function authorUserId(): ?EntityId
    {
        return $this->authorUserId;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function isFirstPost(): bool
    {
        return $this->position === 1;
    }

    public function body(): PostBody
    {
        return $this->body;
    }

    public function moderationState(): PostModerationState
    {
        return $this->moderationState;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return list<PostHistoryEntry> */
    public function pendingHistory(): array
    {
        return $this->pendingHistory;
    }

    public function edit(PostBody $body, EntityId $actorUserId, DateTimeImmutable $at): bool
    {
        UserId::assert($actorUserId);
        if ($this->body->source() === $body->source()) {
            return false;
        }
        $this->snapshot('edited', $actorUserId, $at);
        $this->body = $body;
        $this->touch($at);
        return true;
    }

    public function delete(EntityId $actorUserId, DateTimeImmutable $at): bool
    {
        UserId::assert($actorUserId);
        if ($this->deleted) {
            return false;
        }
        $this->snapshot('deleted', $actorUserId, $at);
        $this->deleted = true;
        $this->deletedAt = self::utc($at);
        $this->touch($at);
        return true;
    }

    public function restore(EntityId $actorUserId, DateTimeImmutable $at): bool
    {
        UserId::assert($actorUserId);
        if (!$this->deleted) {
            return false;
        }
        $this->snapshot('restored', $actorUserId, $at);
        $this->deleted = false;
        $this->deletedAt = null;
        $this->touch($at);
        return true;
    }

    public function approve(EntityId $actorUserId, DateTimeImmutable $at): bool
    {
        return $this->changeModeration(PostModerationState::Visible, 'approved', $actorUserId, $at);
    }

    public function reject(EntityId $actorUserId, DateTimeImmutable $at): bool
    {
        return $this->changeModeration(PostModerationState::Rejected, 'rejected', $actorUserId, $at);
    }

    public function requestModeration(EntityId $actorUserId, DateTimeImmutable $at): bool
    {
        return $this->changeModeration(PostModerationState::Pending, 'moderation_requested', $actorUserId, $at);
    }

    public function markPersisted(int $version): void
    {
        if ($version <= $this->version) {
            throw new InvalidArgumentException('Persisted post version must advance.');
        }
        $this->version = $version;
        $this->pendingHistory = [];
    }

    private function changeModeration(
        PostModerationState $state,
        string $action,
        EntityId $actorUserId,
        DateTimeImmutable $at,
    ): bool {
        UserId::assert($actorUserId);
        if ($this->moderationState === $state) {
            return false;
        }
        $this->snapshot($action, $actorUserId, $at);
        $this->moderationState = $state;
        $this->touch($at);
        return true;
    }

    private function snapshot(string $action, EntityId $actorUserId, DateTimeImmutable $at): void
    {
        $at = self::utc($at);
        $this->assertMutationTime($at);
        $this->pendingHistory[] = new PostHistoryEntry(
            $this->version,
            $this->body,
            $this->moderationState,
            $this->deleted,
            $action,
            $actorUserId,
            $at,
        );
    }

    private function touch(DateTimeImmutable $at): void
    {
        $at = self::utc($at);
        $this->assertMutationTime($at);
        $this->updatedAt = $at;
    }

    private function assertMutationTime(DateTimeImmutable $at): void
    {
        if ($at < $this->createdAt || $at < $this->updatedAt) {
            throw new InvalidArgumentException('Post mutation time cannot move backwards.');
        }
    }

    private static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
