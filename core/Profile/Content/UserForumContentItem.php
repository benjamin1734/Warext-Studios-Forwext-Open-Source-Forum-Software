<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Content;

use DateTimeImmutable;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class UserForumContentItem
{
    public function __construct(
        public UserForumContentType $type,
        public EntityId $contentId,
        public EntityId $threadId,
        public EntityId $forumNodeId,
        public string $forumTitle,
        public string $threadTitle,
        public string $excerpt,
        public ?int $position,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
        if ($this->forumTitle === '' || $this->threadTitle === '') {
            throw new InvalidArgumentException('User forum content titles cannot be empty.');
        }
        if ($this->position !== null && $this->position < 1) {
            throw new InvalidArgumentException('User forum content position must be positive.');
        }
    }
}
