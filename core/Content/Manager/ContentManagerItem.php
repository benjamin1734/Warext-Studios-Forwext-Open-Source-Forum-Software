<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ContentManagerItem
{
    public function __construct(
        public ContentManagerContentType $type,
        public EntityId $id,
        public EntityId $targetUserId,
        public EntityId $forumNodeId,
        public ?EntityId $threadId,
        public string $title,
        public string $excerpt,
        public string $moderationState,
        public bool $deleted,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if (!in_array($this->moderationState, ['visible', 'pending', 'rejected'], true)) {
            throw new InvalidArgumentException('Content manager item moderation state is invalid.');
        }
        if (strlen($this->title) > 200 || strlen($this->excerpt) > 500 || preg_match('//u', $this->title . $this->excerpt) !== 1) {
            throw new InvalidArgumentException('Content manager item display text is invalid.');
        }
        if ($this->type === ContentManagerContentType::Post && $this->threadId === null) {
            throw new InvalidArgumentException('Post content manager item requires a thread id.');
        }
    }
}
