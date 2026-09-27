<?php

declare(strict_types=1);

namespace Forwext\Core\Social\Interaction;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class BookmarkEntry
{
    public function __construct(
        public EntityId $postId,
        public ?string $note,
        public ?EntityId $threadId = null,
        public ?string $threadTitle = null,
        public ?int $postPosition = null,
    ) {
    }
}
