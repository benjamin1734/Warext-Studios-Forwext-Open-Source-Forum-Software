<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeId;
use Forwext\Core\Forum\Post\PostId;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Thread\ThreadId;
use InvalidArgumentException;

final readonly class ModerationPostRecord
{
    public function __construct(
        public EntityId $postId,
        public EntityId $threadId,
        public EntityId $forumNodeId,
        public int $position,
        public PostModerationState $moderationState,
        public bool $deleted,
    ) {
        PostId::assert($this->postId);
        ThreadId::assert($this->threadId);
        ForumNodeId::assert($this->forumNodeId);
        if ($this->position < 1) {
            throw new InvalidArgumentException('Moderation post position must be positive.');
        }
    }
}
