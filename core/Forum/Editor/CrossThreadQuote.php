<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class CrossThreadQuote
{
    public function __construct(
        public EntityId $postId,
        public EntityId $threadId,
        public string $authorLabel,
        public string $threadTitle,
        public int $postPosition,
        public string $bbCode,
    ) {
    }
}
