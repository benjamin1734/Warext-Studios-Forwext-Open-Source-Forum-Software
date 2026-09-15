<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeId;
use Forwext\Core\Forum\Thread\ThreadId;
use Forwext\Core\Forum\Thread\ThreadModerationState;

final readonly class ModerationThreadRecord
{
    public function __construct(
        public EntityId $threadId,
        public EntityId $forumNodeId,
        public ThreadModerationState $moderationState,
        public bool $locked,
        public bool $sticky,
        public bool $deleted,
        public ?EntityId $mergedIntoThreadId,
    ) {
        ThreadId::assert($this->threadId);
        ForumNodeId::assert($this->forumNodeId);
        if ($this->mergedIntoThreadId !== null) {
            ThreadId::assert($this->mergedIntoThreadId);
        }
    }

    public function isActive(): bool
    {
        return !$this->deleted && $this->mergedIntoThreadId === null;
    }
}
