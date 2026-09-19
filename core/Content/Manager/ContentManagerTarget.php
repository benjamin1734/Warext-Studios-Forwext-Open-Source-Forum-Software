<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Manager;

use Forwext\Core\Domain\Entity\EntityId;

final readonly class ContentManagerTarget
{
    public function __construct(
        public ContentManagerContentType $type,
        public EntityId $id,
        public EntityId $forumNodeId,
        public string $moderationState,
        public bool $deleted,
    ) {
    }
}
