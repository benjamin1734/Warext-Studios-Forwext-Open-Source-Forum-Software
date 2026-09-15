<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Thread;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\Entity;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\Event\RecordsDomainEvents;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Node\ForumNodeId;
use InvalidArgumentException;

final class Thread implements Entity
{
    use RecordsDomainEvents;

    private function __construct(
        private readonly EntityId $threadId,
        private readonly EntityId $forumNodeId,
        private readonly ?EntityId $authorUserId,
        private readonly ThreadTypeKey $typeKey,
        private ThreadTitle $title,
        private ThreadModerationState $moderationState,
        private bool $locked,
        private bool $sticky,
        private bool $featured,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private int $version,
    ) {
        ThreadId::assert($this->threadId);
        ForumNodeId::assert($this->forumNodeId);
        if ($this->authorUserId !== null) {
            UserId::assert($this->authorUserId);
        }
        if ($this->version < 0) {
            throw new InvalidArgumentException('Thread aggregate version cannot be negative.');
        }
    }

    public static function create(
        EntityId $threadId,
        EntityId $forumNodeId,
        EntityId $authorUserId,
        ThreadTypeKey $typeKey,
        ThreadTitle $title,
        bool $requiresApproval,
        DateTimeImmutable $now,
    ): self {
        UserId::assert($authorUserId);
        $now = self::utc($now);
        $thread = new self(
            $threadId,
            $forumNodeId,
            $authorUserId,
            $typeKey,
            $title,
            $requiresApproval ? ThreadModerationState::Pending : ThreadModerationState::Visible,
            false,
            false,
            false,
            $now,
            $now,
            0,
        );
        $thread->record('thread.created', $now);
        return $thread;
    }

    public static function hydrate(
        EntityId $threadId,
        EntityId $forumNodeId,
        ?EntityId $authorUserId,
        ThreadTypeKey $typeKey,
        ThreadTitle $title,
        ThreadModerationState $moderationState,
        bool $locked,
        bool $sticky,
        bool $featured,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        int $version,
    ): self {
        if ($version < 1) {
            throw new InvalidArgumentException('Hydrated threads require a persisted aggregate version.');
        }

        return new self(
            $threadId,
            $forumNodeId,
            $authorUserId,
            $typeKey,
            $title,
            $moderationState,
            $locked,
            $sticky,
            $featured,
            self::utc($createdAt),
            self::utc($updatedAt),
            $version,
        );
    }

    public function id(): EntityId
    {
        return $this->threadId;
    }

    public function forumNodeId(): EntityId
    {
        return $this->forumNodeId;
    }

    public function authorUserId(): ?EntityId
    {
        return $this->authorUserId;
    }

    public function typeKey(): ThreadTypeKey
    {
        return $this->typeKey;
    }

    public function title(): ThreadTitle
    {
        return $this->title;
    }

    public function moderationState(): ThreadModerationState
    {
        return $this->moderationState;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function isSticky(): bool
    {
        return $this->sticky;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
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

    public function rename(ThreadTitle $title, DateTimeImmutable $at): bool
    {
        if ($this->title->value() === $title->value()) {
            return false;
        }
        $this->title = $title;
        $this->touch($at, 'thread.title_changed');
        return true;
    }

    public function lock(DateTimeImmutable $at): bool
    {
        return $this->setFlag('locked', true, $at, 'thread.locked');
    }

    public function unlock(DateTimeImmutable $at): bool
    {
        return $this->setFlag('locked', false, $at, 'thread.unlocked');
    }

    public function stick(DateTimeImmutable $at): bool
    {
        return $this->setFlag('sticky', true, $at, 'thread.stickied');
    }

    public function unstick(DateTimeImmutable $at): bool
    {
        return $this->setFlag('sticky', false, $at, 'thread.unstickied');
    }

    public function feature(DateTimeImmutable $at): bool
    {
        return $this->setFlag('featured', true, $at, 'thread.featured');
    }

    public function unfeature(DateTimeImmutable $at): bool
    {
        return $this->setFlag('featured', false, $at, 'thread.unfeatured');
    }

    public function requestModeration(DateTimeImmutable $at): bool
    {
        return $this->setModerationState(ThreadModerationState::Pending, $at, 'thread.moderation_requested');
    }

    public function approve(DateTimeImmutable $at): bool
    {
        return $this->setModerationState(ThreadModerationState::Visible, $at, 'thread.approved');
    }

    public function reject(DateTimeImmutable $at): bool
    {
        return $this->setModerationState(ThreadModerationState::Rejected, $at, 'thread.rejected');
    }

    public function markPersisted(int $version): void
    {
        if ($version <= $this->version) {
            throw new InvalidArgumentException('Persisted thread version must advance.');
        }
        $this->version = $version;
    }

    private function setFlag(string $field, bool $value, DateTimeImmutable $at, string $event): bool
    {
        if (!in_array($field, ['locked', 'sticky', 'featured'], true)) {
            throw new InvalidArgumentException('Unsupported thread flag.');
        }
        if ($this->{$field} === $value) {
            return false;
        }
        $this->{$field} = $value;
        $this->touch($at, $event);
        return true;
    }

    private function setModerationState(
        ThreadModerationState $state,
        DateTimeImmutable $at,
        string $event,
    ): bool {
        if ($this->moderationState === $state) {
            return false;
        }
        $this->moderationState = $state;
        $this->touch($at, $event);
        return true;
    }

    private function touch(DateTimeImmutable $at, string $event): void
    {
        $at = self::utc($at);
        if ($at < $this->createdAt) {
            throw new InvalidArgumentException('Thread mutation time cannot precede creation.');
        }
        $this->updatedAt = $at;
        $this->record($event, $at);
    }

    private function record(string $event, DateTimeImmutable $at): void
    {
        $this->recordDomainEvent(new ThreadDomainEvent($event, $this->threadId, self::utc($at)));
    }

    private static function utc(DateTimeImmutable $value): DateTimeImmutable
    {
        return $value->setTimezone(new DateTimeZone('UTC'));
    }
}
