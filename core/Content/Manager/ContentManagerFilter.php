<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class ContentManagerFilter
{
    public function __construct(
        public EntityId $targetUserId,
        public ?ContentManagerContentType $contentType = null,
        public ?EntityId $forumNodeId = null,
        public ?string $moderationState = null,
        public ?bool $deleted = null,
        public ?string $query = null,
    ) {
        if ($this->moderationState !== null
            && !in_array($this->moderationState, ['visible', 'pending', 'rejected'], true)
        ) {
            throw new InvalidArgumentException('Content manager moderation state filter is invalid.');
        }

        if ($this->query !== null) {
            $query = trim($this->query);
            if ($query === '' || strlen($query) > 200 || preg_match('//u', $query) !== 1) {
                throw new InvalidArgumentException('Content manager text filter is invalid.');
            }
        }
    }

    public function normalizedQuery(): ?string
    {
        return $this->query === null ? null : trim($this->query);
    }
}
